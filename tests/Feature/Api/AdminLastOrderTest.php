<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminLastOrderTest extends TestCase
{
    /** Order/address ids yang dibuat test ini — dihapus di tearDown. */
    private array $createdOrderIds = [];
    private array $createdAddressIds = [];
    private array $createdDetailIds = [];
    private array $createdFollowUpIds = [];
    /** User factory ids — dihapus best-effort di tearDown. */
    private array $createdUserIds = [];
    private int $addressSeq = 0;

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        $this->createdUserIds[] = $user->id;
        return $user;
    }

    /**
     * Order direct (for=1) milik $orderedBy. created_at/total_price/for bisa
     * dioverride lewat $attributes (created_at eksplisit supaya urutan
     * deterministik). delivery_address_id otomatis dibuat bila tidak diberi.
     */
    private function makeOrder(User $orderedBy, array $attributes = []): int
    {
        $now = Carbon::now();
        $attributes['delivery_address_id'] ??= $this->makeAddress($orderedBy->id);

        $id = DB::table('sales_orders')->insertGetId(array_merge([
            'for' => '1',
            'delivery_date' => now()->toDateString(),
            'ordered_by_id' => $orderedBy->id,
            'assigned_by_id' => $orderedBy->id,
            'payment_status' => 1,
            'delivery_status' => 1,
            'total_price' => 100000,
            'created_at' => $now,
            'updated_at' => $now,
        ], $attributes));
        $this->createdOrderIds[] = $id;
        return $id;
    }

    private function makeAddress(int $userId, ?string $phone = null): int
    {
        $now = Carbon::now();
        $this->addressSeq++;
        $id = DB::table('delivery_addresses')->insertGetId([
            'user_id' => $userId,
            'name' => 'Rumah Test',
            'recipient_name' => 'Penerima Test',
            // Default no telp unik per test agar tidak ter-carry antar user.
            'recipient_telp_no' => $phone ?? sprintf('0812-0000-%04d', $this->addressSeq),
            'address' => 'Jl. Test No. 1',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->createdAddressIds[] = $id;
        return $id;
    }

    protected function tearDown(): void
    {
        DB::table('last_order_follow_ups')->whereIn('id', $this->createdFollowUpIds)->delete();
        DB::table('detail_sales_orders')->whereIn('id', $this->createdDetailIds)->delete();
        DB::table('sales_orders')->whereIn('id', $this->createdOrderIds)->delete();
        DB::table('delivery_addresses')->whereIn('id', $this->createdAddressIds)->delete();
        // User + role pivot di auth DB — best effort (pola suite dev).
        foreach ($this->createdUserIds as $id) {
            try {
                User::find($id)?->delete();
            } catch (\Throwable $e) {
                // Biarkan bila user sudah tidak ada / constraint menolak.
            }
        }
        $this->createdOrderIds = [];
        $this->createdAddressIds = [];
        $this->createdUserIds = [];
        parent::tearDown();
    }

    public function test_admin_can_access(): void
    {
        Sanctum::actingAs($this->userWithRole('admin'));

        $res = $this->getJson('/admin/last-orders');

        $res->assertOk()->assertJson(['success' => true]);
        $this->assertArrayHasKey('meta', $res->json());
    }

    public function test_super_admin_can_access(): void
    {
        Sanctum::actingAs($this->userWithRole('super_admin'));

        $res = $this->getJson('/admin/last-orders');

        $res->assertOk();
    }

    public function test_staff_gets_403(): void
    {
        Sanctum::actingAs($this->userWithRole('staff'));

        $res = $this->getJson('/admin/last-orders');

        $res->assertStatus(403)->assertJson([
            'success' => false,
            'message' => 'Hanya admin yang dapat mengakses daftar ini.',
        ]);
    }

    public function test_sales_gets_403(): void
    {
        Sanctum::actingAs($this->userWithRole('sales'));

        $res = $this->getJson('/admin/last-orders');

        $res->assertStatus(403);
    }

    public function test_aggregates_last_order_and_totals_per_user(): void
    {
        $a = $this->userWithRole('staff');
        $b = $this->userWithRole('staff');

        $this->makeOrder($a, ['created_at' => '2026-09-01 10:00:00', 'total_price' => 50000]);
        $this->makeOrder($a, ['created_at' => '2026-10-01 09:30:00', 'total_price' => 75000]);
        $this->makeOrder($b, ['created_at' => '2026-10-05 08:00:00', 'total_price' => 20000]);

        Sanctum::actingAs($this->userWithRole('admin'));
        $res = $this->getJson('/admin/last-orders?per_page=100');

        $res->assertOk();
        $rows = collect($res->json('data'));
        $rowA = $rows->firstWhere('user_id', $a->id);
        $rowB = $rows->firstWhere('user_id', $b->id);
        $this->assertNotNull($rowA);
        $this->assertNotNull($rowB);

        $this->assertSame('2026-10-01 09:30:00', $rowA['last_order_at']);
        $this->assertSame(125000, $rowA['total_nominal']);
        $this->assertSame(2, $rowA['total_orders']);
        $this->assertSame(1, $rowB['total_orders']);
        $this->assertSame(20000, $rowB['total_nominal']);
        $this->assertSame($a->email, $rowA['email']);
        $this->assertSame($a->name, $rowA['name']);
    }

    public function test_only_direct_for_1_is_counted(): void
    {
        $a = $this->userWithRole('staff');
        $this->makeOrder($a, ['created_at' => '2026-10-06 00:00:00', 'total_price' => 111000]);
        $this->makeOrder($a, ['for' => '3', 'created_at' => '2026-10-07 00:00:00', 'total_price' => 888000]);
        $this->makeOrder($a, ['for' => '2', 'created_at' => '2026-10-08 00:00:00', 'total_price' => 777000]);

        Sanctum::actingAs($this->userWithRole('admin'));
        $res = $this->getJson('/admin/last-orders?per_page=100');

        $rowA = collect($res->json('data'))->firstWhere('user_id', $a->id);
        $this->assertSame('2026-10-06 00:00:00', $rowA['last_order_at']);
        $this->assertSame(1, $rowA['total_orders']);
        $this->assertSame(111000, $rowA['total_nominal']);
    }

    public function test_soft_deleted_orders_excluded(): void
    {
        $a = $this->userWithRole('staff');
        $this->makeOrder($a, ['created_at' => '2026-10-01 00:00:00', 'total_price' => 100000]);
        $deletedId = $this->makeOrder($a, ['created_at' => '2026-10-09 00:00:00', 'total_price' => 999000]);
        DB::table('sales_orders')->where('id', $deletedId)->update(['deleted_at' => now()]);

        Sanctum::actingAs($this->userWithRole('admin'));
        $res = $this->getJson('/admin/last-orders?per_page=100');

        $rowA = collect($res->json('data'))->firstWhere('user_id', $a->id);
        $this->assertSame('2026-10-01 00:00:00', $rowA['last_order_at']);
        $this->assertSame(100000, $rowA['total_nominal']);
    }

    public function test_phones_distinct_from_multiple_addresses_and_fallback_to_user_phone(): void
    {
        $multi = $this->userWithRole('staff');
        $this->makeOrder($multi, ['created_at' => '2026-10-01 00:00:00']);
        // Alamat bawaan order dibuat ber-no telp — kosongkan agar ekspektasi
        // hanya alamat tambahan (sekaligus menguji null phone tidak ikut).
        DB::table('delivery_addresses')->where('user_id', $multi->id)
            ->where('recipient_telp_no', 'like', '0812-0000-%')
            ->update(['recipient_telp_no' => null]);
        $this->makeAddress($multi->id, '0812-1111-2222');
        $this->makeAddress($multi->id, '0812-1111-2222'); // duplikat — muncul sekali
        $this->makeAddress($multi->id, '0898-3333-4444');
        $this->makeAddress($multi->id, '-'); // tanpa digit — tidak ikut

        // Fallback: hapus semua no telp alamat, isi users.phone_number.
        $fallback = $this->userWithRole('staff');
        $this->makeOrder($fallback, ['created_at' => '2026-10-02 00:00:00']);
        DB::table('delivery_addresses')->where('user_id', $fallback->id)
            ->update(['recipient_telp_no' => null]);
        DB::connection('mysql_auth')->table('users')->where('id', $fallback->id)
            ->update(['phone_number' => '0857-9999-8888']);

        Sanctum::actingAs($this->userWithRole('admin'));
        $res = $this->getJson('/admin/last-orders?per_page=100');

        $rows = collect($res->json('data'));
        $rowMulti = $rows->firstWhere('user_id', $multi->id);
        $rowFallback = $rows->firstWhere('user_id', $fallback->id);

        $this->assertEqualsCanonicalizing(
            ['0812-1111-2222', '0898-3333-4444'],
            $rowMulti['phones']
        );
        $this->assertSame(['0857-9999-8888'], $rowFallback['phones']);
    }

    public function test_default_sort_desc_and_sort_asc(): void
    {
        $a = $this->userWithRole('staff');
        $b = $this->userWithRole('staff');
        $this->makeOrder($a, ['created_at' => '2026-10-01 00:00:00']);
        $this->makeOrder($b, ['created_at' => '2026-10-05 00:00:00']);

        Sanctum::actingAs($this->userWithRole('admin'));
        $rowsDesc = collect($this->getJson('/admin/last-orders?per_page=100')->json('data'));
        $rowsAsc = collect($this->getJson('/admin/last-orders?per_page=100&sort=last_order_asc')->json('data'));

        // B (order lebih baru) di atas A pada desc, sebaliknya pada asc.
        $this->assertGreaterThan(
            $rowsDesc->pluck('user_id')->search($b->id),
            $rowsDesc->pluck('user_id')->search($a->id)
        );
        $this->assertLessThan(
            $rowsAsc->pluck('user_id')->search($b->id),
            $rowsAsc->pluck('user_id')->search($a->id)
        );
    }

    public function test_search_by_email_and_by_phone(): void
    {
        $a = $this->userWithRole('staff');
        $this->makeOrder($a, ['created_at' => '2026-10-01 00:00:00']);
        $this->makeAddress($a->id, '0898-3333-4444');

        Sanctum::actingAs($this->userWithRole('admin'));

        $byEmail = $this->getJson('/admin/last-orders?search=' . urlencode($a->email));
        $byEmail->assertOk();
        $this->assertNotNull(
            collect($byEmail->json('data'))->firstWhere('user_id', $a->id)
        );

        $byPhone = $this->getJson('/admin/last-orders?search=3333-4444');
        $byPhone->assertOk();
        $this->assertNotNull(
            collect($byPhone->json('data'))->firstWhere('user_id', $a->id)
        );

        $none = $this->getJson('/admin/last-orders?search=zzz-tidak-ada');
        $none->assertOk();
        $this->assertSame(0, $none->json('meta.total'));
        $this->assertSame([], $none->json('data'));
    }

    public function test_pagination_meta(): void
    {
        $u = [];
        for ($i = 0; $i < 3; $i++) {
            $u[$i] = $this->userWithRole('staff');
            $this->makeOrder($u[$i], ['created_at' => '2026-10-0' . ($i + 1) . ' 00:00:00']);
        }

        Sanctum::actingAs($this->userWithRole('admin'));
        $res = $this->getJson('/admin/last-orders?per_page=2');

        $res->assertOk();
        $this->assertSame(2, $res->json('meta.per_page'));
        $this->assertSame(1, $res->json('meta.current_page'));
        $this->assertSame(
            (int) ceil($res->json('meta.total') / 2),
            $res->json('meta.last_page')
        );
        $this->assertLessThanOrEqual(2, count($res->json('data')));

        $page2 = $this->getJson('/admin/last-orders?per_page=2&page=2');
        $ids1 = collect($res->json('data'))->pluck('user_id');
        $ids2 = collect($page2->json('data'))->pluck('user_id');
        $this->assertEquals([], $ids1->intersect($ids2)->values()->all());
    }

    public function test_invalid_params_return_422(): void
    {
        Sanctum::actingAs($this->userWithRole('admin'));

        $this->getJson('/admin/last-orders?sort=ngawur')->assertStatus(422);
        $this->getJson('/admin/last-orders?per_page=0')->assertStatus(422);
    }

    public function test_last_order_follow_ups_table_exists(): void
    {
        $this->assertTrue(
            \Illuminate\Support\Facades\Schema::hasTable('last_order_follow_ups')
        );
        $this->assertTrue(
            \Illuminate\Support\Facades\Schema::hasColumn('last_order_follow_ups', 'followed_up_by')
        );
    }

    public function test_has_phone_filter(): void
    {
        $withPhone = $this->userWithRole('staff');
        $this->makeOrder($withPhone, ['created_at' => '2026-10-01 00:00:00']);

        // Tanpa no telp sama sekali — alamat & users.phone_number kosong.
        $noPhone = $this->userWithRole('staff');
        $this->makeOrder($noPhone, ['created_at' => '2026-10-02 00:00:00']);
        DB::table('delivery_addresses')->where('user_id', $noPhone->id)
            ->update(['recipient_telp_no' => null]);
        DB::connection('mysql_auth')->table('users')->where('id', $noPhone->id)
            ->update(['phone_number' => null]);

        // Fallback users.phone_number tetap dihitung punya no telp.
        $fallback = $this->userWithRole('staff');
        $this->makeOrder($fallback, ['created_at' => '2026-10-03 00:00:00']);
        DB::table('delivery_addresses')->where('user_id', $fallback->id)
            ->update(['recipient_telp_no' => null]);
        DB::connection('mysql_auth')->table('users')->where('id', $fallback->id)
            ->update(['phone_number' => '0857-7777-8888']);

        // No telp tanpa digit (sampah) TIDAK dihitung punya.
        $garbage = $this->userWithRole('staff');
        $this->makeOrder($garbage, ['created_at' => '2026-10-04 00:00:00']);
        DB::table('delivery_addresses')->where('user_id', $garbage->id)
            ->update(['recipient_telp_no' => '-']);
        DB::connection('mysql_auth')->table('users')->where('id', $garbage->id)
            ->update(['phone_number' => null]);

        Sanctum::actingAs($this->userWithRole('admin'));
        $res = $this->getJson('/admin/last-orders?per_page=100&has_phone=1');

        $res->assertOk();
        $ids = collect($res->json('data'))->pluck('user_id');
        $this->assertContains($withPhone->id, $ids);
        $this->assertContains($fallback->id, $ids);
        $this->assertNotContains($noPhone->id, $ids);
        $this->assertNotContains($garbage->id, $ids);
    }

    public function test_has_phone_combined_with_search_intersects(): void
    {
        $match = $this->userWithRole('staff');
        $this->makeOrder($match, ['created_at' => '2026-10-01 00:00:00']);

        $phoneOnly = $this->userWithRole('staff'); // punya telp, email beda
        $this->makeOrder($phoneOnly, ['created_at' => '2026-10-02 00:00:00']);

        Sanctum::actingAs($this->userWithRole('admin'));
        $res = $this->getJson(
            '/admin/last-orders?per_page=100&has_phone=1&search=' . urlencode($match->email)
        );

        $ids = collect($res->json('data'))->pluck('user_id');
        $this->assertContains($match->id, $ids);
        $this->assertNotContains($phoneOnly->id, $ids);
    }

    public function test_list_includes_last_follow_up(): void
    {
        $admin1 = $this->userWithRole('admin');
        $admin2 = $this->userWithRole('admin');
        $u = $this->userWithRole('staff');
        $this->makeOrder($u, ['created_at' => '2026-10-01 00:00:00']);

        $fu1 = \App\Models\LastOrderFollowUp::forceCreate([
            'user_id' => $u->id,
            'followed_up_by' => $admin1->id,
            'created_at' => '2026-10-02 08:00:00',
            'updated_at' => '2026-10-02 08:00:00',
        ]);
        $fu2 = \App\Models\LastOrderFollowUp::forceCreate([
            'user_id' => $u->id,
            'followed_up_by' => $admin2->id,
            'created_at' => '2026-10-04 09:00:00',
            'updated_at' => '2026-10-04 09:00:00',
        ]);
        $this->createdFollowUpIds[] = $fu1->id;
        $this->createdFollowUpIds[] = $fu2->id;

        Sanctum::actingAs($this->userWithRole('admin'));
        $res = $this->getJson('/admin/last-orders?per_page=100');

        $row = collect($res->json('data'))->firstWhere('user_id', $u->id);
        $this->assertSame('2026-10-04 09:00:00', $row['last_follow_up_at']);
        $this->assertSame($admin2->name, $row['last_follow_up_by_name']);
    }

    private function firstProductId(): ?int
    {
        $product = DB::table('products')->orderBy('id')->first('id');
        return $product?->id;
    }

    private function makeDetail(
        int $orderId,
        ?int $productId,
        int $quantity,
        int $unitPrice,
        ?int $subtotal = null,
    ): int {
        $now = Carbon::now();
        $id = (int) DB::table('detail_sales_orders')->insertGetId([
            'product_id' => $productId,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'subtotal_price' => $subtotal ?? $quantity * $unitPrice,
            'sales_order_id' => $orderId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->createdDetailIds[] = $id;
        return $id;
    }

    public function test_detail_product_summary(): void
    {
        $productId = $this->firstProductId();
        if ($productId === null) {
            $this->markTestSkipped('Need at least 1 product in database');
        }

        $u = $this->userWithRole('staff');
        $o1 = $this->makeOrder($u, ['created_at' => '2026-09-01 00:00:00', 'total_price' => 150000]);
        $o2 = $this->makeOrder($u, ['created_at' => '2026-10-01 00:00:00', 'total_price' => 200000]);
        $this->makeDetail($o1, $productId, 10, 15000, 150000); // harga lama
        $this->makeDetail($o2, $productId, 5, 16000, 80000);   // harga termutakhir
        $this->makeDetail($o2, null, 2, 5000, 10000);          // tanpa produk
        // Order online milik user yang sama tidak ikut.
        $o3 = $this->makeOrder($u, ['for' => '3', 'created_at' => '2026-10-05 00:00:00', 'total_price' => 500000]);
        $this->makeDetail($o3, $productId, 99, 9999);

        Sanctum::actingAs($this->userWithRole('admin'));
        $res = $this->getJson("/admin/last-orders/{$u->id}");

        $res->assertOk();
        $data = $res->json('data');
        $this->assertSame($u->id, $data['user_id']);
        $this->assertSame($u->email, $data['email']);
        $this->assertSame(2, $data['total_orders']);
        $this->assertSame(350000, $data['total_nominal']);
        $this->assertSame('2026-10-01 00:00:00', $data['last_order_at']);
        $this->assertNotEmpty($data['phones']);

        $products = collect($data['products']);
        $main = $products->firstWhere('product_id', $productId);
        $this->assertNotNull($main);
        $this->assertSame(15, $main['quantity']);
        $this->assertSame(16000, $main['unit_price']); // harga satuan termutakhir
        $this->assertSame(230000, $main['total']);

        $nullProduct = $products->firstWhere('product_id', null);
        $this->assertNotNull($nullProduct);
        $this->assertSame('Tanpa Produk', $nullProduct['name']);
        $this->assertSame(2, $nullProduct['quantity']);
        $this->assertSame(10000, $nullProduct['total']);

        // Urut total desc: produk utama di atas baris Tanpa Produk.
        $this->assertLessThan(
            $products->pluck('product_id')->search(null),
            $products->pluck('product_id')->search($productId)
        );

        $this->assertSame([], $data['follow_ups']);
    }

    public function test_detail_returns_404_for_user_without_direct_orders(): void
    {
        $u = $this->userWithRole('staff'); // tanpa order direct

        Sanctum::actingAs($this->userWithRole('admin'));
        $res = $this->getJson("/admin/last-orders/{$u->id}");

        $res->assertStatus(404)->assertJson([
            'success' => false,
            'message' => 'User tidak memiliki order direct.',
        ]);
    }

    public function test_detail_includes_recent_follow_ups(): void
    {
        $u = $this->userWithRole('staff');
        $this->makeOrder($u, ['created_at' => '2026-10-01 00:00:00']);
        $admin = $this->userWithRole('admin');
        $fu = \App\Models\LastOrderFollowUp::forceCreate([
            'user_id' => $u->id,
            'followed_up_by' => $admin->id,
            'created_at' => '2026-10-03 07:30:00',
            'updated_at' => '2026-10-03 07:30:00',
        ]);
        $this->createdFollowUpIds[] = $fu->id;

        Sanctum::actingAs($this->userWithRole('admin'));
        $res = $this->getJson("/admin/last-orders/{$u->id}");

        $followUps = $res->json('data.follow_ups');
        $this->assertCount(1, $followUps);
        $this->assertSame($admin->name, $followUps[0]['by_name']);
        $this->assertSame('2026-10-03 07:30:00', $followUps[0]['created_at']);
    }

    public function test_store_follow_up(): void
    {
        $u = $this->userWithRole('staff');
        $this->makeOrder($u, ['created_at' => '2026-10-01 00:00:00']);
        $admin = $this->userWithRole('admin');

        Sanctum::actingAs($admin);
        $res = $this->postJson("/admin/last-orders/{$u->id}/follow-ups");

        $res->assertStatus(201)->assertJson(['success' => true]);
        $this->assertSame($admin->name, $res->json('data.by_name'));

        $row = DB::table('last_order_follow_ups')
            ->where('user_id', $u->id)
            ->where('followed_up_by', $admin->id)
            ->first();
        $this->assertNotNull($row);
        $this->createdFollowUpIds[] = $row->id;

        // Langsung tercermin di list.
        $list = $this->getJson('/admin/last-orders?per_page=100');
        $rowList = collect($list->json('data'))->firstWhere('user_id', $u->id);
        $this->assertNotNull($rowList['last_follow_up_at']);
        $this->assertSame($admin->name, $rowList['last_follow_up_by_name']);
    }

    public function test_store_follow_up_forbidden_for_staff(): void
    {
        $u = $this->userWithRole('staff');

        Sanctum::actingAs($this->userWithRole('staff'));
        $res = $this->postJson("/admin/last-orders/{$u->id}/follow-ups");

        $res->assertStatus(403);
        $this->assertSame(
            0,
            DB::table('last_order_follow_ups')->where('user_id', $u->id)->count()
        );
    }
}
