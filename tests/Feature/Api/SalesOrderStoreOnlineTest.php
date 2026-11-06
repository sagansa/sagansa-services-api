<?php

namespace Tests\Feature\Api;

use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Menguji POST /sales-orders/online (storeOnline):
 *  - Pembuatan order online biasa tetap jalan (baseline).
 *  - Idempotency: request ulang dengan idempotency_key yang sama
 *    (skenario retry mobile setelah timeout) mengembalikan order
 *    existing — TIDAK membuat baris kedua (akar masalah double order).
 *  - Guard resi duplikat dalam window 31 hari: ditolak 409 dengan
 *    detail order existing; bisa di-override confirm_duplicate_receipt;
 *    resi sama di luar window tetap diterima (provider daur ulang resi).
 */
class SalesOrderStoreOnlineTest extends TestCase
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

    /** Produk apa pun yang valid untuk FK items.*.product_id. */
    private function productId(): int
    {
        return (int) DB::table('products')->value('id');
    }

    private function payload(array $overrides = []): array
    {
        $storeId = Store::factory()->create()->id;
        $this->trackedStoreIds[] = $storeId;

        return array_merge([
            'store_id' => $storeId,
            'delivery_date' => now()->toDateString(),
            'online_shop_provider_id' => 1,
            'delivery_service_id' => 1,
            'receipt_no' => 'SO-' . strtoupper(uniqid()),
            'items' => [
                [
                    'product_id' => $this->productId(),
                    'quantity' => 2,
                    'unit_price' => 5000,
                ],
            ],
        ], $overrides);
    }

    /**
     * Order online existing untuk menguji guard resi. `created_at` eksplisit
     * agar window 31 hari bisa dikontrol.
     */
    private function makeOnlineOrder(string $receiptNo, ?Carbon $createdAt = null): int
    {
        $now = $createdAt ?? Carbon::now();
        $storeId = Store::factory()->create()->id;
        $this->trackedStoreIds[] = $storeId;

        $id = DB::table('sales_orders')->insertGetId([
            'for' => '3',
            'store_id' => $storeId,
            'receipt_no' => $receiptNo,
            'delivery_date' => $now->toDateString(),
            'payment_status' => '2',
            'delivery_status' => '4',
            'shipping_cost' => 0,
            'total_price' => 150000,
            'ordered_by_id' => User::factory()->create()->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->trackedOrderIds[] = $id;

        return $id;
    }

    public function test_create_order_online_sukses_menyimpan_idempotency_key(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $key = 'test-key-' . uniqid();
        // Payload dibuat SEKALI — receipt_no-nya acak per pemanggilan
        // payload(), jadi variabel yang sama wajib dipakai ulang.
        $payload = $this->payload(['idempotency_key' => $key]);

        $res = $this->postJson('/sales-orders/online', $payload);

        $res->assertStatus(201)->assertJsonPath('success', true);
        $this->trackedOrderIds[] = (int) $res->json('data.id');

        $this->assertDatabaseHas('sales_orders', [
            'receipt_no' => $res->json('data.receipt_no'),
            'idempotency_key' => $key,
        ]);
    }

    public function test_retry_dengan_idempotency_key_sama_mengembalikan_order_existing(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $key = 'test-key-' . uniqid();
        $receipt = 'SO-' . strtoupper(uniqid());

        $first = $this->postJson('/sales-orders/online', $this->payload([
            'idempotency_key' => $key,
            'receipt_no' => $receipt,
        ]));
        $first->assertStatus(201);
        $firstId = $first->json('data.id');
        $this->trackedOrderIds[] = (int) $firstId;

        // Retry (mis. mobile timeout lalu user kirim ulang): key sama,
        // payload sama — harus replay, bukan INSERT kedua.
        $second = $this->postJson('/sales-orders/online', $this->payload([
            'idempotency_key' => $key,
            'receipt_no' => $receipt,
        ]));

        $second->assertStatus(200)->assertJsonPath('success', true)
            ->assertJsonPath('idempotent_replay', true)
            ->assertJsonPath('data.id', $firstId);

        $count = DB::table('sales_orders')
            ->where('receipt_no', $receipt)
            ->whereNull('deleted_at')
            ->count();
        $this->assertSame(1, $count, 'Retry tidak boleh membuat baris kedua.');
    }

    public function test_tanpa_idempotency_key_tetap_jalan_app_versi_lama(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $res = $this->postJson('/sales-orders/online', $this->payload());

        $res->assertStatus(201)->assertJsonPath('success', true);
        $this->trackedOrderIds[] = (int) $res->json('data.id');

        $count = DB::table('sales_orders')
            ->where('receipt_no', $res->json('data.receipt_no'))
            ->whereNull('deleted_at')
            ->count();
        $this->assertSame(1, $count);
    }

    public function test_resi_sama_dalam_window_ditolak_409_dengan_detail_order_existing(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $receipt = 'DUP-' . strtoupper(uniqid());
        $existingId = $this->makeOnlineOrder($receipt);

        $res = $this->postJson('/sales-orders/online', $this->payload([
            'receipt_no' => $receipt,
        ]));

        $res->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('duplicate_receipt', true)
            ->assertJsonPath('data.existing_order_id', $existingId)
            ->assertJsonPath('data.receipt_no', $receipt);

        $count = DB::table('sales_orders')
            ->where('receipt_no', $receipt)
            ->whereNull('deleted_at')
            ->count();
        $this->assertSame(1, $count, 'Resi duplikat dalam window tidak boleh masuk.');
    }

    public function test_resi_sama_dalam_window_bisa_di_override_dengan_konfirmasi(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $receipt = 'DUP-' . strtoupper(uniqid());
        $this->makeOnlineOrder($receipt);

        $res = $this->postJson('/sales-orders/online', $this->payload([
            'receipt_no' => $receipt,
            'confirm_duplicate_receipt' => true,
        ]));

        $res->assertStatus(201)->assertJsonPath('success', true);
        $this->trackedOrderIds[] = (int) $res->json('data.id');

        $count = DB::table('sales_orders')
            ->where('receipt_no', $receipt)
            ->whereNull('deleted_at')
            ->count();
        $this->assertSame(2, $count, 'Override konfirmasi harus mengizinkan resi sama dalam window.');
    }

    public function test_resi_sama_di_luar_window_tetap_diterima(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $receipt = 'OLD-' . strtoupper(uniqid());
        $this->makeOnlineOrder($receipt, Carbon::now()->subDays(40));

        $res = $this->postJson('/sales-orders/online', $this->payload([
            'receipt_no' => $receipt,
        ]));

        $res->assertStatus(201)->assertJsonPath('success', true);
        $this->trackedOrderIds[] = (int) $res->json('data.id');

        $count = DB::table('sales_orders')
            ->where('receipt_no', $receipt)
            ->whereNull('deleted_at')
            ->count();
        $this->assertSame(2, $count, 'Resi sama di luar window adalah hal legitimate.');
    }
}
