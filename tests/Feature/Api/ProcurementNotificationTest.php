<?php

namespace Tests\Feature\Api;

use App\Models\DailySalary;
use App\Models\DetailRequest;
use App\Models\FuelService;
use App\Models\InvoicePurchase;
use App\Models\PaymentReceipt;
use App\Models\PaymentType;
use App\Models\Product;
use App\Models\RequestPurchase;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\FcmService;
use App\Services\ProcurementNotificationService;
use Laravel\Sanctum\Sanctum;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

/**
 * Menguji notifikasi procurement (invoice transfer & payment receipt) yang
 * dipicu dari ProcurementController dan dikirim lewat ProcurementNotificationService.
 *
 * FcmService di-swap dengan mock di container sehingga tidak benar-benar
 * memanggil Firebase; kita mengassert siapa yang menerima push.
 */
class ProcurementNotificationTest extends TestCase
{
    private FcmService $fcm;

    /** @var array<int> user_id yang menerima push (hasDevice selalu true di mock). */
    private array $sentTo = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->sentTo = [];
        $this->fcm = Mockery::mock(FcmService::class);
        $this->fcm->shouldReceive('hasDevice')->andReturn(true);
        $this->fcm->shouldReceive('sendToUser')
            ->andReturnUsing(function ($userId, $data) {
                $this->sentTo[] = $userId;
                return 1;
            });
        $this->app->instance(FcmService::class, $this->fcm);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        return $user;
    }

    private function store(): Store
    {
        return Store::factory()->create();
    }

    /**
     * Buat PaymentType dengan id eksplisit (controller membandingkan == 1).
     */
    private function ensurePaymentType(int $id, string $name): void
    {
        if (!PaymentType::find($id)) {
            PaymentType::insert(['id' => $id, 'name' => $name, 'status' => 1]);
        }
    }

    // ------------------------------------------------------------------
    // 1. Invoice Transfer dibuat → push ke admin/super_admin (kecuali creator)
    // ------------------------------------------------------------------

    public function test_invoice_transfer_created_notifies_admins_not_creator(): void
    {
        $this->ensurePaymentType(1, 'Transfer');

        $creator = $this->userWithRole('staff');
        $admin = $this->userWithRole('admin');
        $superAdmin = $this->userWithRole('super_admin');
        $otherStaff = $this->userWithRole('staff');

        $store = $this->store();
        $invoice = InvoicePurchase::factory()->create([
            'store_id' => $store->id,
            'payment_type_id' => 1,
            'created_by_id' => $creator->id,
            'total_price' => 1500000,
        ]);

        app(ProcurementNotificationService::class)
            ->notifyInvoiceTransferCreated($invoice, $creator->id);

        $this->assertContains($admin->id, $this->sentTo);
        $this->assertContains($superAdmin->id, $this->sentTo);
        $this->assertNotContains($creator->id, $this->sentTo);
        $this->assertNotContains($otherStaff->id, $this->sentTo);
    }

    /**
     * Endpoint POST /procurement/invoices dengan payment_type_id=1 memicu push,
     * dengan payment_type_id=2 (Cash) TIDAK memicu push.
     */
    public function test_store_invoice_endpoint_triggers_push_only_for_transfer(): void
    {
        $this->ensurePaymentType(1, 'Transfer');
        $this->ensurePaymentType(2, 'Cash');

        $creator = $this->userWithRole('staff');
        $admin = $this->userWithRole('admin');

        $store = $this->store();
        $supplier = Supplier::create(['name' => 'Supp', 'status' => 1]);
        $requestPurchase = RequestPurchase::create([
            'store_id' => $store->id,
            'date' => now()->toDateString(),
            'status' => 1,
        ]);

        $u = \DB::table('units')->insertGetId(['name' => 'pcs', 'unit' => 'pcs']);
        $mg = \DB::table('material_groups')->insertGetId(['name' => 'mg', 'status' => 1]);
        $oc = \DB::table('online_categories')->insertGetId(['name' => 'oc', 'status' => 1]);
        $pg = \DB::table('product_groups')->insertGetId(['name' => 'pg']);
        $product = Product::create([
            'name' => 'Prod ' . uniqid(),
            'slug' => 'prod-' . uniqid(),
            'request' => 1,
            'remaining' => 1,
            'unit_id' => $u,
            'material_group_id' => $mg,
            'online_category_id' => $oc,
            'product_group_id' => $pg,
            'payment_type_id' => 1,
            'user_id' => $creator->id,
        ]);

        $detailRequest = DetailRequest::create([
            'product_id' => $product->id,
            'quantity_plan' => 2,
            'status' => 4,
            'request_purchase_id' => $requestPurchase->id,
            'store_id' => $store->id,
            'payment_type_id' => 1,
        ]);

        // --- Transfer (payment_type_id = 1) → harus push ke admin ---
        Sanctum::actingAs($creator);
        $res = $this->postJson('/procurement/invoices', [
            'supplier_id' => $supplier->id,
            'store_id' => $store->id,
            'payment_type_id' => 1,
            'date' => now()->toDateString(),
            'items' => [
                [
                    'detail_request_id' => $detailRequest->id,
                    'quantity_product' => 2,
                    'subtotal_invoice' => 1500000,
                ],
            ],
        ]);

        $res->assertCreated();
        $this->assertContains($admin->id, $this->sentTo);
        $this->assertNotContains($creator->id, $this->sentTo);

        // --- Cash (payment_type_id = 2) → TIDAK boleh push ---
        $this->sentTo = [];
        $detailRequest2 = DetailRequest::create([
            'product_id' => $product->id,
            'quantity_plan' => 1,
            'status' => 4,
            'request_purchase_id' => $requestPurchase->id,
            'store_id' => $store->id,
            'payment_type_id' => 2,
        ]);
        $res2 = $this->postJson('/procurement/invoices', [
            'supplier_id' => $supplier->id,
            'store_id' => $store->id,
            'payment_type_id' => 2,
            'date' => now()->toDateString(),
            'items' => [
                [
                    'detail_request_id' => $detailRequest2->id,
                    'quantity_product' => 1,
                    'subtotal_invoice' => 500000,
                ],
            ],
        ]);

        $res2->assertCreated();
        $this->assertEmpty($this->sentTo, 'Invoice Cash tidak boleh memicu push.');
    }

    // ------------------------------------------------------------------
    // 2. Payment Receipt dibuat → push ke created_by tiap record (kecuali payer)
    // ------------------------------------------------------------------

    public function test_payment_receipt_invoice_branch_notifies_creators(): void
    {
        $this->ensurePaymentType(1, 'Transfer');

        $payer = $this->userWithRole('staff');
        $ownerA = $this->userWithRole('staff');
        $ownerB = $this->userWithRole('staff');
        $store = $this->store();

        $invA = InvoicePurchase::factory()->create([
            'store_id' => $store->id,
            'payment_type_id' => 1,
            'created_by_id' => $ownerA->id,
        ]);
        $invB = InvoicePurchase::factory()->create([
            'store_id' => $store->id,
            'payment_type_id' => 1,
            'created_by_id' => $ownerB->id,
        ]);

        $receipt = PaymentReceipt::create([
            'payment_for' => 3,
            'total_amount' => 1000000,
            'transfer_amount' => 1000000,
            'user_id' => $payer->id,
        ]);
        $receipt->invoicePurchases()->attach([$invA->id, $invB->id]);

        app(ProcurementNotificationService::class)->notifyPaymentReceiptPaid($receipt);

        $this->assertContains($ownerA->id, $this->sentTo);
        $this->assertContains($ownerB->id, $this->sentTo);
        $this->assertNotContains($payer->id, $this->sentTo);
    }

    public function test_payment_receipt_daily_salary_branch_notifies_creators(): void
    {
        $this->ensurePaymentType(1, 'Transfer');

        $payer = $this->userWithRole('staff');
        $owner = $this->userWithRole('staff');
        $store = $this->store();

        $salary = DailySalary::create([
            'store_id' => $store->id,
            'amount' => 200000,
            'payment_type_id' => 1,
            'status' => 3,
            'created_by_id' => $owner->id,
        ]);

        $receipt = PaymentReceipt::create([
            'payment_for' => 2,
            'total_amount' => 200000,
            'transfer_amount' => 200000,
            'user_id' => $payer->id,
        ]);
        $receipt->dailySalaries()->attach([$salary->id]);

        app(ProcurementNotificationService::class)->notifyPaymentReceiptPaid($receipt);

        $this->assertContains($owner->id, $this->sentTo);
        $this->assertNotContains($payer->id, $this->sentTo);
    }

    public function test_payment_receipt_fuel_service_branch_notifies_creators(): void
    {
        $this->ensurePaymentType(1, 'Transfer');

        $payer = $this->userWithRole('staff');
        $owner = $this->userWithRole('staff');
        $store = $this->store();
        $vehicle = Vehicle::create([
            'no_register' => 'B' . rand(1000, 9999),
            'type' => 1,
            'store_id' => $store->id,
            'status' => 1,
        ]);

        $fuel = FuelService::create([
            'date' => now()->toDateString(),
            'vehicle_id' => $vehicle->id,
            'payment_type_id' => 1,
            'fuel_service' => 1,
            'km' => 100,
            'liter' => 10,
            'amount' => 150000,
            'created_by_id' => $owner->id,
            'status' => 1,
        ]);

        $receipt = PaymentReceipt::create([
            'payment_for' => 1,
            'total_amount' => 150000,
            'transfer_amount' => 150000,
            'user_id' => $payer->id,
        ]);
        $receipt->fuelServices()->attach([$fuel->id]);

        app(ProcurementNotificationService::class)->notifyPaymentReceiptPaid($receipt);

        $this->assertContains($owner->id, $this->sentTo);
        $this->assertNotContains($payer->id, $this->sentTo);
    }

    // ------------------------------------------------------------------
    // 3. Kegagalan FCM tidak menggagalkan request utama
    // ------------------------------------------------------------------

    public function test_fcm_failure_does_not_break_payment_receipt(): void
    {
        $this->ensurePaymentType(1, 'Transfer');

        // FcmService yang me-throw saat mengirim.
        $failing = Mockery::mock(FcmService::class);
        $failing->shouldReceive('hasDevice')->andReturn(true);
        $failing->shouldReceive('sendToUser')->andThrow(new \RuntimeException('FCM down'));
        $this->app->instance(FcmService::class, $failing);

        $payer = $this->userWithRole('admin');
        $owner = $this->userWithRole('staff');
        $store = $this->store();

        $inv = InvoicePurchase::factory()->create([
            'store_id' => $store->id,
            'payment_type_id' => 1,
            'created_by_id' => $owner->id,
        ]);

        Sanctum::actingAs($payer);
        $res = $this->postJson('/procurement/payment-receipts', [
            'invoice_ids' => [$inv->id],
            'transfer_amount' => 1000000,
        ]);

        // Response tetap sukses 201 meski FCM gagal.
        $res->assertCreated();
        // Record tetap ter-update (invoice lunas).
        $this->assertEquals('2', $inv->fresh()->payment_status);

        // Service tidak me-lempar ke luar.
        $this->assertTrue(true);
    }

    public function test_payment_receipt_endpoint_notifies_creator(): void
    {
        $this->ensurePaymentType(1, 'Transfer');

        $payer = $this->userWithRole('admin');
        $owner = $this->userWithRole('staff');
        $store = $this->store();

        $inv = InvoicePurchase::factory()->create([
            'store_id' => $store->id,
            'payment_type_id' => 1,
            'created_by_id' => $owner->id,
        ]);

        Sanctum::actingAs($payer);
        $res = $this->postJson('/procurement/payment-receipts', [
            'invoice_ids' => [$inv->id],
            'transfer_amount' => 1000000,
        ]);

        $res->assertCreated();
        $this->assertContains($owner->id, $this->sentTo);
        $this->assertNotContains($payer->id, $this->sentTo);

        // Endpoint daily salary (payment_for=2) juga memicu push ke creator.
        $salary = DailySalary::create([
            'store_id' => $store->id,
            'amount' => 200000,
            'payment_type_id' => 1,
            'status' => 3,
            'created_by_id' => $owner->id,
        ]);
        $res2 = $this->postJson('/procurement/payment-receipts', [
            'payment_for' => 2,
            'daily_salary_ids' => [$salary->id],
            'transfer_amount' => 200000,
        ]);
        $res2->assertCreated();
        $this->assertContains($owner->id, $this->sentTo);

        // Endpoint fuel service juga memicu push ke creator.
        $vehicle = Vehicle::create([
            'no_register' => 'B' . rand(1000, 9999),
            'type' => 1,
            'store_id' => $store->id,
            'status' => 1,
        ]);
        // Receipt fuel service kini mewajibkan item punya supplier.
        $fsSupplier = Supplier::factory()->create();
        $fuel = FuelService::create([
            'date' => now()->toDateString(),
            'vehicle_id' => $vehicle->id,
            'supplier_id' => $fsSupplier->id,
            'payment_type_id' => 1,
            'fuel_service' => 1,
            'km' => 100,
            'liter' => 10,
            'amount' => 150000,
            'created_by_id' => $owner->id,
            'status' => 1,
        ]);
        $res3 = $this->postJson('/procurement/fuel-service-payment-receipts', [
            'fuel_service_ids' => [$fuel->id],
            'transfer_amount' => 150000,
        ]);
        $res3->assertCreated();
        $this->assertContains($owner->id, $this->sentTo);
    }

    // ------------------------------------------------------------------
    // 4. Bypass status "siap dibayar": daily salary status 1 (belum dibayar)
    //    boleh langsung dibayar; status 2 (dibayar) & 4 (perbaiki) ditolak.
    // ------------------------------------------------------------------

    public function test_payment_receipt_daily_salary_status_1_can_be_paid(): void
    {
        $this->ensurePaymentType(1, 'Transfer');

        $payer = $this->userWithRole('admin');
        $owner = $this->userWithRole('staff');
        $store = $this->store();

        $salary = DailySalary::create([
            'store_id' => $store->id,
            'amount' => 200000,
            'payment_type_id' => 1,
            'status' => '1', // belum dibayar → bypass langsung dibayar
            'created_by_id' => $owner->id,
        ]);

        Sanctum::actingAs($payer);
        $res = $this->postJson('/procurement/payment-receipts', [
            'payment_for' => 2,
            'daily_salary_ids' => [$salary->id],
            'transfer_amount' => 200000,
        ]);

        $res->assertCreated();
        $this->assertEquals('2', $salary->fresh()->status);
        $this->assertContains($owner->id, $this->sentTo);
        $this->assertNotContains($payer->id, $this->sentTo);
    }

    public function test_payment_receipt_daily_salary_status_3_still_works(): void
    {
        $this->ensurePaymentType(1, 'Transfer');

        $payer = $this->userWithRole('admin');
        $store = $this->store();

        $salary = DailySalary::create([
            'store_id' => $store->id,
            'amount' => 200000,
            'payment_type_id' => 1,
            'status' => '3', // siap dibayar (regresi)
            'created_by_id' => $payer->id,
        ]);

        Sanctum::actingAs($payer);
        $res = $this->postJson('/procurement/payment-receipts', [
            'payment_for' => 2,
            'daily_salary_ids' => [$salary->id],
            'transfer_amount' => 200000,
        ]);

        $res->assertCreated();
        $this->assertEquals('2', $salary->fresh()->status);
    }

    public function test_payment_receipt_daily_salary_status_2_and_4_rejected(): void
    {
        $this->ensurePaymentType(1, 'Transfer');

        $payer = $this->userWithRole('admin');
        $store = $this->store();

        foreach (['2', '4'] as $status) {
            $salary = DailySalary::create([
                'store_id' => $store->id,
                'amount' => 200000,
                'payment_type_id' => 1,
                'status' => $status,
                'created_by_id' => $payer->id,
            ]);

            Sanctum::actingAs($payer);
            $res = $this->postJson('/procurement/payment-receipts', [
                'payment_for' => 2,
                'daily_salary_ids' => [$salary->id],
                'transfer_amount' => 200000,
            ]);

            $res->assertStatus(400);
            $res->assertJson([
                'success' => false,
            ]);
            $res->assertJsonPath(
                'message',
                "Daily salary #{$salary->id} tidak dapat dibayar (status saat ini tidak diizinkan)."
            );

            // Status tidak berubah dan daily salary tidak ter-attach ke receipt manapun.
            $this->assertEquals($status, $salary->fresh()->status);
            $this->assertDatabaseMissing('daily_salary_payment_receipt', [
                'daily_salary_id' => $salary->id,
            ]);
        }
    }
}
