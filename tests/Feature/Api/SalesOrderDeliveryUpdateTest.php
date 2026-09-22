<?php

namespace Tests\Feature\Api;

use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Menguji POST /sales-orders/delivery-update (updateDelivery):
 *  - order online (for=3) dapat di-update via order_id — bukan hanya
 *    receipt_no — agar mobile menunjuk baris yang persis dilihat user.
 *  - Saat resi duplikat ada di DB (bug sync marketplace-bot yang selalu
 *    INSERT), update via order_id harus mengenai baris yang dikirim,
 *    bukan duplikat lain.
 *  - Fallback lookup by receipt_no (app versi lama) harus deterministik:
 *    pilih duplikat termuda (id terbesar) — baris teratas di list mobile.
 */
class SalesOrderDeliveryUpdateTest extends TestCase
{
    /** @var array<int> id order yang dibuat selama test, untuk cleanup. */
    private array $trackedOrderIds = [];

    protected function tearDown(): void
    {
        if (! empty($this->trackedOrderIds)) {
            DB::table('detail_sales_orders')
                ->whereIn('sales_order_id', $this->trackedOrderIds)
                ->delete();
            DB::table('sales_orders')->whereIn('id', $this->trackedOrderIds)->delete();
        }
        parent::tearDown();
    }

    private function makeOnlineOrder(string $receiptNo, int $deliveryStatus): int
    {
        $id = DB::table('sales_orders')->insertGetId([
            'for' => '3',
            'store_id' => Store::factory()->create()->id,
            'receipt_no' => $receiptNo,
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

    public function test_order_online_dapat_diupdate_via_order_id(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $orderId = $this->makeOnlineOrder('SO-' . strtoupper(uniqid()), 4);

        $res = $this->postJson('/sales-orders/delivery-update', [
            'order_id' => $orderId,
            'delivery_status' => 3,
            'received_by' => ' Tester ',
            'image_delivery' => ['images/Delivery/test.jpg'],
        ]);

        $res->assertOk()->assertJsonPath('success', true)
            ->assertJsonPath('data.order_id', $orderId)
            ->assertJsonPath('data.delivery_status', 3);
        $this->assertDatabaseHas('sales_orders', [
            'id' => $orderId,
            'delivery_status' => 3,
            'received_by' => 'Tester',
        ]);
    }

    public function test_resi_duplikat_update_via_order_id_mengenai_baris_yang_dimaksud(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $receiptNo = 'DUP-' . strtoupper(uniqid());
        $olderId = $this->makeOnlineOrder($receiptNo, 4);
        $newerId = $this->makeOnlineOrder($receiptNo, 4);

        $res = $this->postJson('/sales-orders/delivery-update', [
            'order_id' => $newerId,
            'receipt_no' => $receiptNo,
            'delivery_status' => 3,
            'received_by' => 'Tester',
            'image_delivery' => ['images/Delivery/test.jpg'],
        ]);

        $res->assertOk()->assertJsonPath('success', true);
        $this->assertDatabaseHas('sales_orders', [
            'id' => $newerId,
            'delivery_status' => 3,
        ]);
        // Duplikat lain tidak tersentuh.
        $this->assertDatabaseHas('sales_orders', [
            'id' => $olderId,
            'delivery_status' => 4,
        ]);
    }

    public function test_fallback_resi_duplikat_memilih_baris_termuda(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $receiptNo = 'DUP-' . strtoupper(uniqid());
        $olderId = $this->makeOnlineOrder($receiptNo, 4);
        $newerId = $this->makeOnlineOrder($receiptNo, 4);

        // App versi lama hanya mengirim receipt_no.
        $res = $this->postJson('/sales-orders/delivery-update', [
            'receipt_no' => $receiptNo,
            'delivery_status' => 3,
            'received_by' => 'Tester',
            'image_delivery' => ['images/Delivery/test.jpg'],
        ]);

        $res->assertOk()->assertJsonPath('success', true)
            ->assertJsonPath('data.order_id', $newerId);
        $this->assertDatabaseHas('sales_orders', [
            'id' => $newerId,
            'delivery_status' => 3,
        ]);
        $this->assertDatabaseHas('sales_orders', [
            'id' => $olderId,
            'delivery_status' => 4,
        ]);
    }

    public function test_order_online_tanpa_foto_bukti_ditolak(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $orderId = $this->makeOnlineOrder('SO-' . strtoupper(uniqid()), 4);

        $res = $this->postJson('/sales-orders/delivery-update', [
            'order_id' => $orderId,
            'delivery_status' => 3,
            'received_by' => 'Tester',
        ]);

        $res->assertStatus(400)->assertJsonPath('success', false);
        $this->assertDatabaseHas('sales_orders', [
            'id' => $orderId,
            'delivery_status' => 4,
        ]);
    }

    public function test_order_terkunci_tidak_dapat_diubah(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $orderId = $this->makeOnlineOrder('SO-' . strtoupper(uniqid()), 6);

        $res = $this->postJson('/sales-orders/delivery-update', [
            'order_id' => $orderId,
            'delivery_status' => 3,
            'image_delivery' => ['images/Delivery/test.jpg'],
        ]);

        $res->assertStatus(400)->assertJsonPath('success', false);
        $this->assertDatabaseHas('sales_orders', [
            'id' => $orderId,
            'delivery_status' => 6,
        ]);
    }
}
