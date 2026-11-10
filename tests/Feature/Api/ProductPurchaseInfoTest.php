<?php

namespace Tests\Feature\Api;

use App\Models\InvoicePurchase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductPurchaseInfoTest extends TestCase
{
    private function userWithRole(string $role): \App\Models\User
    {
        $user = \App\Models\User::factory()->create();
        $user->assignRole($role);
        return $user;
    }

    /**
     * Produk test dengan FK meniru produk existing (aman untuk FK dev DB).
     */
    private function createTestProduct(string $name): int
    {
        $base = DB::table('products')->where('payment_type_id', '!=', '3')->first();
        if (!$base) {
            $this->markTestSkipped('Need at least 1 product in database');
        }

        $now = now();
        return DB::table('products')->insertGetId([
            'name' => $name,
            'slug' => 'test-' . uniqid(),
            'request' => 1,
            'remaining' => 0,
            'unit_id' => $base->unit_id,
            'material_group_id' => $base->material_group_id,
            'payment_type_id' => $base->payment_type_id,
            'online_category_id' => $base->online_category_id,
            'user_id' => $base->user_id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Rantai lengkap product → request → detail_request → invoice → detail_invoice.
     */
    private function seedPurchaseChain(
        int $productId,
        int $storeId,
        array $invoiceAttrs = [],
        int $quantity = 3,
        int $subtotal = 30000
    ): array {
        $now = now();
        $paymentTypeId = DB::table('products')->where('id', $productId)->value('payment_type_id');

        $requestPurchaseId = DB::table('request_purchases')->insertGetId([
            'store_id' => $storeId,
            'date' => $now->toDateString(),
            'status' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $detailRequestId = DB::table('detail_requests')->insertGetId([
            'product_id' => $productId,
            'quantity_plan' => $quantity,
            'status' => 4,
            'request_purchase_id' => $requestPurchaseId,
            'store_id' => $storeId,
            'payment_type_id' => $paymentTypeId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Supplier dibuat factory (test DB tidak punya supplier) — dicatat untuk cleanup.
        $invoice = InvoicePurchase::factory()->create(array_merge([
            'store_id' => $storeId,
            'payment_status' => '1',
        ], $invoiceAttrs));

        $detailInvoiceId = DB::table('detail_invoices')->insertGetId([
            'invoice_purchase_id' => $invoice->id,
            'detail_request_id' => $detailRequestId,
            'quantity_product' => $quantity,
            'subtotal_invoice' => $subtotal,
            'status' => 3,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [
            'request_purchase_id' => $requestPurchaseId,
            'detail_request_id' => $detailRequestId,
            'invoice_id' => $invoice->id,
            'supplier_id' => $invoice->supplier_id,
            'detail_invoice_id' => $detailInvoiceId,
        ];
    }

    private function cleanupChain(int $productId, array $ids): void
    {
        DB::table('detail_invoices')->whereIn('id', [$ids['detail_invoice_id']])->delete();
        DB::table('invoice_purchases')->whereIn('id', [$ids['invoice_id']])->delete();
        DB::table('detail_requests')->whereIn('id', [$ids['detail_request_id']])->delete();
        DB::table('request_purchases')->whereIn('id', [$ids['request_purchase_id']])->delete();
        DB::table('products')->where('id', $productId)->delete();
        if (!empty($ids['supplier_id'])) {
            DB::table('suppliers')->whereIn('id', [$ids['supplier_id']])->delete();
        }
    }

    public function test_admin_gets_last_purchase_and_in_process(): void
    {
        Sanctum::actingAs($this->userWithRole('admin'));

        $store = \App\Models\Store::first();
        if (!$store) {
            $this->markTestSkipped('Need at least 1 store in database');
        }

        $name = 'TST Info Beli ' . uniqid();
        $productId = $this->createTestProduct($name);
        // Invoice belum lunas 5 unit — satu-satunya, jadi in_process.quantity = 5.
        $ids = $this->seedPurchaseChain($productId, $store->id, ['payment_status' => '1'], 5, 25000);

        try {
            $res = $this->getJson('/procurement/product-purchase-info?q=' . urlencode($name));

            $res->assertOk()->assertJson(['success' => true]);
            $row = collect($res->json('data'))->first(fn ($r) => $r['id'] === $productId);

            $this->assertNotNull($row, 'Produk test harus muncul di response');
            $this->assertNotNull($row['last_purchase']);
            $this->assertEquals(5000, $row['last_purchase']['unit_price']); // 25000 / 5
            $this->assertEquals($store->id, (int) $row['last_purchase']['store_id']);
            $this->assertNotNull($row['last_purchase']['supplier_name']);
            $this->assertNotNull($row['last_purchase']['date']);

            $this->assertNotNull($row['in_process']);
            $this->assertEquals(5, (int) $row['in_process']['quantity']);
            $this->assertEquals(1, $row['in_process']['invoice_count']);

            $this->assertArrayHasKey('pagination', $res->json());
        } finally {
            $this->cleanupChain($productId, $ids);
        }
    }

    public function test_non_admin_roles_are_forbidden(): void
    {
        foreach (['staff', 'sales', 'storage-staff'] as $role) {
            Sanctum::actingAs($this->userWithRole($role));
            $this->getJson('/procurement/product-purchase-info')->assertStatus(403);
        }
    }

    public function test_store_filter_scopes_last_purchase(): void
    {
        Sanctum::actingAs($this->userWithRole('admin'));

        $stores = \App\Models\Store::orderBy('id')->limit(2)->get();
        if ($stores->count() < 2) {
            $this->markTestSkipped('Need at least 2 stores in database');
        }
        [$storeA, $storeB] = $stores->values();

        $name = 'TST Scope Store ' . uniqid();
        $productId = $this->createTestProduct($name);
        // Store A: 1 Okt, harga 1000/unit. Store B: 5 Okt, harga 2000/unit.
        $idsA = $this->seedPurchaseChain($productId, $storeA->id, ['date' => '2026-10-01'], 2, 2000);
        $idsB = $this->seedPurchaseChain($productId, $storeB->id, ['date' => '2026-10-05'], 4, 8000);

        try {
            // Tanpa filter: pembelian terbaru lintas store → store B.
            $res = $this->getJson('/procurement/product-purchase-info?q=' . urlencode($name));
            $row = collect($res->json('data'))->firstWhere('id', $productId);
            $this->assertEquals((int) $storeB->id, (int) $row['last_purchase']['store_id']);
            $this->assertEquals(2000, $row['last_purchase']['unit_price']);

            // Filter store A: pembelian terakhir milik store A.
            $res = $this->getJson('/procurement/product-purchase-info?q=' . urlencode($name) . '&store_id=' . $storeA->id);
            $row = collect($res->json('data'))->firstWhere('id', $productId);
            $this->assertEquals((int) $storeA->id, (int) $row['last_purchase']['store_id']);
            $this->assertEquals(1000, $row['last_purchase']['unit_price']);
        } finally {
            $this->cleanupChain($productId, $idsA);
            $this->cleanupChain($productId, $idsB);
        }
    }

    public function test_in_process_ignores_paid_invoices(): void
    {
        Sanctum::actingAs($this->userWithRole('admin'));

        $store = \App\Models\Store::first();
        if (!$store) {
            $this->markTestSkipped('Need at least 1 store in database');
        }

        $name = 'TST Unpaid Only ' . uniqid();
        $productId = $this->createTestProduct($name);
        $idsUnpaid = $this->seedPurchaseChain($productId, $store->id, ['payment_status' => '1'], 5, 25000);
        $idsPaid = $this->seedPurchaseChain($productId, $store->id, ['payment_status' => '2'], 7, 35000);

        try {
            $res = $this->getJson('/procurement/product-purchase-info?q=' . urlencode($name));
            $row = collect($res->json('data'))->firstWhere('id', $productId);

            // Last purchase boleh dari invoice lunas (2000/7... = 5000),
            // tapi in_process hanya menghitung yang belum lunas.
            $this->assertNotNull($row['last_purchase']);
            $this->assertEquals(5, (int) $row['in_process']['quantity']);
            $this->assertEquals(1, $row['in_process']['invoice_count']);
        } finally {
            $this->cleanupChain($productId, $idsUnpaid);
            $this->cleanupChain($productId, $idsPaid);
        }
    }

    public function test_invalid_invoice_excluded_from_last_purchase(): void
    {
        Sanctum::actingAs($this->userWithRole('admin'));

        $store = \App\Models\Store::first();
        if (!$store) {
            $this->markTestSkipped('Need at least 1 store in database');
        }

        $name = 'TST Skip Invalid ' . uniqid();
        $productId = $this->createTestProduct($name);
        $idsValid = $this->seedPurchaseChain($productId, $store->id, ['date' => '2026-10-01', 'payment_status' => '2'], 2, 2000);
        $idsInvalid = $this->seedPurchaseChain($productId, $store->id, ['date' => '2026-10-10', 'payment_status' => '3'], 9, 9000);

        try {
            $res = $this->getJson('/procurement/product-purchase-info?q=' . urlencode($name));
            $row = collect($res->json('data'))->firstWhere('id', $productId);

            // Invoice Tidak Valid (10 Okt) diabaikan → last purchase = 1 Okt.
            $this->assertEquals('2026-10-01', substr($row['last_purchase']['date'], 0, 10));
            $this->assertEquals(1000, $row['last_purchase']['unit_price']);
        } finally {
            $this->cleanupChain($productId, $idsValid);
            $this->cleanupChain($productId, $idsInvalid);
        }
    }

    public function test_product_without_history_returns_nulls(): void
    {
        Sanctum::actingAs($this->userWithRole('admin'));

        $name = 'TST No History ' . uniqid();
        $productId = $this->createTestProduct($name);

        try {
            $res = $this->getJson('/procurement/product-purchase-info?q=' . urlencode($name));

            $res->assertOk();
            $row = collect($res->json('data'))->firstWhere('id', $productId);
            $this->assertNotNull($row);
            $this->assertNull($row['last_purchase']);
            $this->assertNull($row['in_process']);
        } finally {
            DB::table('products')->where('id', $productId)->delete();
        }
    }
}
