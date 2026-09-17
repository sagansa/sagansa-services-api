<?php

namespace Tests\Feature\Api;

use App\Models\ClosingStore;
use App\Models\DailySalary;
use App\Models\FuelService;
use App\Models\InvoicePurchase;
use App\Models\PaymentType;
use App\Models\Presence;
use App\Models\ShiftStore;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Checklist pengeluaran cash di Tutup Shift Toko — gaji harian, bensin/servis,
 * dan invoice & bon — hanya boleh berisi item tunai yang tanggalnya sama
 * dengan tanggal closing store dibuat (bandingkan ClosingStoreResource
 * admin: tunai + belum dibayar + toko yang dipilih). Item yang sudah
 * terhubung ke closing tsb tetap tampil saat diedit apa pun tanggalnya.
 *
 * Test berjalan di database test (konvensi test Feature di service ini),
 * baris yang dibuat dibersihkan di tearDown.
 */
class ClosingStoreCashExpenseListTest extends TestCase
{
    private User $staff;
    private Store $store;
    private ShiftStore $shiftStore;
    private Vehicle $vehicle;
    private Supplier $supplier;

    /** @var array<string, int[]> id record per model untuk cleanup */
    private array $createdIds = [
        'daily_salaries' => [],
        'fuel_services' => [],
        'invoice_purchases' => [],
    ];
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
        $this->vehicle = Vehicle::create([
            'no_register' => 'B TEST 01',
            'type' => 1,
            'store_id' => $this->store->id,
            'status' => 1,
        ]);
        $this->supplier = Supplier::factory()->create();

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
                $closing->fuelServices()->detach();
                $closing->invoicePurchases()->detach();
                $closing->delete();
            }
        }
        foreach ($this->createdIds as $table => $ids) {
            if ($ids) {
                DB::table($table)->whereIn('id', $ids)->delete();
            }
        }
        Presence::where('created_by_id', $this->staff->id)->delete();
        DB::table('vehicles')->where('id', $this->vehicle->id)->delete();
        DB::table('shift_stores')->where('id', $this->shiftStore->id)->delete();
        DB::table('stores')->where('id', $this->store->id)->delete();

        parent::tearDown();
    }

    private function track(string $table, int $id): void
    {
        $this->createdIds[$table][] = $id;
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
        $this->track('daily_salaries', $salary->id);

        return $salary;
    }

    private function fuelService(array $overrides = []): FuelService
    {
        $fuel = FuelService::create(array_merge([
            'store_id' => $this->store->id,
            'vehicle_id' => $this->vehicle->id,
            'date' => now()->toDateString(),
            'fuel_service' => 1, // Fuel
            'km' => 1000,
            'liter' => 10,
            'amount' => 20000,
            'payment_type_id' => 2, // Tunai
            'status' => 1, // Pending / belum terhubung
            'created_by_id' => $this->staff->id,
        ], $overrides));
        $this->track('fuel_services', $fuel->id);

        return $fuel;
    }

    private function invoicePurchase(array $overrides = []): InvoicePurchase
    {
        $invoice = InvoicePurchase::create(array_merge([
            'store_id' => $this->store->id,
            'supplier_id' => $this->supplier->id,
            'date' => now()->toDateString(),
            'taxes' => 0,
            'discounts' => 0,
            'total_price' => 150000,
            'payment_type_id' => 2, // Tunai
            'payment_status' => '1', // Belum dibayar
            'order_status' => '1',
            'created_by_id' => $this->staff->id,
        ], $overrides));
        $this->track('invoice_purchases', $invoice->id);

        return $invoice;
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

    public function test_unpaid_transactions_only_lists_same_date_cash_expenses(): void
    {
        Sanctum::actingAs($this->staff);
        $oldDate = now()->subDays(3)->toDateString();

        // Gaji harian
        $today = $this->salary();
        $oldUnpaid = $this->salary(['date' => $oldDate]);
        $todayPaid = $this->salary(['status' => 2]);
        $todayTransfer = $this->salary(['payment_type_id' => 1]);

        // Bensin / servis
        $fuelToday = $this->fuelService();
        $fuelOld = $this->fuelService(['date' => $oldDate]);
        $fuelTodayPaid = $this->fuelService(['status' => 2]);

        // Invoice & bon
        $invoiceToday = $this->invoicePurchase();
        $invoiceOld = $this->invoicePurchase(['date' => $oldDate]);
        $invoiceTodayPaid = $this->invoicePurchase(['payment_status' => '2']);

        $res = $this->getJson('/closing-stores/unpaid-transactions');

        $res->assertOk();
        $data = $res->json('data');

        $salaryIds = collect($data['daily_salaries'])->pluck('id');
        $this->assertTrue($salaryIds->contains($today->id), 'Gaji tunai hari ini harus tampil.');
        $this->assertFalse(
            $salaryIds->contains($oldUnpaid->id),
            'Gaji tunai tanggal lama tidak boleh tampil di tutup shift hari ini.'
        );
        $this->assertFalse($salaryIds->contains($todayPaid->id), 'Gaji yang sudah dibayar tidak boleh tampil.');
        $this->assertFalse($salaryIds->contains($todayTransfer->id), 'Gaji transfer tidak boleh tampil.');

        $fuelIds = collect($data['fuel_services'])->pluck('id');
        $this->assertTrue($fuelIds->contains($fuelToday->id), 'Bensin/servis tunai hari ini harus tampil.');
        $this->assertFalse(
            $fuelIds->contains($fuelOld->id),
            'Bensin/servis tunai tanggal lama tidak boleh tampil di tutup shift hari ini.'
        );
        $this->assertFalse($fuelIds->contains($fuelTodayPaid->id), 'Bensin/servis yang sudah dibayar tidak boleh tampil.');

        $invoiceIds = collect($data['invoice_purchases'])->pluck('id');
        $this->assertTrue($invoiceIds->contains($invoiceToday->id), 'Invoice/bon tunai hari ini harus tampil.');
        $this->assertFalse(
            $invoiceIds->contains($invoiceOld->id),
            'Invoice/bon tunai tanggal lama tidak boleh tampil di tutup shift hari ini.'
        );
        $this->assertFalse($invoiceIds->contains($invoiceTodayPaid->id), 'Invoice yang sudah dibayar tidak boleh tampil.');
    }

    public function test_show_offers_same_date_items_and_keeps_attached_ones(): void
    {
        Sanctum::actingAs($this->staff);
        $oldDate = now()->subDays(3)->toDateString();

        $closing = $this->closing();

        $today = $this->salary();
        $oldUnlinked = $this->salary(['date' => $oldDate]);
        $oldAttached = $this->salary(['date' => $oldDate]);
        $closing->dailySalaries()->attach($oldAttached->id);

        $fuelToday = $this->fuelService();
        $fuelOldUnlinked = $this->fuelService(['date' => $oldDate]);
        $fuelOldAttached = $this->fuelService(['date' => $oldDate]);
        $closing->fuelServices()->attach($fuelOldAttached->id);

        $invoiceToday = $this->invoicePurchase();
        $invoiceOldUnlinked = $this->invoicePurchase(['date' => $oldDate]);
        $invoiceOldAttached = $this->invoicePurchase(['date' => $oldDate]);
        $closing->invoicePurchases()->attach($invoiceOldAttached->id);

        $res = $this->getJson("/closing-stores/{$closing->id}");

        $res->assertOk();
        $data = $res->json('data');

        $salaryIds = collect($data['daily_salaries'])->pluck('id');
        $this->assertTrue($salaryIds->contains($today->id), 'Gaji tunai sedate closing harus tampil.');
        $this->assertFalse(
            $salaryIds->contains($oldUnlinked->id),
            'Gaji tanggal lama yang belum terhubung tidak boleh ditawarkan.'
        );
        $this->assertTrue(
            $salaryIds->contains($oldAttached->id),
            'Gaji yang sudah terhubung ke closing ini tetap tampil saat diedit.'
        );

        $fuelIds = collect($data['fuel_services'])->pluck('id');
        $this->assertTrue($fuelIds->contains($fuelToday->id), 'Bensin/servis sedate closing harus tampil.');
        $this->assertFalse(
            $fuelIds->contains($fuelOldUnlinked->id),
            'Bensin/servis tanggal lama yang belum terhubung tidak boleh ditawarkan.'
        );
        $this->assertTrue(
            $fuelIds->contains($fuelOldAttached->id),
            'Bensin/servis yang sudah terhubung ke closing ini tetap tampil saat diedit.'
        );

        $invoiceIds = collect($data['invoice_purchases'])->pluck('id');
        $this->assertTrue($invoiceIds->contains($invoiceToday->id), 'Invoice/bon sedate closing harus tampil.');
        $this->assertFalse(
            $invoiceIds->contains($invoiceOldUnlinked->id),
            'Invoice/bon tanggal lama yang belum terhubung tidak boleh ditawarkan.'
        );
        $this->assertTrue(
            $invoiceIds->contains($invoiceOldAttached->id),
            'Invoice/bon yang sudah terhubung ke closing ini tetap tampil saat diedit.'
        );
    }
}
