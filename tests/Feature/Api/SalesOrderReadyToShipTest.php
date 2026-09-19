<?php

namespace Tests\Feature\Api;

use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Menguji POST /sales-orders/ready-to-ship (markReadyToShip):
 *  - delivery_status 1 (belum dikirim) → sukses, status menjadi 4.
 *  - delivery_status 4 (siap dikirim) → sukses idempoten; permintaan
 *    ulang (double-tap / data list stale di client) bukan error.
 *  - delivery_status lain (3 = sudah dikirim) → tetap ditolak 400.
 */
class SalesOrderReadyToShipTest extends TestCase
{
    /** @var array<int> id order online yang dibuat selama test, untuk cleanup. */
    private array $trackedOrderIds = [];

    protected function tearDown(): void
    {
        if (! empty($this->trackedOrderIds)) {
            DB::table('sales_orders')->whereIn('id', $this->trackedOrderIds)->delete();
        }
        parent::tearDown();
    }

    private function makeOnlineOrder(int $deliveryStatus): int
    {
        $id = DB::table('sales_orders')->insertGetId([
            'for' => '3',
            'store_id' => Store::factory()->create()->id,
            'receipt_no' => 'SO-' . strtoupper(uniqid()),
            'delivery_date' => now()->toDateString(),
            'payment_status' => '2',
            'delivery_status' => (string) $deliveryStatus,
            'shipping_cost' => 0,
            'total_price' => 1000,
            'ordered_by_id' => User::factory()->create()->id,
        ]);
        $this->trackedOrderIds[] = $id;

        return $id;
    }

    public function test_order_belum_dikirim_berhasil_ditandai_siap_dikirim(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $orderId = $this->makeOnlineOrder(1);

        $res = $this->postJson('/sales-orders/ready-to-ship', [
            'id' => $orderId,
            'for' => '3',
        ]);

        $res->assertOk()->assertJsonPath('success', true);
        $this->assertDatabaseHas('sales_orders', [
            'id' => $orderId,
            'delivery_status' => 4,
        ]);
    }

    public function test_permintaan_ulang_order_yang_sudah_siap_dikirim_tetap_sukses(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $orderId = $this->makeOnlineOrder(4);

        $res = $this->postJson('/sales-orders/ready-to-ship', [
            'id' => $orderId,
            'for' => '3',
        ]);

        $res->assertOk()->assertJsonPath('success', true);
        $this->assertDatabaseHas('sales_orders', [
            'id' => $orderId,
            'delivery_status' => 4,
        ]);
    }

    public function test_order_sudah_dikirim_tetap_ditolak(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $orderId = $this->makeOnlineOrder(3);

        $res = $this->postJson('/sales-orders/ready-to-ship', [
            'id' => $orderId,
            'for' => '3',
        ]);

        $res->assertStatus(400)->assertJsonPath('success', false);
    }
}
