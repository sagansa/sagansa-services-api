<?php

namespace Tests\Feature\Api;

use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Menguji POST /sales-orders/{id}/items (updateItems) + exposure
 * shipping_cost di GET /sales-orders/search:
 *  - Admin dapat mengubah rincian produk dengan ongkos kirim → 200,
 *    total_price = SUM(subtotal item) + shipping_cost.
 *  - Tanpa shipping_cost di payload → nilai lama dipertahankan (bukan 0),
 *    total tetap memperhitungkannya.
 *  - shipping_cost negatif → 422.
 *  - Non-admin → 403.
 *  - Order terkunci (delivery_status 3) → 400.
 *  - shipping_cost ikut tampil di response list/search (mobile butuh ini
 *    untuk menampilkan & mengubah ongkos kirim).
 */
class SalesOrderUpdateItemsTest extends TestCase
{
    /** @var array<int> id order yang dibuat selama test, untuk cleanup. */
    private array $trackedOrderIds = [];

    /** @var array<int> id store buatan test, untuk cleanup. */
    private array $trackedStoreIds = [];

    /** @var array<int> id grup buatan test, untuk cleanup. */
    private array $trackedGroupIds = [];

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

        if (! empty($this->trackedGroupIds)) {
            DB::table('product_online_groups')->whereIn('id', $this->trackedGroupIds)->delete();
        }

        parent::tearDown();
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function productId(): int
    {
        return (int) DB::table('products')->value('id');
    }

    private function groupId(): int
    {
        $id = (int) DB::table('product_online_groups')->insertGetId([
            'name' => 'Paket Group Update Test',
            'slug' => 'paket-group-update-test-'.uniqid(),
            'online_price' => 50000,
            'online_category_id' => 1,
            'unit_id' => 1,
            'is_active' => 1,
            'user_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->trackedGroupIds[] = $id;

        return $id;
    }

    /**
     * Order direct (for=1) dengan items + ongkos kirim yang bisa diatur.
     * Total awal konsisten dengan invarian: total = items + shipping.
     */
    private function makeDirectOrder(int $shippingCost = 0, int $deliveryStatus = 1): int
    {
        $storeId = Store::factory()->create()->id;
        $this->trackedStoreIds[] = $storeId;

        $id = DB::table('sales_orders')->insertGetId([
            'for' => '1',
            'store_id' => $storeId,
            'delivery_date' => now()->toDateString(),
            'payment_status' => '1',
            'delivery_status' => (string) $deliveryStatus,
            'shipping_cost' => $shippingCost,
            'total_price' => 20000 + $shippingCost,
            'ordered_by_id' => User::factory()->create()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->trackedOrderIds[] = $id;

        DB::table('detail_sales_orders')->insert([
            'sales_order_id' => $id,
            'product_id' => $this->productId(),
            'quantity' => 2,
            'unit_price' => 10000,
            'subtotal_price' => 20000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    public function test_admin_update_items_dengan_ongkos_kirim_menghitung_total(): void
    {
        Sanctum::actingAs($this->userWithRole('admin'));
        $orderId = $this->makeDirectOrder();

        $res = $this->postJson("/sales-orders/{$orderId}/items", [
            'items' => [
                ['product_id' => $this->productId(), 'quantity' => 3, 'unit_price' => 10000],
            ],
            'shipping_cost' => 15000,
        ]);

        $res->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.shipping_cost', 15000)
            ->assertJsonPath('data.total_price', 45000);

        $this->assertDatabaseHas('sales_orders', [
            'id' => $orderId,
            'shipping_cost' => '15000',
            'total_price' => '45000',
        ]);
    }

    public function test_tanpa_shipping_cost_nilai_lama_dipertahankan(): void
    {
        Sanctum::actingAs($this->userWithRole('admin'));
        $orderId = $this->makeDirectOrder(shippingCost: 20000);

        $res = $this->postJson("/sales-orders/{$orderId}/items", [
            'items' => [
                ['product_id' => $this->productId(), 'quantity' => 1, 'unit_price' => 30000],
            ],
        ]);

        $res->assertStatus(200)
            ->assertJsonPath('data.shipping_cost', 20000)
            ->assertJsonPath('data.total_price', 50000);
    }

    public function test_shipping_cost_negatif_ditolak_422(): void
    {
        Sanctum::actingAs($this->userWithRole('admin'));
        $orderId = $this->makeDirectOrder();

        $res = $this->postJson("/sales-orders/{$orderId}/items", [
            'items' => [
                ['product_id' => $this->productId(), 'quantity' => 1, 'unit_price' => 5000],
            ],
            'shipping_cost' => -1000,
        ]);

        $res->assertStatus(422);
    }

    public function test_non_admin_ditolak_403(): void
    {
        Sanctum::actingAs($this->userWithRole('sales'));
        $orderId = $this->makeDirectOrder();

        $res = $this->postJson("/sales-orders/{$orderId}/items", [
            'items' => [
                ['product_id' => $this->productId(), 'quantity' => 1, 'unit_price' => 5000],
            ],
            'shipping_cost' => 5000,
        ]);

        $res->assertStatus(403);
    }

    public function test_order_terkunci_ditolak_400(): void
    {
        Sanctum::actingAs($this->userWithRole('admin'));
        $orderId = $this->makeDirectOrder(deliveryStatus: 3);

        $res = $this->postJson("/sales-orders/{$orderId}/items", [
            'items' => [
                ['product_id' => $this->productId(), 'quantity' => 1, 'unit_price' => 5000],
            ],
            'shipping_cost' => 5000,
        ]);

        $res->assertStatus(400);
    }

    public function test_search_menampilkan_shipping_cost(): void
    {
        Sanctum::actingAs($this->userWithRole('admin'));
        $orderId = $this->makeDirectOrder(shippingCost: 25000);

        $res = $this->getJson('/sales-orders/search?for=1&per_page=50');

        $res->assertStatus(200)->assertJsonPath('success', true);

        $row = collect($res->json('data'))->firstWhere('id', $orderId);
        $this->assertNotNull($row, 'Order direct buatan test harus ada di response search.');
        $this->assertSame(25000, (int) $row['shipping_cost']);
    }

    public function test_admin_update_items_campuran_produk_dan_grup(): void
    {
        Sanctum::actingAs($this->userWithRole('admin'));
        $orderId = $this->makeDirectOrder(shippingCost: 15000);
        $gid = $this->groupId();

        $res = $this->postJson("/sales-orders/{$orderId}/items", [
            'items' => [
                ['product_id' => $this->productId(), 'quantity' => 1, 'unit_price' => 10000],
                ['product_online_group_id' => $gid, 'quantity' => 2, 'unit_price' => 50000],
            ],
        ]);

        $res->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.total_price', 125000); // 10k + 100k + ongkir 15k

        $this->assertDatabaseHas('detail_sales_orders', [
            'sales_order_id' => $orderId,
            'product_online_group_id' => $gid,
            'product_id' => null,
            'quantity' => 2,
            'subtotal_price' => 100000,
        ]);

        $names = array_map(
            fn (array $item) => $item['product_name'] ?? '',
            $res->json('data.items')
        );
        $this->assertContains('Paket Group Update Test', $names);
    }

    public function test_item_tanpa_produk_dan_grup_ditolak_422(): void
    {
        Sanctum::actingAs($this->userWithRole('admin'));
        $orderId = $this->makeDirectOrder();

        $res = $this->postJson("/sales-orders/{$orderId}/items", [
            'items' => [
                ['quantity' => 1, 'unit_price' => 5000],
            ],
        ]);

        $res->assertStatus(422);
    }
}
