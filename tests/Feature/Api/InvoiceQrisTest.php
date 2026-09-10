<?php

namespace Tests\Feature\Api;

use App\Models\InvoicePurchase;
use App\Models\PaymentType;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\User;
use App\Services\QrisService;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InvoiceQrisTest extends TestCase
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

    private function makeInvoice(Supplier $supplier, int $price = 150000): InvoicePurchase
    {
        $store = Store::first() ?? Store::factory()->create();
        $creator = User::factory()->create();

        return InvoicePurchase::create([
            'store_id' => $store->id,
            'supplier_id' => $supplier->id,
            'date' => now()->toDateString(),
            'taxes' => 0,
            'discounts' => 0,
            'total_price' => $price,
            'payment_status' => '1',
            'order_status' => '1',
            'payment_type_id' => 1,
            'created_by_id' => $creator->id,
        ]);
    }

    public function test_admin_gets_valid_dynamic_qris_for_invoice(): void
    {
        Sanctum::actingAs($this->userWithRole('admin'));

        $supplier = Supplier::factory()->create(['qris' => self::STATIC_QRIS]);
        $invoice = $this->makeInvoice($supplier, 150000);

        $res = $this->getJson("/procurement/invoices/{$invoice->id}/qris");

        $res->assertOk()->assertJson(['success' => true]);

        $payload = $res->json('data.payload');
        $this->assertMatchesRegularExpression('/6304[0-9A-F]{4}$/', $payload);
        $this->assertStringContainsString('010212', $payload);
        $this->assertStringContainsString('5406150000', $payload);
        $this->assertTrue(app(QrisService::class)->validatePayload($payload));
        $this->assertEquals(150000, $res->json('data.amount'));
    }

    public function test_invoice_qris_rejects_zero_nominal_with_400(): void
    {
        Sanctum::actingAs($this->userWithRole('admin'));

        $supplier = Supplier::factory()->create(['qris' => self::STATIC_QRIS]);
        $invoice = $this->makeInvoice($supplier, 0);

        $this->getJson("/procurement/invoices/{$invoice->id}/qris")
            ->assertStatus(400)
            ->assertJson(['success' => false]);
    }

    public function test_invoice_qris_rejects_supplier_without_qris(): void
    {
        Sanctum::actingAs($this->userWithRole('admin'));

        $supplier = Supplier::factory()->create(['qris' => null]);
        $invoice = $this->makeInvoice($supplier, 150000);

        $this->getJson("/procurement/invoices/{$invoice->id}/qris")
            ->assertStatus(400)
            ->assertJson(['success' => false]);
    }

    public function test_staff_cannot_generate_invoice_qris(): void
    {
        Sanctum::actingAs($this->userWithRole('staff'));

        $supplier = Supplier::factory()->create(['qris' => self::STATIC_QRIS]);
        $invoice = $this->makeInvoice($supplier, 150000);

        $this->getJson("/procurement/invoices/{$invoice->id}/qris")
            ->assertForbidden();
    }
}
