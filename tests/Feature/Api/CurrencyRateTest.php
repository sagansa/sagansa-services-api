<?php

namespace Tests\Feature\Api;

use App\Models\CurrencyRate;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Kurs referensi antar valuta per tanggal (1 USD = x VND) — terpusat:
 * server me-resolve (manual > publik tanggal itu > publik terdekat >
 * terkini) dan meng-cache hasil referensi publik ke tabel currency_rates.
 */
class CurrencyRateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CurrencyRate::query()->where('base_currency', 'USD')->where('quote_currency', 'VND')->delete();
    }

    protected function tearDown(): void
    {
        CurrencyRate::query()->where('base_currency', 'USD')->where('quote_currency', 'VND')->delete();

        parent::tearDown();
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function fakePublicApis(): void
    {
        Http::fake(function ($request) {
            $url = (string) $request->url();
            if (str_contains($url, 'open.er-api.com')) {
                return Http::response([
                    'result' => 'success',
                    'time_last_update_unix' => now()->startOfDay()->timestamp,
                    'rates' => ['VND' => 26150],
                ], 200);
            }
            // Historis per tanggal: hanya tanggal berikut tersedia.
            if (str_contains($url, '@2026-09-01/')) {
                return Http::response(['date' => '2026-09-01', 'usd' => ['vnd' => 25000]], 200);
            }
            if (str_contains($url, '@2024-03-28/')) {
                return Http::response(['date' => '2024-03-28', 'usd' => ['vnd' => 24758]], 200);
            }

            return Http::response(['date' => '2026-10-22', 'usd' => ['vnd' => 0]], 200);
        });
    }

    public function test_get_menresolve_kurs_per_tanggal_dan_cache_publik(): void
    {
        $this->fakePublicApis();
        Sanctum::actingAs($this->userWithRole('admin'));

        $res = $this->getJson('/currency-rates?base=USD&quote=VND&dates=2026-09-01,2024-02-07');
        $res->assertOk();

        $data = $res->json('data');
        $this->assertEqualsWithDelta(26150.0, $data['latest']['rate'], 0.0001);

        // Tanggal dengan data publik: kurs tanggalnya sendiri.
        $this->assertEqualsWithDelta(25000.0, $data['rates']['2026-09-01']['rate'], 0.0001);
        $this->assertSame('public', $data['rates']['2026-09-01']['source']);
        $this->assertSame('2026-09-01', $data['rates']['2026-09-01']['source_date']);

        // Sebelum awal cakupan publik (28 Mar 2024) → kurs terdekat.
        $this->assertEqualsWithDelta(24758.0, $data['rates']['2024-02-07']['rate'], 0.0001);
        $this->assertSame('public', $data['rates']['2024-02-07']['source']);
        $this->assertSame('2024-03-28', $data['rates']['2024-02-07']['source_date']);

        // Kurs publik ter-cache server — permintaan berikutnya tanpa jaringan.
        $this->assertDatabaseHas('currency_rates', [
            'base_currency' => 'USD',
            'quote_currency' => 'VND',
            'rate_date' => '2026-09-01',
            'source' => 'public',
            'rate' => 25000,
        ]);
    }

    public function test_kurs_manual_menimpa_publik_dan_bisa_dihapus(): void
    {
        $this->fakePublicApis();
        Sanctum::actingAs($this->userWithRole('admin'));

        // Data lama tanpa referensi publik → diisi manual oleh admin.
        $res = $this->postJson('/currency-rates', [
            'base' => 'USD',
            'quote' => 'VND',
            'rate_date' => '2024-02-07',
            'rate' => 24600,
        ]);
        $res->assertOk()->assertJsonPath('data.source', 'manual');

        $resolved = $this->getJson('/currency-rates?base=USD&quote=VND&dates=2024-02-07');
        $resolved->assertOk()
            ->assertJsonPath('data.rates.2024-02-07.source', 'manual');
        $this->assertEqualsWithDelta(24600.0, $resolved->json('data.rates.2024-02-07.rate'), 0.0001);

        // Hapus manual → kembali ke publik terdekat (awal cakupan).
        $del = $this->deleteJson('/currency-rates?base=USD&quote=VND&date=2024-02-07');
        $del->assertOk()->assertJsonPath('data.source', 'public')
            ->assertJsonPath('data.source_date', '2024-03-28');

        // Baris manual benar-benar hilang.
        $this->assertDatabaseMissing('currency_rates', [
            'rate_date' => '2024-02-07',
            'source' => 'manual',
        ]);
    }

    public function test_bukan_admin_ditolak(): void
    {
        Sanctum::actingAs($this->userWithRole('staff'));

        $this->getJson('/currency-rates?base=USD&quote=VND')->assertStatus(403);
        $this->postJson('/currency-rates', [
            'base' => 'USD', 'quote' => 'VND', 'rate_date' => '2024-02-07', 'rate' => 1,
        ])->assertStatus(403);
        $this->deleteJson('/currency-rates?base=USD&quote=VND&date=2024-02-07')->assertStatus(403);
    }

    public function test_publik_tidak_tersedia_jatuh_ke_terkini(): void
    {
        // Semua sumber publik gagal — tidak ada latest, resolved null.
        Http::fake(fn () => Http::response([], 500));
        Sanctum::actingAs($this->userWithRole('admin'));

        $res = $this->getJson('/currency-rates?base=USD&quote=VND&dates=2026-09-01');
        $res->assertOk();
        $this->assertNull($res->json('data.latest'));
        $this->assertSame([], $res->json('data.rates'));
    }
}
