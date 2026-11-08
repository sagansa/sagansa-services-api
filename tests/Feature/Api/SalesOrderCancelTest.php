<?php

namespace Tests\Feature\Api;

use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Menguji POST /sales-orders/{id}/cancel (cancel):
 *  - Admin dapat membatalkan order online (for=3) berstatus 1/4/5 → 200,
 *    delivery_status jadi 7 dan reason tersimpan di kolom notes.
 *  - Non-admin ditolak 403.
 *  - Order direct (for=1) tidak ditemukan (lookup filter for=3) → 404.
 *  - Status 3 (sudah dikirim) → 400 arahkan ke alur refund.
 *  - Status 2/6/7 (final) → 400.
 *  - reason kosong → 422.
 */
class SalesOrderCancelTest extends TestCase
{
    /** @var array<int> id order yang dibuat selama test, untuk cleanup. */
    private array $trackedOrderIds = [];

    /** @var array<int> id store buatan test, untuk cleanup. */
    private array $trackedStoreIds = [];

    protected function tearDown(): void
    {
        if (! empty($this->trackedOrderIds)) {
            DB::table('detail_sales_orders')
                ->whereIn('sales_order_id', $this->trackedOrderIds)
                ->delete();
            DB::table('sales_orders')->whereIn('id', $this->trackedOrderIds)->delete();
        }

        if (! empty($this->trackedStoreIds)) {
            Store::whereIn('id', $this->trackedStoreIds)->delete();
        }

        parent::tearDown();
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    /** Order online (for=3) dengan delivery_status yang bisa diatur per test. */
    private function makeOnlineOrder(int $deliveryStatus = 4): int
    {
        $storeId = Store::factory()->create()->id;
        $this->trackedStoreIds[] = $storeId;

        $id = DB::table('sales_orders')->insertGetId([
            'for' => '3',
            'store_id' => $storeId,
            'receipt_no' => 'SO-' . strtoupper(uniqid()),
            'delivery_date' => now()->toDateString(),
            'payment_status' => '2',
            'delivery_status' => (string) $deliveryStatus,
            'shipping_cost' => 0,
            'total_price' => 150000,
            'ordered_by_id' => User::factory()->create()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->trackedOrderIds[] = $id;

        return $id;
    }

    public function test_admin_batalkan_order_siap_dikirim_mengubah_status_ke_7(): void
    {
        Sanctum::actingAs($this->userWithRole('admin'));
        $orderId = $this->makeOnlineOrder(4);

        $res = $this->postJson("/sales-orders/{$orderId}/cancel", [
            'reason' => 'Dibatalkan oleh pembeli',
        ]);

        $res->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.delivery_status', 7)
            ->assertJsonPath('data.notes', 'Dibatalkan oleh pembeli');

        $this->assertDatabaseHas('sales_orders', [
            'id' => $orderId,
            'delivery_status' => '7',
            'notes' => 'Dibatalkan oleh pembeli',
        ]);
    }

    public function test_admin_batalkan_order_belum_dikirim(): void
    {
        Sanctum::actingAs($this->userWithRole('admin'));
        $orderId = $this->makeOnlineOrder(1);

        $res = $this->postJson("/sales-orders/{$orderId}/cancel", [
            'reason' => 'Pembeli batal di marketplace',
        ]);

        $res->assertStatus(200)->assertJsonPath('data.delivery_status', 7);
    }

    public function test_admin_batalkan_order_perbaiki(): void
    {
        Sanctum::actingAs($this->userWithRole('admin'));
        $orderId = $this->makeOnlineOrder(5);

        $res = $this->postJson("/sales-orders/{$orderId}/cancel", [
            'reason' => 'Pembeli batal',
        ]);

        $res->assertStatus(200)->assertJsonPath('data.delivery_status', 7);
    }

    public function test_non_admin_ditolak_403(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $orderId = $this->makeOnlineOrder(4);

        $res = $this->postJson("/sales-orders/{$orderId}/cancel", [
            'reason' => 'Dibatalkan oleh pembeli',
        ]);

        $res->assertStatus(403);
    }

    public function test_order_direct_tidak_ditemukan_404(): void
    {
        Sanctum::actingAs($this->userWithRole('admin'));
        // Order direct (for=1) — lookup cancel memfilter for=3.
        $storeId = Store::factory()->create()->id;
        $this->trackedStoreIds[] = $storeId;
        $id = DB::table('sales_orders')->insertGetId([
            'for' => '1',
            'store_id' => $storeId,
            'receipt_no' => 'SO-DIRECT-' . strtoupper(uniqid()),
            'delivery_date' => now()->toDateString(),
            'payment_status' => '2',
            'delivery_status' => '4',
            'shipping_cost' => 0,
            'total_price' => 100000,
            'ordered_by_id' => User::factory()->create()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->trackedOrderIds[] = $id;

        $res = $this->postJson("/sales-orders/{$id}/cancel", [
            'reason' => 'Dibatalkan oleh pembeli',
        ]);

        $res->assertStatus(404);
    }

    public function test_order_sudah_dikirim_diarahkan_ke_refund(): void
    {
        Sanctum::actingAs($this->userWithRole('admin'));
        $orderId = $this->makeOnlineOrder(3);

        $res = $this->postJson("/sales-orders/{$orderId}/cancel", [
            'reason' => 'Dibatalkan oleh pembeli',
        ]);

        $res->assertStatus(400)->assertJsonPath('success', false);
        $this->assertStringContainsString('refund', (string) $res->json('message'));
    }

    public function test_status_final_valid_dikembalikan_tidak_bisa_dibatalkan(): void
    {
        Sanctum::actingAs($this->userWithRole('admin'));

        foreach ([2, 6] as $status) {
            $orderId = $this->makeOnlineOrder($status);

            $res = $this->postJson("/sales-orders/{$orderId}/cancel", [
                'reason' => 'Dibatalkan oleh pembeli',
            ]);

            $res->assertStatus(400);
        }
    }

    public function test_order_sudah_dibatalkan_ditolak_400(): void
    {
        Sanctum::actingAs($this->userWithRole('admin'));
        $orderId = $this->makeOnlineOrder(7);

        $res = $this->postJson("/sales-orders/{$orderId}/cancel", [
            'reason' => 'Dibatalkan oleh pembeli',
        ]);

        $res->assertStatus(400)->assertJsonPath('success', false);
        $this->assertStringContainsString(
            'sudah dibatalkan',
            (string) $res->json('message')
        );
    }

    public function test_reason_kosong_ditolak_422(): void
    {
        Sanctum::actingAs($this->userWithRole('admin'));
        $orderId = $this->makeOnlineOrder(4);

        $res = $this->postJson("/sales-orders/{$orderId}/cancel", [
            'reason' => '',
        ]);

        $res->assertStatus(422);
    }
}
