<?php

namespace Tests\Feature\Api;

use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Menguji exposure item di GET /sales-orders/search untuk order direct
 * hasil checkout pelanggan (apps/order): baris detail_sales_orders boleh
 * merujuk product_online_groups (product_id NULL), bukan products.
 *
 * enrichOrder() wajib mengikutkan baris tersebut — lengkap dengan nama
 * produk ATAU nama grup — karena mobile menampilkan "Daftar Barang" dari
 * field `items`. INNER JOIN products yang membuang baris grup membuat
 * "setiap order masuk tampil tanpa produk padahal totalnya ada".
 */
class SalesOrderSearchGroupItemsTest extends TestCase
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

    private function groupId(): int
    {
        $id = (int) DB::table('product_online_groups')->insertGetId([
            'name' => 'Paket Group Test',
            'slug' => 'paket-group-test-'.uniqid(),
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
     * Order direct gaya checkout pelanggan: satu baris produk biasa +
     * satu baris grup produk (product_id NULL), total = 20000 + 50000.
     */
    private function makeCheckoutStyleOrder(): int
    {
        $storeId = Store::factory()->create()->id;
        $this->trackedStoreIds[] = $storeId;

        $id = DB::table('sales_orders')->insertGetId([
            'for' => '1',
            'store_id' => $storeId,
            'delivery_date' => now()->toDateString(),
            'payment_status' => '1',
            'delivery_status' => '1',
            'shipping_cost' => 10000,
            'total_price' => 80000,
            'ordered_by_id' => User::factory()->create()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->trackedOrderIds[] = $id;

        $productId = (int) DB::table('products')->value('id');

        DB::table('detail_sales_orders')->insert([
            [
                'sales_order_id' => $id,
                'product_id' => $productId,
                'product_online_group_id' => null,
                'quantity' => 2,
                'unit_price' => 10000,
                'subtotal_price' => 20000,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'sales_order_id' => $id,
                'product_id' => null,
                'product_online_group_id' => $this->groupId(),
                'quantity' => 1,
                'unit_price' => 50000,
                'subtotal_price' => 50000,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        return $id;
    }

    public function test_search_order_direct_ikutkan_item_grup_produk(): void
    {
        Sanctum::actingAs($this->userWithRole('admin'));
        $orderId = $this->makeCheckoutStyleOrder();

        $res = $this->getJson('/sales-orders/search?for=1&per_page=50');

        $res->assertStatus(200)->assertJsonPath('success', true);

        $order = collect($res->json('data'))->firstWhere('id', $orderId);
        $this->assertNotNull($order, 'Order direct hasil checkout harus ada di list.');

        $this->assertCount(
            2,
            $order['items'],
            'Order dengan 1 item produk + 1 item grup harus mengembalikan 2 items.'
        );

        $names = array_map(
            fn (array $item) => $item['product_name'] ?? '',
            $order['items']
        );
        $this->assertContains('Paket Group Test', $names, 'Item grup harus membawa nama grup-nya.');

        $this->assertSame('10000', (string) $order['shipping_cost'], 'Ongkos kirim harus ikut direspons.');
    }
}
