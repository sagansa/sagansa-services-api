<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SalesDashboardTest extends TestCase
{
    private function adminOrSkip(): User
    {
        try {
            $user = User::factory()->create();
            $user->assignRole('admin');
            return $user;
        } catch (\Spatie\Permission\Exceptions\RoleDoesNotExist $e) {
            $this->markTestSkipped("Role 'admin' not seeded in test DB — pre-existing test env limitation");
        }
    }

    public function test_admin_can_access(): void
    {
        Sanctum::actingAs($this->adminOrSkip());

        $res = $this->getJson('/sales-dashboard');

        $res->assertOk()->assertJson(['success' => true]);
    }

    public function test_super_admin_can_access(): void
    {
        try {
            $user = User::factory()->create();
            $user->assignRole('super_admin');
        } catch (\Spatie\Permission\Exceptions\RoleDoesNotExist $e) {
            $this->markTestSkipped("Role 'super_admin' not seeded in test DB");
        }
        /** @var User $user */
        Sanctum::actingAs($user);

        $res = $this->getJson('/sales-dashboard');

        $res->assertOk()->assertJson(['success' => true]);
    }

    public function test_unauthenticated_returns_401(): void
    {
        $res = $this->getJson('/sales-dashboard');

        $res->assertUnauthorized();
    }

    public function test_staff_returns_403(): void
    {
        try {
            $user = User::factory()->create();
            $user->assignRole('staff');
        } catch (\Spatie\Permission\Exceptions\RoleDoesNotExist $e) {
            $this->markTestSkipped("Role 'staff' not seeded in test DB");
        }
        /** @var User $user */
        Sanctum::actingAs($user);

        $res = $this->getJson('/sales-dashboard');

        $res->assertForbidden();
    }

    public function test_default_periode_is_today_and_view_is_summary(): void
    {
        Sanctum::actingAs($this->adminOrSkip());

        $res = $this->getJson('/sales-dashboard');

        $res->assertOk()
            ->assertJsonPath('data.periode', 'today')
            ->assertJsonPath('data.view', 'summary');
    }

    public function test_invalid_periode_returns_422(): void
    {
        Sanctum::actingAs($this->adminOrSkip());

        $res = $this->getJson('/sales-dashboard?periode=foo');

        $res->assertStatus(422);
    }

    public function test_invalid_view_returns_422(): void
    {
        Sanctum::actingAs($this->adminOrSkip());

        $res = $this->getJson('/sales-dashboard?view=foo');

        $res->assertStatus(422);
    }

    public function test_invalid_sort_returns_422(): void
    {
        Sanctum::actingAs($this->adminOrSkip());

        $res = $this->getJson('/sales-dashboard?view=products&sort=foo');

        $res->assertStatus(422);
    }

    public function test_per_page_clamped_to_200(): void
    {
        Sanctum::actingAs($this->adminOrSkip());

        $res = $this->getJson('/sales-dashboard?view=products&per_page=9999');

        $res->assertOk()->assertJsonPath('data.meta.per_page', 200);
    }

    public function test_accepts_all_four_periodes(): void
    {
        Sanctum::actingAs($this->adminOrSkip());

        foreach (['today', 'yesterday', 'month', 'year'] as $p) {
            $res = $this->getJson("/sales-dashboard?periode={$p}");
            $res->assertOk()->assertJsonPath('data.periode', $p);
        }
    }

    public function test_summary_aggregates_three_kpis_correctly(): void
    {
        $admin = $this->adminOrSkip();
        $store = \App\Models\Store::first();
        if (!$store) {
            $this->markTestSkipped('Need at least 1 store');
        }

        $today = now()->format('Y-m-d');
        foreach ([['total' => 100000, 'qty' => 5], ['total' => 50000, 'qty' => 2]] as $so) {
            $soId = \DB::table('sales_orders')->insertGetId([
                'for' => '3', 'delivery_date' => $today, 'store_id' => $store->id,
                'delivery_status' => 3, 'payment_status' => 1, 'total_price' => $so['total'],
                'ordered_by_id' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
            \DB::table('detail_sales_orders')->insert([
                'product_id' => 1, 'quantity' => $so['qty'], 'unit_price' => 1000,
                'subtotal_price' => 1000 * $so['qty'], 'sales_order_id' => $soId,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        Sanctum::actingAs($admin);
        $res = $this->getJson('/sales-dashboard?periode=today&view=summary');

        $res->assertOk()
            ->assertJsonPath('data.omzet', 150000)
            ->assertJsonPath('data.order_count', 2)
            ->assertJsonPath('data.total_qty', 7);
    }

    public function test_summary_excludes_non_delivered_orders(): void
    {
        $admin = $this->adminOrSkip();
        $store = \App\Models\Store::first();
        if (!$store) {
            $this->markTestSkipped('Need at least 1 store');
        }

        $today = now()->format('Y-m-d');
        \DB::table('sales_orders')->insert([
            'for' => '3', 'delivery_date' => $today, 'store_id' => $store->id,
            'delivery_status' => 1, 'payment_status' => 1, 'total_price' => 999999,
            'ordered_by_id' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        Sanctum::actingAs($admin);
        $res = $this->getJson('/sales-dashboard?periode=today&view=summary');

        $res->assertOk();
        $this->assertNotEquals(999999, $res->json('data.omzet'));
    }

    public function test_trend_returns_points_with_interval(): void
    {
        $admin = $this->adminOrSkip();

        Sanctum::actingAs($admin);
        $res = $this->getJson('/sales-dashboard?periode=month&view=trend');

        $res->assertOk()
            ->assertJsonPath('data.interval', 'day')
            ->assertJsonStructure(['data' => ['view', 'periode', 'interval', 'points']]);

        $points = $res->json('data.points');
        $this->assertIsArray($points);
        $this->assertGreaterThanOrEqual(1, count($points));
        if (!empty($points)) {
            $this->assertArrayHasKey('label', $points[0]);
            $this->assertArrayHasKey('omzet', $points[0]);
        }
    }

    public function test_trend_year_has_twelve_points(): void
    {
        $admin = $this->adminOrSkip();

        Sanctum::actingAs($admin);
        $res = $this->getJson('/sales-dashboard?periode=year&view=trend');

        $res->assertOk()->assertJsonPath('data.interval', 'month');
        $this->assertCount(12, $res->json('data.points'));
    }

    public function test_trend_today_yesterday_have_24_hour_points(): void
    {
        $admin = $this->adminOrSkip();

        Sanctum::actingAs($admin);
        foreach (['today', 'yesterday'] as $p) {
            $res = $this->getJson("/sales-dashboard?periode={$p}&view=trend");
            $res->assertOk()->assertJsonPath('data.interval', 'hour');
            $this->assertCount(24, $res->json('data.points'), "Periode {$p} harus 24 titik jam");
        }
    }

    public function test_products_view_returns_items_with_qty_and_revenue(): void
    {
        $admin = $this->adminOrSkip();
        $productId = \DB::table('products')->value('id');
        if ($productId === null) {
            $this->markTestSkipped('No products in DB');
        }
        $store = \App\Models\Store::first();
        if (!$store) {
            $this->markTestSkipped('Need at least 1 store');
        }

        $today = now()->format('Y-m-d');
        $soId = \DB::table('sales_orders')->insertGetId([
            'for' => '3', 'delivery_date' => $today, 'store_id' => $store->id,
            'delivery_status' => 3, 'payment_status' => 1, 'total_price' => 5000,
            'ordered_by_id' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        \DB::table('detail_sales_orders')->insert([
            'product_id' => $productId, 'quantity' => 5, 'unit_price' => 1000,
            'subtotal_price' => 5000, 'sales_order_id' => $soId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        Sanctum::actingAs($admin);
        $res = $this->getJson('/sales-dashboard?periode=today&view=products&sort=qty');

        $res->assertOk()
            ->assertJsonStructure(['data' => ['items', 'meta', 'category_summary']]);

        $item = collect($res->json('data.items'))->firstWhere('product_id', $productId);
        $this->assertNotNull($item);
        $this->assertEquals(5, $item['qty']);
        $this->assertEquals(5000, $item['revenue']);
        $this->assertArrayHasKey('qty_prev', $item);
        $this->assertArrayHasKey('revenue_prev', $item);
        $this->assertNotNull($item['product_name']);
        $this->assertArrayHasKey('category_name', $item);
        $this->assertNotNull($res->json('data.meta.prev_label'));
    }

    public function test_products_view_paginates(): void
    {
        $admin = $this->adminOrSkip();

        Sanctum::actingAs($admin);
        $res = $this->getJson('/sales-dashboard?periode=year&view=products&per_page=2');

        $res->assertOk();
        $items = $res->json('data.items');
        $this->assertLessThanOrEqual(2, count($items));
        $this->assertNotNull($res->json('data.meta.last_page'));
    }

    public function test_products_view_sort_by_revenue_orders_correctly(): void
    {
        $admin = $this->adminOrSkip();

        Sanctum::actingAs($admin);
        $res = $this->getJson('/sales-dashboard?periode=year&view=products&sort=revenue');

        $res->assertOk()->assertJsonPath('data.sort', 'revenue');
        $items = $res->json('data.items');
        if (count($items) >= 2) {
            $this->assertGreaterThanOrEqual($items[1]['revenue'], $items[0]['revenue']);
        }
    }

    public function test_channels_view_returns_breakdown_with_percentage(): void
    {
        $admin = $this->adminOrSkip();
        $store = \App\Models\Store::first();
        if (!$store) {
            $this->markTestSkipped('Need at least 1 store');
        }

        $today = now()->format('Y-m-d');
        \DB::table('sales_orders')->insert([
            'for' => '3', 'delivery_date' => $today, 'store_id' => $store->id,
            'delivery_status' => 3, 'payment_status' => 1, 'total_price' => 1000000,
            'ordered_by_id' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        Sanctum::actingAs($admin);
        $res = $this->getJson('/sales-dashboard?periode=today&view=channels');

        $res->assertOk()
            ->assertJsonStructure(['data' => ['total_omzet', 'items']]);

        $items = $res->json('data.items');
        $this->assertIsArray($items);
        if (!empty($items)) {
            $first = $items[0];
            $this->assertArrayHasKey('channel', $first);
            $this->assertArrayHasKey('channel_label', $first);
            $this->assertArrayHasKey('omzet', $first);
            $this->assertArrayHasKey('percentage', $first);

            $total = array_sum(array_column($items, 'percentage'));
            $this->assertEqualsWithDelta(100, $total, 0.5, 'Total percentage should be ~100');
        }
    }

    public function test_channels_view_handles_empty_period(): void
    {
        $admin = $this->adminOrSkip();

        Sanctum::actingAs($admin);
        $res = $this->getJson('/sales-dashboard?periode=today&view=channels');

        $res->assertOk()->assertJsonPath('data.total_omzet', 0);
    }

    public function test_channels_label_mapping(): void
    {
        $admin = $this->adminOrSkip();

        Sanctum::actingAs($admin);
        $res = $this->getJson('/sales-dashboard?periode=year&view=channels');

        $res->assertOk();
        $items = $res->json('data.items');
        $labels = array_column($items, 'channel_label');
        $validLabels = ['Direct', 'Employee', 'Online'];
        foreach ($labels as $l) {
            $this->assertContains($l, $validLabels, "Unexpected label: {$l}");
        }
    }

    public function test_trend_compare_year_returns_omzet_prev(): void
    {
        $admin = $this->adminOrSkip();
        $store = \App\Models\Store::first();
        if (!$store) {
            $this->markTestSkipped('Need at least 1 store');
        }

        $currentYear = (int) now()->format('Y');
        $prevYear = $currentYear - 1;
        $currentYearMonth = sprintf('%04d-01', $currentYear);

        // Insert SO Jan tahun ini
        \DB::table('sales_orders')->insert([
            'for' => '3',
            'delivery_date' => $currentYear . '-01-15',
            'store_id' => $store->id,
            'delivery_status' => 3,
            'payment_status' => 1,
            'total_price' => 10000000,
            'ordered_by_id' => 1,
            'created_at' => $currentYear . '-01-15 10:00:00',
            'updated_at' => $currentYear . '-01-15 10:00:00',
        ]);
        // Insert SO Jan tahun lalu
        \DB::table('sales_orders')->insert([
            'for' => '3',
            'delivery_date' => $prevYear . '-01-15',
            'store_id' => $store->id,
            'delivery_status' => 3,
            'payment_status' => 1,
            'total_price' => 8500000,
            'ordered_by_id' => 1,
            'created_at' => $prevYear . '-01-15 10:00:00',
            'updated_at' => $prevYear . '-01-15 10:00:00',
        ]);

        Sanctum::actingAs($admin);
        $res = $this->getJson("/sales-dashboard?periode=year&view=trend&compare_year={$prevYear}");

        $res->assertOk()
            ->assertJsonPath('data.compare_year', $prevYear);
        $points = $res->json('data.points');
        $janPoint = collect($points)->firstWhere('label', $currentYearMonth);
        $this->assertNotNull($janPoint, 'Jan current year point must exist');
        $this->assertEquals(10000000, $janPoint['omzet']);
        $this->assertEquals(8500000, $janPoint['omzet_prev']);
    }

    public function test_trend_compare_year_same_as_current_returns_null_compare(): void
    {
        $admin = $this->adminOrSkip();
        $currentYear = (int) now()->format('Y');

        Sanctum::actingAs($admin);
        $res = $this->getJson("/sales-dashboard?periode=year&view=trend&compare_year={$currentYear}");

        $res->assertOk()->assertJsonPath('data.compare_year', null);
        $points = $res->json('data.points');
        $this->assertArrayNotHasKey('omzet_prev', $points[0]);
    }

    public function test_trend_compare_year_invalid_returns_422(): void
    {
        $admin = $this->adminOrSkip();

        Sanctum::actingAs($admin);
        $res = $this->getJson('/sales-dashboard?periode=year&view=trend&compare_year=1999');

        $res->assertStatus(422);
    }

    public function test_categories_view_returns_online_category_breakdown(): void
    {
        $admin = $this->adminOrSkip();

        Sanctum::actingAs($admin);
        $res = $this->getJson('/sales-dashboard?periode=today&view=categories');

        $res->assertOk()
            ->assertJsonPath('data.view', 'categories')
            ->assertJsonStructure([
                'success',
                'data' => [
                    'view',
                    'periode',
                    'total_omzet',
                    'total_qty',
                    'prev_label',
                    'items',
                ],
            ]);
    }
}
