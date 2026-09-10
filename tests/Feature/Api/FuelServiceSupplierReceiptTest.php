<?php

namespace Tests\Feature\Api;

use App\Models\FuelService;
use App\Models\PaymentReceipt;
use App\Models\PaymentType;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\User;
use App\Services\QrisService;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FuelServiceSupplierReceiptTest extends TestCase
{
    private const STATIC_QRIS = '00020101021126690014ID.CO.QRIS.WWW011893600915000000000202181500000000000000000303UMI5204541153033605802ID5912TOKO SAGANSA6007JAKARTA6105102106209070503A01630415C4';

    protected function setUp(): void
    {
        parent::setUp();

        if (!PaymentType::find(1)) {
            PaymentType::insert(['id' => 1, 'name' => 'Transfer', 'status' => 1]);
        }
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        return $user;
    }

    private function makeFuelService(Supplier $supplier, int $amount = 50000): FuelService
    {
        $store = Store::first() ?? Store::factory()->create();
        $vehicle = \App\Models\Vehicle::create([
            'no_register' => 'B' . rand(1000, 9999),
            'type' => 1,
            'store_id' => $store->id,
            'status' => 1,
        ]);

        return FuelService::create([
            'store_id' => $store->id,
            'vehicle_id' => $vehicle->id,
            'supplier_id' => $supplier->id,
            'fuel_service' => 2, // Service
            'payment_type_id' => 1, // Transfer
            'amount' => $amount,
            'date' => now()->toDateString(),
            'km' => '10000',
            'liter' => 0,
            'status' => '1', // Pending
            'created_by_id' => 1,
        ]);
    }

    // ── Supplier QRIS Dynamic Endpoint ──────────────────────────────────────

    public function test_admin_gets_dynamic_qris_for_supplier(): void
    {
        Sanctum::actingAs($this->userWithRole('admin'));

        $supplier = Supplier::factory()->create(['qris' => self::STATIC_QRIS]);

        $res = $this->postJson("/suppliers/{$supplier->id}/qris-dynamic", [
            'amount' => 75000,
        ]);

        $res->assertOk()->assertJson(['success' => true]);

        $payload = $res->json('data.payload');
        $this->assertMatchesRegularExpression('/6304[0-9A-F]{4}$/', $payload);
        $this->assertStringContainsString('010212', $payload);
        $this->assertStringContainsString('540575000', $payload);
        $this->assertTrue(app(QrisService::class)->validatePayload($payload));
        $this->assertEquals(75000, $res->json('data.amount'));
    }

    public function test_supplier_qris_dynamic_rejects_supplier_without_qris(): void
    {
        Sanctum::actingAs($this->userWithRole('admin'));

        $supplier = Supplier::factory()->create(['qris' => null]);

        $this->postJson("/suppliers/{$supplier->id}/qris-dynamic", ['amount' => 50000])
            ->assertStatus(400)
            ->assertJson(['success' => false]);
    }

    public function test_supplier_qris_dynamic_rejects_zero_amount(): void
    {
        Sanctum::actingAs($this->userWithRole('admin'));

        $supplier = Supplier::factory()->create(['qris' => self::STATIC_QRIS]);

        $this->postJson("/suppliers/{$supplier->id}/qris-dynamic", ['amount' => 0])
            ->assertStatus(422)
            ->assertJson(['success' => false]);
    }

    public function test_staff_cannot_generate_supplier_qris_dynamic(): void
    {
        Sanctum::actingAs($this->userWithRole('staff'));

        $supplier = Supplier::factory()->create(['qris' => self::STATIC_QRIS]);

        $this->postJson("/suppliers/{$supplier->id}/qris-dynamic", ['amount' => 50000])
            ->assertForbidden();
    }

    // ── Fuel Service Receipt — supplier_id persistence ──────────────────────

    public function test_fuel_service_receipt_stores_supplier_id(): void
    {
        Sanctum::actingAs($this->userWithRole('admin'));

        $supplier = Supplier::factory()->create();
        $fs1 = $this->makeFuelService($supplier, 30000);
        $fs2 = $this->makeFuelService($supplier, 20000);

        $res = $this->postJson('/procurement/fuel-service-payment-receipts', [
            'fuel_service_ids' => [$fs1->id, $fs2->id],
            'transfer_amount' => 50000,
        ]);

        $res->assertCreated()->assertJson(['success' => true]);
        $receiptId = $res->json('data.id');
        $receipt = PaymentReceipt::find($receiptId);
        $this->assertEquals($supplier->id, $receipt->supplier_id);
    }

    public function test_fuel_service_receipt_rejects_mixed_supplier(): void
    {
        Sanctum::actingAs($this->userWithRole('admin'));

        $supplierA = Supplier::factory()->create();
        $supplierB = Supplier::factory()->create();
        $fs1 = $this->makeFuelService($supplierA, 30000);
        $fs2 = $this->makeFuelService($supplierB, 20000);

        $res = $this->postJson('/procurement/fuel-service-payment-receipts', [
            'fuel_service_ids' => [$fs1->id, $fs2->id],
            'transfer_amount' => 50000,
        ]);

        $res->assertStatus(422)->assertJson([
            'success' => false,
            'message' => 'Item yang dipilih berasal dari supplier berbeda. Pilih item dari supplier yang sama untuk satu pembayaran.',
        ]);
    }

    public function test_fuel_service_receipt_rejects_items_without_supplier(): void
    {
        Sanctum::actingAs($this->userWithRole('admin'));

        $supplier = Supplier::factory()->create();
        $fs = $this->makeFuelService($supplier, 30000);
        // Simulate no supplier: null supplier_id
        $fs->update(['supplier_id' => null]);

        $res = $this->postJson('/procurement/fuel-service-payment-receipts', [
            'fuel_service_ids' => [$fs->id],
            'transfer_amount' => 30000,
        ]);

        $res->assertStatus(422)->assertJson([
            'success' => false,
        ]);
    }
}
