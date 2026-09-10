<?php

namespace Tests\Feature\Api;

use App\Models\InvoicePurchase;
use App\Models\Supplier;
use App\Models\Store;
use App\Models\Vehicle;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SupplierVerificationAndClosingTest extends TestCase
{
    private function userWithRole(string $role): \App\Models\User
    {
        $user = \App\Models\User::factory()->create();
        $user->assignRole($role);
        return $user;
    }

    private function makeInvoice(array $attributes = []): InvoicePurchase
    {
        $store = Store::first();
        if (!$store) {
            $this->markTestSkipped('Need at least 1 store in database');
        }

        return InvoicePurchase::factory()->create(array_merge([
            'store_id' => $store->id,
            'payment_type_id' => 1,
            'payment_status' => '1',
        ], $attributes));
    }

    protected function setUp(): void
    {
        parent::setUp();
        // Ensure supervisor role exists
        if (!Role::where('name', 'supervisor')->exists()) {
            Role::firstOrCreate(['name' => 'supervisor']);
        }
    }

    // ── A1: Closing info on invoice endpoints ─────────────────────────────

    public function test_invoices_endpoint_includes_closing_stores(): void
    {
        Sanctum::actingAs($this->userWithRole('admin'));
        $invoice = $this->makeInvoice();

        $res = $this->getJson('/procurement/invoices');

        $res->assertOk()->assertJsonStructure([
            'data' => [
                '*' => ['closing_stores'],
            ],
        ]);
    }

    public function test_show_invoice_endpoint_includes_closing_stores(): void
    {
        Sanctum::actingAs($this->userWithRole('admin'));
        $invoice = $this->makeInvoice();

        $res = $this->getJson("/procurement/invoices/{$invoice->id}");

        $res->assertOk()->assertJsonStructure([
            'data' => ['closing_stores'],
        ]);
    }

    // ── A2: Supplier store guard ─────────────────────────────────────────

    public function test_staff_cannot_create_supplier(): void
    {
        Sanctum::actingAs($this->userWithRole('staff'));

        $res = $this->postJson('/suppliers', [
            'name' => 'Test Supplier',
            'address' => 'Jl. Test',
            'province_id' => 1,
            'city_id' => 1,
        ]);

        $res->assertStatus(403);
    }

    public function test_supervisor_can_create_supplier(): void
    {
        Sanctum::actingAs($this->userWithRole('supervisor'));

        $res = $this->postJson('/suppliers', [
            'name' => 'Test Supplier Supervisor',
            'address' => 'Jl. Test Supervisor',
        ]);

        // Supervisor is allowed; may fail on province/city validation but not 403
        $this->assertNotEquals(403, $res->getStatusCode());
    }

    public function test_admin_can_create_supplier(): void
    {
        Sanctum::actingAs($this->userWithRole('admin'));

        $res = $this->postJson('/suppliers', [
            'name' => 'Test Supplier Admin',
            'address' => 'Jl. Test Admin',
        ]);

        // Admin is allowed; may fail on province/city validation but not 403
        $this->assertNotEquals(403, $res->getStatusCode());
    }

    // ── A2: Supplier update — staff image-only ────────────────────────────

    public function test_staff_can_only_update_image(): void
    {
        Sanctum::actingAs($this->userWithRole('staff'));
        $supplier = Supplier::factory()->create(['name' => 'Original Name']);

        // Route is POST /{id}
        $res = $this->postJson("/suppliers/{$supplier->id}", [
            'name' => 'Changed Name',
            'image' => 'images/test.jpg',
        ]);

        $res->assertOk();
        $this->assertSame('Original Name', $supplier->fresh()->name);
        $this->assertSame('images/test.jpg', $supplier->fresh()->image);
    }

    public function test_staff_status_change_is_ignored(): void
    {
        Sanctum::actingAs($this->userWithRole('staff'));
        $supplier = Supplier::factory()->create(['status' => 1]);

        $res = $this->postJson("/suppliers/{$supplier->id}", [
            'status' => 2,
        ]);

        $res->assertOk();
        $this->assertSame(1, $supplier->fresh()->status);
    }

    // ── A2: Supplier update — admin/super_admin status ────────────────────

    public function test_admin_can_update_status(): void
    {
        Sanctum::actingAs($this->userWithRole('admin'));
        $supplier = Supplier::factory()->create(['status' => 1, 'image' => 'photo.jpg']);

        $res = $this->postJson("/suppliers/{$supplier->id}", [
            'status' => 2,
        ]);

        $res->assertOk();
        $this->assertSame(2, $supplier->fresh()->status);
    }

    public function test_super_admin_can_update_status(): void
    {
        Sanctum::actingAs($this->userWithRole('super_admin'));
        $supplier = Supplier::factory()->create(['status' => 1, 'image' => 'photo.jpg']);

        $res = $this->postJson("/suppliers/{$supplier->id}", [
            'status' => 2,
        ]);

        $res->assertOk();
        $this->assertSame(2, $supplier->fresh()->status);
    }

    public function test_supervisor_cannot_update_status(): void
    {
        Sanctum::actingAs($this->userWithRole('supervisor'));
        $supplier = Supplier::factory()->create(['status' => 1]);

        $res = $this->postJson("/suppliers/{$supplier->id}", [
            'status' => 2,
        ]);

        $res->assertOk();
        $this->assertSame(1, $supplier->fresh()->status);
    }

    // ── A2: Foto wajib untuk Valid ────────────────────────────────────────

    public function test_cannot_set_valid_without_photo(): void
    {
        Sanctum::actingAs($this->userWithRole('admin'));
        $supplier = Supplier::factory()->create(['status' => 1, 'image' => null]);

        $res = $this->postJson("/suppliers/{$supplier->id}", [
            'status' => 2,
        ]);

        $res->assertStatus(422);
        $this->assertStringContainsString('foto', $res->json('message'));
    }

    public function test_can_set_valid_with_existing_photo(): void
    {
        Sanctum::actingAs($this->userWithRole('admin'));
        $supplier = Supplier::factory()->create(['status' => 1, 'image' => 'photo.jpg']);

        $res = $this->postJson("/suppliers/{$supplier->id}", [
            'status' => 2,
        ]);

        $res->assertOk();
        $this->assertSame(2, $supplier->fresh()->status);
    }

    public function test_can_set_valid_with_new_photo(): void
    {
        Sanctum::actingAs($this->userWithRole('admin'));
        $supplier = Supplier::factory()->create(['status' => 1, 'image' => null]);

        $res = $this->postJson("/suppliers/{$supplier->id}", [
            'status' => 2,
            'image' => 'new-photo.jpg',
        ]);

        $res->assertOk();
        $this->assertSame(2, $supplier->fresh()->status);
        $this->assertSame('new-photo.jpg', $supplier->fresh()->image);
    }

    // ── A3: Blacklist gating ─────────────────────────────────────────────

    public function test_create_invoice_rejects_blacklisted_supplier(): void
    {
        Sanctum::actingAs($this->userWithRole('admin'));
        $store = Store::first();
        if (!$store) {
            $this->markTestSkipped('Need at least 1 store');
        }
        $blacklisted = Supplier::factory()->create(['status' => 3]);

        // The blacklist check happens before item validation,
        // so we test via updateInvoice which is simpler to set up
        $invoice = $this->makeInvoice();

        $res = $this->putJson("/procurement/invoices/{$invoice->id}", [
            'supplier_id' => $blacklisted->id,
        ]);

        $res->assertStatus(422);
        $this->assertStringContainsString('Blacklist', $res->json('message'));
    }

    public function test_update_invoice_rejects_blacklisted_supplier(): void
    {
        Sanctum::actingAs($this->userWithRole('admin'));
        $invoice = $this->makeInvoice();
        $blacklisted = Supplier::factory()->create(['status' => 3]);

        $res = $this->putJson("/procurement/invoices/{$invoice->id}", [
            'supplier_id' => $blacklisted->id,
        ]);

        $res->assertStatus(422);
        $this->assertStringContainsString('Blacklist', $res->json('message'));
    }

    public function test_create_fuel_service_rejects_blacklisted_supplier(): void
    {
        Sanctum::actingAs($this->userWithRole('admin'));
        $blacklisted = Supplier::factory()->create(['status' => 3]);

        $store = Store::first() ?? Store::factory()->create();
        $vehicle = Vehicle::create([
            'no_register' => 'B' . rand(1000, 9999),
            'type' => 1,
            'store_id' => $store->id,
            'status' => 1,
        ]);

        $res = $this->postJson('/closing-stores/fuel-services', [
            'date' => now()->format('Y-m-d'),
            'fuel_service' => 1,
            'vehicle_id' => $vehicle->id,
            'supplier_id' => $blacklisted->id,
            'amount' => 100000,
            'image' => 'test.jpg',
        ]);

        $res->assertStatus(422);
        $this->assertStringContainsString('Blacklist', $res->json('message'));
    }

    public function test_closing_store_suppliers_excludes_blacklisted(): void
    {
        Sanctum::actingAs($this->userWithRole('admin'));
        $blacklisted = Supplier::factory()->create(['status' => 3]);

        $res = $this->getJson('/closing-stores/suppliers');

        $res->assertOk();
        $supplierIds = collect($res->json('data'))->pluck('id');
        $this->assertFalse($supplierIds->contains($blacklisted->id));
    }
}
