<?php

namespace App\Services;

use App\Models\CurrencyRate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Resolusi kurs antar valuta per tanggal, tersimpan terpusat di tabel
 * currency_rates.
 *
 * Urutan resolusi untuk satu tanggal (kecocokan logika klien trend harga
 * kontrak impor): manual (input admin) → publik tanggal itu → publik
 * tanggal terdekat yang tersedia (tanggal lebih lama dari awal cakupan
 * data publik memakai kurs awal cakupan) → kurs publik terkini.
 *
 * Referensi publik (tanpa API key, di-cache server di tabel — satu sumber
 * untuk semua perangkat):
 * - terkini: open.er-api.com/v6/latest/{base}
 * - historis per tanggal: cdn.jsdelivr.net npm/@fawazahmed0/currency-api
 *   @{Y-m-d} (cakupan mulai 2024-03-28)
 */
class CurrencyRateService
{
    /** Kurs historis publik (currency-api) baru tersedia mulai tanggal ini. */
    public const EARLIEST_PUBLIC_DATE = '2024-03-28';

    /** Batas tanggal per permintaan — menjaga latency GET tetap wajar. */
    public const MAX_DATES_PER_REQUEST = 40;

    /**
     * Resolusi kurs untuk daftar tanggal + kurs terkini pasangan.
     *
     * @param list<string> $dates tanggal Y-m-d yang dibutuhkan klien.
     * @return array{
     *   base: string, quote: string,
     *   latest: array{rate: float, date: string, source: string}|null,
     *   rates: array<string, array{rate: float, source: string, source_date: string}>
     * }
     */
    public function resolveForDates(string $base, string $quote, array $dates): array
    {
        $base = strtoupper($base);
        $quote = strtoupper($quote);

        $dates = collect($dates)
            ->filter(fn ($d) => is_string($d) && strtotime((string) $d) !== false)
            ->map(fn ($d) => CarbonImmutable::parse((string) $d)->toDateString())
            ->unique()
            ->values()
            ->take(self::MAX_DATES_PER_REQUEST)
            ->all();

        $latest = $this->ensureLatestRate($base, $quote);
        $latestEntry = $latest === null ? null : [
            'rate' => (float) $latest['rate'],
            'date' => (string) $latest['date'],
            'source' => 'public',
        ];

        $missing = array_values(array_filter(
            $dates,
            fn (string $date) => ! $this->hasCachedRow($base, $quote, $date),
        ));
        // Tanggal pra-cakupan publik di-resolve dari baris awal cakupan —
        // pastikan baris itu ikut terambil.
        if (in_array(true, array_map(fn (string $d) => $d < self::EARLIEST_PUBLIC_DATE, $dates), true)
            && ! $this->hasCachedRow($base, $quote, self::EARLIEST_PUBLIC_DATE)) {
            $missing[] = self::EARLIEST_PUBLIC_DATE;
        }
        foreach (array_unique($missing) as $date) {
            $this->fetchAndCacheHistorical($base, $quote, $date);
        }

        $rates = [];
        foreach ($dates as $date) {
            $entry = $this->resolveDate($base, $quote, $date, $latestEntry);
            if ($entry !== null) {
                $rates[$date] = $entry;
            }
        }

        return [
            'base' => $base,
            'quote' => $quote,
            'latest' => $latestEntry,
            'rates' => $rates,
        ];
    }

    /**
     * Upsert kurs manual satu tanggal (input admin) — menimpa referensi
     * publik untuk tanggal itu.
     */
    public function upsertManualRate(string $base, string $quote, string $date, float $rate, ?int $userId): array
    {
        $base = strtoupper($base);
        $quote = strtoupper($quote);
        $date = CarbonImmutable::parse($date)->toDateString();

        CurrencyRate::updateOrCreate(
            [
                'base_currency' => $base,
                'quote_currency' => $quote,
                'rate_date' => $date,
            ],
            [
                'rate' => $rate,
                'source' => 'manual',
                'created_by_id' => $userId,
            ],
        );

        return [
            'rate' => $rate,
            'source' => 'manual',
            'source_date' => $date,
        ];
    }

    /**
     * Hapus kurs manual satu tanggal — kembali memakai referensi publik.
     * Baris publik tidak pernah dihapus lewat sini.
     */
    public function deleteManualRate(string $base, string $quote, string $date): bool
    {
        $deleted = CurrencyRate::where('base_currency', strtoupper($base))
            ->where('quote_currency', strtoupper($quote))
            ->where('rate_date', CarbonImmutable::parse($date)->toDateString())
            ->where('source', 'manual')
            ->delete();

        return $deleted > 0;
    }

    /* ------------------------------------------------------------- RESOLVE */

    /**
     * Ada baris cache untuk tanggal itu (public atau manual, termasuk
     * penanda rate=0 "tidak tersedia").
     */
    private function hasCachedRow(string $base, string $quote, string $date): bool
    {
        return CurrencyRate::where('base_currency', $base)
            ->where('quote_currency', $quote)
            ->where('rate_date', $date)
            ->exists();
    }

    /**
     * @param array{rate: float, date: string, source: string}|null $latest
     * @return array{rate: float, source: string, source_date: string}|null
     */
    private function resolveDate(string $base, string $quote, string $date, ?array $latest)
    {
        // Manual (input admin) selalu menang untuk tanggalnya.
        $row = CurrencyRate::where('base_currency', $base)
            ->where('quote_currency', $quote)
            ->where('rate_date', $date)
            ->orderByDesc('source') // 'manual' > 'public' secara alfabetis.
            ->first();
        if ($row && $row->source === 'manual' && $row->rate > 0) {
            return [
                'rate' => (float) $row->rate,
                'source' => 'manual',
                'source_date' => (string) $row->rate_date->toDateString(),
            ];
        }

        // Publik tanggal itu (baris rate=0 = dicek & tidak tersedia).
        if ($row && $row->source === 'public' && $row->rate > 0) {
            return [
                'rate' => (float) $row->rate,
                'source' => 'public',
                'source_date' => (string) $row->rate_date->toDateString(),
            ];
        }

        // Tanggal lebih lama dari awal cakupan publik → kurs tanggal
        // terdekat yang tersedia (awal cakupan), bukan kurs terkini.
        if ($date < self::EARLIEST_PUBLIC_DATE) {
            $nearest = CurrencyRate::where('base_currency', $base)
                ->where('quote_currency', $quote)
                ->where('source', 'public')
                ->where('rate', '>', 0)
                ->whereDate('rate_date', self::EARLIEST_PUBLIC_DATE)
                ->first();
            if ($nearest) {
                return [
                    'rate' => (float) $nearest->rate,
                    'source' => 'public',
                    'source_date' => (string) $nearest->rate_date->toDateString(),
                ];
            }
        }

        // Terakhir: kurs terkini (tanggal tanpa data publik).
        if ($latest !== null) {
            return [
                'rate' => $latest['rate'],
                'source' => 'latest',
                'source_date' => $latest['date'],
            ];
        }

        return null;
    }

    /* ------------------------------------------------------- PUBLIC FETCH */

    /**
     * Pastikan kurs terkini pasangan ada dan masih hari ini; ambil dari
     * referensi publik bila belum/ basi.
     *
     * @return array{rate: float, date: string}|null
     */
    private function ensureLatestRate(string $base, string $quote): ?array
    {
        $row = CurrencyRate::where('base_currency', $base)
            ->where('quote_currency', $quote)
            ->where('source', 'public')
            ->where('rate', '>', 0)
            ->orderByDesc('rate_date')
            ->first();

        $today = now()->toDateString();
        if ($row && $row->rate_date->toDateString() === $today) {
            return ['rate' => (float) $row->rate, 'date' => $today];
        }

        $fetched = $this->fetchPublicLatest($base, $quote);
        if ($fetched === null) {
            // Gagal ambil — cache lama (bila ada) tetap dipakai.
            return $row === null ? null : [
                'rate' => (float) $row->rate,
                'date' => (string) $row->rate_date->toDateString(),
            ];
        }

        return $fetched;
    }

    /**
     * @return array{rate: float, date: string}|null
     */
    private function fetchPublicLatest(string $base, string $quote): ?array
    {
        // Utama: open.er-api.com — diperbarui harian.
        try {
            $res = Http::timeout(10)
                ->get("https://open.er-api.com/v6/latest/{$base}");
            if ($res->successful()) {
                $rate = $res->json("rates.{$quote}");
                $updated = $res->json('time_last_update_unix');
                if (is_numeric($rate) && $rate > 0) {
                    $date = is_numeric($updated)
                        ? CarbonImmutable::createFromTimestamp((int) $updated)->toDateString()
                        : now()->toDateString();
                    $this->storePublic($base, $quote, $date, (float) $rate);

                    return ['rate' => (float) $rate, 'date' => $date];
                }
            }
        } catch (\Throwable $e) {
            Log::warning("currency-rate latest {$base}/{$quote} gagal: {$e->getMessage()}");
        }

        // Cadangan: currency-api via CDN jsDelivr (@latest = tanggal hari ini).
        try {
            $res = Http::timeout(10)
                ->get('https://cdn.jsdelivr.net/npm/@fawazahmed0/currency-api@latest/v1/currencies/'.strtolower($base).'.json');
            if ($res->successful()) {
                $rate = $res->json(strtolower($base).'.'.strtolower($quote));
                $date = $res->json('date');
                if (is_numeric($rate) && $rate > 0) {
                    $date = is_string($date) && strtotime($date) !== false
                        ? CarbonImmutable::parse($date)->toDateString()
                        : now()->toDateString();
                    $this->storePublic($base, $quote, $date, (float) $rate);

                    return ['rate' => (float) $rate, 'date' => $date];
                }
            }
        } catch (\Throwable $e) {
            Log::warning("currency-rate latest (cdn) {$base}/{$quote} gagal: {$e->getMessage()}");
        }

        return null;
    }

    /**
     * Ambil kurs historis satu tanggal dari CDN jsDelivr (immutable →
     * cache seumur hidup). Tanggal tanpa data dicoba mundur sampai 3
     * hari; bila tetap kosong disimpan rate=0 sebagai penanda agar tidak
     * diambil ulang setiap permintaan.
     */
    private function fetchAndCacheHistorical(string $base, string $quote, string $date): void
    {
        $cursor = CarbonImmutable::parse($date);
        for ($back = 0; $back < 4; $back++) {
            $tryDate = $cursor->subDays($back)->toDateString();
            if ($tryDate < self::EARLIEST_PUBLIC_DATE) {
                break;
            }
            try {
                $res = Http::timeout(10)->get(
                    'https://cdn.jsdelivr.net/npm/@fawazahmed0/currency-api'
                    ."@{$tryDate}/v1/currencies/".strtolower($base).'.json'
                );
                if ($res->successful()) {
                    $rate = $res->json(strtolower($base).'.'.strtolower($quote));
                    if (is_numeric($rate) && $rate > 0) {
                        // Simpan di tanggal asli yang diminta — resolusi
                        // berikutnya langsung cache hit.
                        $this->storePublic($base, $quote, $date, (float) $rate);

                        return;
                    }
                }
            } catch (\Throwable $e) {
                Log::warning("currency-rate hist {$base}/{$quote} @{$tryDate} gagal: {$e->getMessage()}");
            }
        }

        // Penanda "pernah dicek, tidak tersedia" (rate = 0).
        $this->storePublic($base, $quote, $date, 0);
    }

    private function storePublic(string $base, string $quote, string $date, float $rate): void
    {
        CurrencyRate::updateOrCreate(
            [
                'base_currency' => $base,
                'quote_currency' => $quote,
                'rate_date' => $date,
                'source' => 'public',
            ],
            ['rate' => $rate],
        );
    }
}
