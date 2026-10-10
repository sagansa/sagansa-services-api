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
}
