<?php

namespace Tests\Feature\Api;

use App\Models\ClosingStore;
use App\Models\DailySalary;
use App\Models\PaymentType;
use App\Models\Presence;
use App\Models\ShiftStore;
use App\Models\Store;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Checklist "Pengeluaran Gaji Harian (Cash)" di Tutup Shift Toko hanya boleh
 * berisi daily salary tunai yang tanggalnya sama dengan tanggal closing store
 * dibuat (bandingkan dengan dailySalaries select di ClosingStoreResource
 * admin: tunai + belum dibayar + toko yang dipilih).
 *
 * Test berjalan di database dev (konvensi test Feature di service ini),
 * baris yang dibuat dibersihkan di tearDown.
 */
class ClosingStoreDailySalaryListTest extends TestCase
{
    private User $staff;
    private Store $store;
    private ShiftStore $shiftStore;

    /** @var int[] */
    private array $salaryIds = [];
    private ?int $closingId = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (!Role::where('name', 'staff')->exists()) {
            Role::firstOrCreate(['name' => 'staff']);
        }
        if (!PaymentType::find(1)) {
            PaymentType::insert(['id' => 1, 'name' => 'Transfer', 'status' => 1]);
        }
        if (!PaymentType::find(2)) {
            PaymentType::insert(['id' => 2, 'name' => 'Tunai', 'status' => 1]);
        }

        $this->staff = User::factory()->create();
        $this->staff->assignRole('staff');

        $this->store = Store::factory()->create();
        // Tanpa factory: ShiftStoreFactory masih menulis kolom skema lama
        // (start_time dsl.) yang tidak ada di tabel shift_stores sebenarnya.
        $this->shiftStore = ShiftStore::create([
            'store_id' => $this->store->id,
            'name' => 'Shift Test',
        ]);

        Presence::create([
            'created_by_id' => $this->staff->id,
            'store_id' => $this->store->id,
            'shift_store_id' => $this->shiftStore->id,
            'status' => 1,
            'check_in' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->closingId) {
            $closing = ClosingStore::find($this->closingId);
            if ($closing) {
                $closing->dailySalaries()->detach();
                $closing->delete();
            }
        }
        if ($this->salaryIds) {
            DailySalary::whereIn('id', $this->salaryIds)->delete();
        }
        Presence::where('created_by_id', $this->staff->id)->delete();

        parent::tearDown();
    }

    private function salary(array $overrides = []): DailySalary
    {
        $salary = DailySalary::create(array_merge([
            'store_id' => $this->store->id,
            'shift_store_id' => $this->shiftStore->id,
            'date' => now()->toDateString(),
            'amount' => 25000,
            'payment_type_id' => 2, // Tunai
            'status' => 1, // Belum dibayar
            'created_by_id' => $this->staff->id,
        ], $overrides));
        $this->salaryIds[] = $salary->id;

        return $salary;
    }

    private function closing(): ClosingStore
    {
        $closing = ClosingStore::create([
            'store_id' => $this->store->id,
            'shift_store_id' => $this->shiftStore->id,
            'date' => now()->toDateString(),
            'cash_from_yesterday' => 0,
            'cash_for_tomorrow' => 0,
            'total_cash_transfer' => 0,
            'status' => 1,
            'created_by_id' => $this->staff->id,
        ]);
        $this->closingId = $closing->id;

        return $closing;
    }

    public function test_unpaid_transactions_only_lists_daily_salaries_of_today(): void
    {
        Sanctum::actingAs($this->staff);

        $today = $this->salary();
        $oldUnpaid = $this->salary(['date' => now()->subDays(3)->toDateString()]);
        $todayPaid = $this->salary(['status' => 2]);
        $todayTransfer = $this->salary(['payment_type_id' => 1]);

        $res = $this->getJson('/closing-stores/unpaid-transactions');

        $res->assertOk();
        $ids = collect($res->json('data.daily_salaries'))->pluck('id');

        $this->assertTrue($ids->contains($today->id), 'Gaji tunai hari ini harus tampil.');
        $this->assertFalse(
            $ids->contains($oldUnpaid->id),
            'Gaji tunai tanggal lama tidak boleh tampil di tutup shift hari ini.'
        );
        $this->assertFalse($ids->contains($todayPaid->id), 'Gaji yang sudah dibayar tidak boleh tampil.');
        $this->assertFalse($ids->contains($todayTransfer->id), 'Gaji transfer tidak boleh tampil.');
    }

    public function test_show_offers_same_date_salaries_and_keeps_attached_ones(): void
    {
        Sanctum::actingAs($this->staff);

        $closing = $this->closing();

        $today = $this->salary();
        $oldUnlinked = $this->salary(['date' => now()->subDays(3)->toDateString()]);
        $oldAttached = $this->salary(['date' => now()->subDays(3)->toDateString()]);
        $closing->dailySalaries()->attach($oldAttached->id);

        $res = $this->getJson("/closing-stores/{$closing->id}");

        $res->assertOk();
        $ids = collect($res->json('data.daily_salaries'))->pluck('id');

        $this->assertTrue($ids->contains($today->id), 'Gaji tunai sedate closing harus tampil.');
        $this->assertFalse(
            $ids->contains($oldUnlinked->id),
            'Gaji tanggal lama yang belum terhubung tidak boleh ditawarkan.'
        );
        $this->assertTrue(
            $ids->contains($oldAttached->id),
            'Gaji yang sudah terhubung ke closing ini tetap tampil saat diedit.'
        );
    }
}
