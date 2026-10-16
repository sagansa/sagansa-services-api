<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Gate `canSell` SalesOrderEmployeeController — `sales + former-employee`
 * (sales eksternal) kini diizinkan berjualan dengan full paritas
 * sales aktif (spec 2026-10-16). Test fokus di gate; happy-path create
 * tidak berubah di fitur ini.
 */
class SalesOrderEmployeeFormerEmployeeAccessTest extends TestCase
{
    /** User factory ids — dihapus best-effort di tearDown. */
    private array $createdUserIds = [];

    private function userWithRoles(array $roles): User
    {
        foreach ($roles as $role) {
            \Spatie\Permission\Models\Role::firstOrCreate(['name' => $role]);
        }
        $user = User::factory()->create();
        foreach ($roles as $role) {
            $user->assignRole($role);
        }
        $this->createdUserIds[] = $user->id;
        return $user;
    }

    protected function tearDown(): void
    {
        if ($this->createdUserIds) {
            User::whereIn('id', $this->createdUserIds)->delete();
        }
        parent::tearDown();
    }

    public function test_index_allows_sales_with_former_employee(): void
    {
        // Test DB suite tidak di-refresh antar run dan skema bisa kurang
        // lengkap (lihat catatan PaymentReceiptDailySalaryTest) — index
        // mem-query sales_orders langsung, jadi skip bila tabel tak ada.
        // Gate tetap ter-cover oleh test store (422) & staff (403).
        if (!\Illuminate\Support\Facades\Schema::hasTable('sales_orders')) {
            $this->markTestSkipped('Tabel sales_orders tidak ada di test DB.');
        }

        Sanctum::actingAs($this->userWithRoles(['sales', 'former-employee']));

        $res = $this->getJson('/sales-orders/employee');

        $res->assertOk()->assertJsonPath('success', true);
    }

    public function test_store_passes_gate_for_sales_with_former_employee(): void
    {
        Sanctum::actingAs($this->userWithRoles(['sales', 'former-employee']));

        // Payload kosong: gate harus LOLOS (bukan 403) lalu gagal di
        // validasi 422 — membuktikan mantan pegawai melewati gate.
        $res = $this->postJson('/sales-orders/employee', []);

        $res->assertStatus(422);
    }

    public function test_index_still_rejects_non_sales(): void
    {
        Sanctum::actingAs($this->userWithRoles(['staff']));

        $res = $this->getJson('/sales-orders/employee');

        $res->assertStatus(403);
    }
}
