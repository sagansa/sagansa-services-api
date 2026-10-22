<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CurrencyRateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Kurs referensi antar valuta per tanggal (mis. 1 USD = x VND) untuk
 * analisa trend harga kontrak impor — tersimpan terpusat agar konsisten
 * di semua perangkat. Baca: resolved (manual > publik tanggal itu >
 * publik terdekat > terkini); tulis: kurs manual oleh admin/super_admin.
 */
class CurrencyRateController extends Controller
{
    public function __construct(private readonly CurrencyRateService $rates)
    {
    }

    private function isAdmin(Request $request): bool
    {
        $user = $request->user();

        return $user && ($user->hasRole('admin') || $user->hasRole('super_admin'));
    }

    private function forbidden(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Hanya admin/super_admin yang dapat melakukan aksi ini.',
        ], 403);
    }

    /**
     * GET /currency-rates?base=USD&quote=VND&dates=2024-02-07,2026-09-01
     * → resolved per tanggal + kurs terkini. Tanggal tanpa cache diambil
     * dari referensi publik oleh server (di-cache ke tabel).
     */
    public function index(Request $request): JsonResponse
    {
        if (! $this->isAdmin($request)) {
            return $this->forbidden();
        }

        $validated = $request->validate([
            'base' => ['required', 'string', 'size:3'],
            'quote' => ['required', 'string', 'size:3'],
            'dates' => ['nullable', 'string'],
        ]);

        $dates = array_filter(explode(',', (string) ($validated['dates'] ?? '')));
        $data = $this->rates->resolveForDates(
            $validated['base'],
            $validated['quote'],
            array_values($dates),
        );

        return response()->json(['success' => true, 'data' => $data]);
    }

    /**
     * POST /currency-rates {base, quote, rate_date, rate} — upsert kurs
     * manual satu tanggal (menimpa referensi publik).
     */
    public function store(Request $request): JsonResponse
    {
        if (! $this->isAdmin($request)) {
            return $this->forbidden();
        }

        $validated = $request->validate([
            'base' => ['required', 'string', 'size:3'],
            'quote' => ['required', 'string', 'size:3'],
            'rate_date' => ['required', 'date', 'before_or_equal:today'],
            'rate' => ['required', 'numeric', 'gt:0'],
        ]);

        $entry = $this->rates->upsertManualRate(
            $validated['base'],
            $validated['quote'],
            $validated['rate_date'],
            (float) $validated['rate'],
            $request->user()->id,
        );

        return response()->json([
            'success' => true,
            'message' => 'Kurs manual tersimpan.',
            'data' => $entry,
        ]);
    }

    /**
     * DELETE /currency-rates?base=USD&quote=VND&date=2024-02-07 — hapus
     * kurs manual tanggal itu (kembali ke referensi publik).
     */
    public function destroy(Request $request): JsonResponse
    {
        if (! $this->isAdmin($request)) {
            return $this->forbidden();
        }

        $validated = $request->validate([
            'base' => ['required', 'string', 'size:3'],
            'quote' => ['required', 'string', 'size:3'],
            'date' => ['required', 'date'],
        ]);

        $deleted = $this->rates->deleteManualRate(
            $validated['base'],
            $validated['quote'],
            $validated['date'],
        );

        if (! $deleted) {
            return response()->json([
                'success' => false,
                'message' => 'Kurs manual untuk tanggal itu tidak ditemukan.',
            ], 404);
        }

        // Resolved setelah hapus — publik/terkini kembali berlaku.
        $data = $this->rates->resolveForDates(
            $validated['base'],
            $validated['quote'],
            [$validated['date']],
        );

        return response()->json([
            'success' => true,
            'message' => 'Kurs manual dihapus — kembali memakai referensi publik.',
            'data' => $data['rates'][$validated['date']] ?? null,
        ]);
    }
}
