<?php

namespace Tests\Feature\Api;

use App\Models\ImportDocument;
use App\Models\ImportPurchase;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ImportPurchaseTest extends TestCase
{
    /** @var list<int> import yang dibuat test — dibersihkan di tearDown. */
    private array $createdImportIds = [];

    /** @var array<int, array{hpp: mixed, hpp_updated_at: mixed}> kondisi hpp produk sebelum test (untuk dipulihkan). */
    private array $productHppBackup = [];
    /**
     * Fixture ringan mengikuti pola sheet HPP METE: 1 item, kurs 17.545,
     * transfer bank valuta + PPh 2,5% auto.
     */
    private function makeImportPayload(Product $product, Supplier $supplier): array
    {
        return [
            'supplier_id' => $supplier->id,
            'currency' => 'USD',
            'exchange_rate' => 17545,
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 19500,
                    'unit_price_foreign' => 0.78,
                ],
            ],
            'costs' => [
                [
                    'stage' => 'payment',
                    'name' => 'Transfer Bank',
                    'category' => 'bank',
                    'is_foreign_currency' => true,
                    'amount_foreign' => 25,
                    'amount_estimate' => 0,
                ],
                [
                    'stage' => 'customs',
                    'name' => 'PPh 2,5%',
                    'category' => 'tax',
                    'tax_rate' => 2.5,
                    'amount_estimate' => 0,
                ],
            ],
        ];
    }

    private function makeImport(): ImportPurchase
    {
        [$product, $supplier] = $this->ensureFixtures();

        Sanctum::actingAs($this->userWithRole('admin'));

        $res = $this->postJson('/import-purchases', $this->makeImportPayload($product, $supplier));
        $res->assertCreated();

        $import = ImportPurchase::findOrFail($res->json('data.id'));
        $this->createdImportIds[] = $import->id;
        // Pulihkan hpp produk bila test finalisasi menimpanya.
        $this->productHppBackup[$product->id] = [
            'hpp' => $product->hpp,
            'hpp_updated_at' => $product->hpp_updated_at,
        ];

        return $import;
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    /**
     * Pastikan master minimal tersedia (self-seeding) — DB test bisa saja
     * kosong. Produk butuh baris units/material_groups/payment_types/
     * online_categories (NOT NULL pada schema legacy).
     *
     * @return array{0: Product, 1: Supplier}
     */
    private function ensureFixtures(): array
    {
        $unit = \App\Models\Unit::firstOrCreate(['name' => 'Kilogram'], ['unit' => 'kg']);

        $materialGroupId = \DB::table('material_groups')->where('name', 'Bahan Baku')->value('id')
            ?: \DB::table('material_groups')->insertGetId(['name' => 'Bahan Baku', 'status' => 1]);
        $paymentTypeId = \DB::table('payment_types')->where('name', 'Transfer')->value('id')
            ?: \DB::table('payment_types')->insertGetId(['name' => 'Transfer', 'status' => 1]);
        $onlineCategoryId = \DB::table('online_categories')->where('name', 'Umum')->value('id')
            ?: \DB::table('online_categories')->insertGetId(['name' => 'Umum', 'status' => 1]);

        $product = Product::firstOrCreate(
            ['sku' => 'TEST-IMPORT-METE'],
            [
                'name' => 'Mete BB2 (test import)',
                'slug' => 'mete-bb2-test-import',
                'request' => 1,
                'remaining' => 1,
                'unit_id' => $unit->id,
                'material_group_id' => $materialGroupId,
                'payment_type_id' => $paymentTypeId,
                'online_category_id' => $onlineCategoryId,
                'user_id' => 1,
            ],
        );

        $supplier = Supplier::firstOrCreate(
            ['name' => 'Supplier Impor Test'],
            ['status' => 1],
        );

        return [$product, $supplier];
    }

    private function attachDocument(ImportPurchase $import, string $type): void
    {
        ImportDocument::create([
            'import_purchase_id' => $import->id,
            'type' => $type,
            'file_path' => 'documents/ImportPurchase/' . uniqid() . '.webp',
            'uploaded_by' => 1,
        ]);
    }

    public function test_guest_gets_401(): void
    {
        $this->getJson('/import-purchases')->assertUnauthorized();
    }

    public function test_staff_cannot_create_import(): void
    {
        [$product, $supplier] = $this->ensureFixtures();

        Sanctum::actingAs($this->userWithRole('staff'));

        $this->postJson('/import-purchases', $this->makeImportPayload($product, $supplier))
            ->assertForbidden();
    }

    public function test_admin_can_create_import_draft_with_totals_snapshot(): void
    {
        $import = $this->makeImport();

        $this->assertSame('draft', $import->stage);
        $this->assertSame(1, $import->items()->count());
        $this->assertSame(2, $import->costs()->count());

        // Barang 19.500 × 0,78 × 17.545 = 266.859.450; transfer $25 × kurs
        // = 438.625; PPh 2,5% × barang = 6.671.486,25 → total 273.969.561,25.
        $this->assertEqualsWithDelta(273969561.25, (float) $import->total_estimate, 1.0);
    }

    public function test_advance_from_payment_requires_documents(): void
    {
        $import = $this->makeImport();

        // draft → payment: tanpa syarat dokumen.
        $this->postJson("/import-purchases/{$import->id}/advance")
            ->assertOk()
            ->assertJsonPath('data.stage', 'payment');

        // payment → shipment: wajib invoice + bukti pembayaran.
        $res = $this->postJson("/import-purchases/{$import->id}/advance");
        $res->assertStatus(422)
            ->assertJsonPath('success', false);
        $this->assertCount(2, $res->json('data.blockers'));

        $this->attachDocument($import, 'invoice');
        $this->attachDocument($import, 'payment_proof');
        $this->postJson("/import-purchases/{$import->id}/advance")
            ->assertOk()
            ->assertJsonPath('data.stage', 'shipment');
    }

    public function test_show_includes_hpp_and_checklist(): void
    {
        $import = $this->makeImport();
        Sanctum::actingAs($this->userWithRole('staff'));

        $res = $this->getJson("/import-purchases/{$import->id}");

        $res->assertOk()
            ->assertJsonPath('data.stage', 'draft')
            ->assertJsonPath('next_stage', 'payment');
        $this->assertNotEmpty($res->json('hpp.items'));
        $this->assertArrayHasKey('payment', $res->json('checklist'));
    }

    public function test_document_rejects_foreign_path_prefix(): void
    {
        $import = $this->makeImport();

        $this->postJson("/import-purchases/{$import->id}/documents", [
            'type' => 'invoice',
            'file_path' => 'images/InvoicePurchase/abc.webp',
        ])->assertStatus(422);

        $this->postJson("/import-purchases/{$import->id}/documents", [
            'type' => 'invoice',
            'file_path' => 'documents/ImportPurchase/abc.webp',
        ])->assertCreated();
    }

    public function test_full_pipeline_to_finalize_updates_product_hpp(): void
    {
        $import = $this->makeImport();
        $item = $import->items()->first();
        $productId = $item->product_id;

        // payment: invoice + bukti bayar.
        $this->postJson("/import-purchases/{$import->id}/advance")->assertOk();
        $this->attachDocument($import, 'invoice');
        $this->attachDocument($import, 'payment_proof');
        $this->postJson("/import-purchases/{$import->id}/advance")->assertOk();

        // shipment: B/L + packing list.
        $this->attachDocument($import, 'bl');
        $this->attachDocument($import, 'packing_list');
        $this->postJson("/import-purchases/{$import->id}/advance")->assertOk();

        // customs: PIB + bukti pajak.
        $this->attachDocument($import, 'pib');
        $this->attachDocument($import, 'tax_proof');
        $this->postJson("/import-purchases/{$import->id}/advance")->assertOk();

        // arrival → receiving.
        $this->postJson("/import-purchases/{$import->id}/advance")->assertOk();

        // Finalisasi sebelum isi jumlah diterima → 422.
        $this->postJson("/import-purchases/{$import->id}/finalize")->assertStatus(422);

        // Receive: qty diterima 11.700 kg (yield 60%) + real PPh.
        $this->postJson("/import-purchases/{$import->id}/receive", [
            'items' => [
                ['id' => $item->id, 'quantity_received' => 11700],
            ],
            'costs' => [
                ['id' => $import->costs()->where('category', 'tax')->first()->id, 'amount_real' => 11579050],
            ],
        ])->assertOk();

        $res = $this->postJson("/import-purchases/{$import->id}/finalize");
        $res->assertOk()->assertJsonPath('data.stage', 'finalized');

        $item->refresh();
        $this->assertNotNull($item->hpp_real);

        // HPP real per kg: (266.859.450 + 438.625 + 11.579.050) ÷ 11.700.
        $expected = (266859450 + 438625 + 11579050) / 11700;
        $this->assertEqualsWithDelta($expected, (float) $item->hpp_real, 0.01);

        // Kolom products.hpp ter-update (inti fitur: harga satuan per produk).
        $product = Product::find($productId);
        $this->assertEqualsWithDelta($expected, (float) $product->hpp, 0.01);
        $this->assertNotNull($product->hpp_updated_at);

        // Finalisasi ganda → 422.
        $this->postJson("/import-purchases/{$import->id}/finalize")->assertStatus(422);
    }

    public function test_cancel_and_update_guards(): void
    {
        $import = $this->makeImport();

        $this->postJson("/import-purchases/{$import->id}/cancel")->assertOk();
        $this->assertSame('cancelled', $import->fresh()->stage);

        // Cancelled tidak bisa diubah/di-advance.
        $this->postJson("/import-purchases/{$import->id}/advance")->assertStatus(422);
        $this->putJson("/import-purchases/{$import->id}", ['exchange_rate' => 18000])
            ->assertStatus(422);
    }

    protected function tearDown(): void
    {
        // Bersihkan hanya data yang dibuat test ini (DB dev dipakai bersama).
        if ($this->createdImportIds) {
            ImportPurchase::query()->whereIn('id', $this->createdImportIds)->delete();
        }
        foreach ($this->productHppBackup as $productId => $snapshot) {
            Product::query()->whereKey($productId)->update($snapshot);
        }

        parent::tearDown();
    }
}
