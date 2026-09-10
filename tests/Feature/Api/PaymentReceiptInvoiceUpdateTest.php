<?php

namespace Tests\Feature\Api;

use App\Models\InvoicePurchase;
use App\Models\PaymentReceipt;
use App\Models\PaymentType;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PaymentReceiptInvoiceUpdateTest extends TestCase
{
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

    private function makeInvoice(int $price = 100000, string $status = '1'): InvoicePurchase
    {
        $store = Store::first() ?? Store::factory()->create();
        $supplier = Supplier::first() ?? Supplier::factory()->create();
        $creator = User::factory()->create();

        return InvoicePurchase::create([
            'store_id' => $store->id,
            'supplier_id' => $supplier->id,
            'date' => now()->toDateString(),
            'taxes' => 0,
            'discounts' => 0,
            'total_price' => $price,
            'payment_status' => $status,
            'order_status' => '1',
            'payment_type_id' => 1,
            'created_by_id' => $creator->id,
        ]);
    }

    public function test_admin_can_update_invoice_payment_receipt_items_and_status(): void
    {
        $admin = $this->userWithRole('admin');
        Sanctum::actingAs($admin);

        $inv1 = $this->makeInvoice(100000, '2');
        $inv2 = $this->makeInvoice(200000, '2');
        $inv3 = $this->makeInvoice(150000, '1');

        $receipt = PaymentReceipt::create([
            'payment_for' => '3',
            'total_amount' => 300000,
            'transfer_amount' => 300000,
            'supplier_id' => $inv1->supplier_id,
            'user_id' => $admin->id,
            'notes' => 'Old notes',
        ]);
        $receipt->invoicePurchases()->attach([$inv1->id, $inv2->id]);

        // Update: remove inv1, keep inv2, add inv3
        $res = $this->postJson("/procurement/payment-receipts/{$receipt->id}", [
            'invoice_ids' => [$inv2->id, $inv3->id],
            'transfer_amount' => 350000,
            'notes' => 'New notes',
        ]);

        $res->assertOk()
            ->assertJson(['success' => true]);

        // inv1 detached => status reverts to 1 (unpaid)
        $this->assertEquals('1', $inv1->fresh()->payment_status);
        // inv2 kept => status remains 2 (paid)
        $this->assertEquals('2', $inv2->fresh()->payment_status);
        // inv3 attached => status becomes 2 (paid)
        $this->assertEquals('2', $inv3->fresh()->payment_status);

        $receipt = $receipt->fresh();
        $this->assertEquals(350000, $receipt->total_amount);
        $this->assertEquals(350000, $receipt->transfer_amount);
        $this->assertEquals('New notes', $receipt->notes);
        $this->assertEquals([$inv2->id, $inv3->id], $receipt->invoicePurchases()->pluck('invoice_purchases.id')->sort()->values()->all());
    }

    public function test_non_admin_cannot_update_payment_receipt(): void
    {
        $staff = $this->userWithRole('staff');
        Sanctum::actingAs($staff);

        $inv1 = $this->makeInvoice(100000, '2');
        $receipt = PaymentReceipt::create([
            'payment_for' => '3',
            'total_amount' => 100000,
            'transfer_amount' => 100000,
            'supplier_id' => $inv1->supplier_id,
            'user_id' => $staff->id,
        ]);
        $receipt->invoicePurchases()->attach([$inv1->id]);

        $res = $this->postJson("/procurement/payment-receipts/{$receipt->id}", [
            'invoice_ids' => [$inv1->id],
            'transfer_amount' => 100000,
        ]);

        $res->assertForbidden();
    }
}
