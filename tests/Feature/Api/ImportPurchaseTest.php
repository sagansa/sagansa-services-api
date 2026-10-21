<?php

namespace Tests\Feature\Api;

use App\Models\ImportDocument;
use App\Models\ImportPurchase;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\ImportHppService;
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
            'invoice_number' => 'INV-METE-001',
            'purchase_date' => '2026-10-18',
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
        $unit = Unit::firstOrCreate(['name' => 'Kilogram'], ['unit' => 'kg']);

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
            'file_path' => 'documents/ImportPurchase/'.uniqid().'.webp',
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
        $this->assertSame('INV-METE-001', $import->invoice_number);
        $this->assertSame('2026-10-18', $import->purchase_date->toDateString());
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

    public function test_advance_bypass_documents_clears_document_gate(): void
    {
        $import = $this->makeImport();

        // draft → payment tanpa dokumen.
        $this->postJson("/import-purchases/{$import->id}/advance")
            ->assertOk()
            ->assertJsonPath('data.stage', 'payment');

        // payment → shipment dengan bypass: gerbang dokumen dilewati.
        $this->postJson("/import-purchases/{$import->id}/advance", [
            'bypass_documents' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.stage', 'shipment');

        // Bypass tak berlaku untuk syarat data: import tanpa item tetap
        // tertahan di draft meski bypass dikirim.
        $empty = ImportPurchase::create([
            'supplier_id' => $import->supplier_id,
            'currency' => 'USD',
            'exchange_rate' => 17545,
            'stage' => 'draft',
            'created_by_id' => $import->created_by_id,
        ]);
        $this->createdImportIds[] = $empty->id;
        $res = $this->postJson("/import-purchases/{$empty->id}/advance", [
            'bypass_documents' => true,
        ]);
        $res->assertStatus(422);
        $this->assertStringContainsString(
            'Minimal 1 item',
            $res->json('data.blockers.0.message'),
        );
    }

    public function test_receive_supports_staged_realization_and_new_costs(): void
    {
        $import = $this->makeImport();

        // Realisasi biaya saja sudah boleh diisi sejak draft (draft → payment
        // tidak lagi menjadi gerbang input realisasi).
        $bank = $import->costs->firstWhere(fn ($c) => $c->name === 'Transfer Bank');
        $this->postJson("/import-purchases/{$import->id}/receive", [
            'costs' => [
                ['id' => $bank->id, 'amount_real' => 438625],
                // Baris biaya BARU (notul/denda yang muncul belakangan).
                [
                    'name' => 'Denda Penyimpanan',
                    'stage' => 'customs',
                    'category' => 'other',
                    'amount_real' => 1500000,
                ],
            ],
        ])->assertOk();

        // draft → payment.
        $this->postJson("/import-purchases/{$import->id}/advance")
            ->assertOk()
            ->assertJsonPath('data.stage', 'payment');

        $this->assertSame(438625.0, (float) $bank->fresh()->amount_real);
        $this->assertDatabaseHas('import_costs', [
            'import_purchase_id' => $import->id,
            'name' => 'Denda Penyimpanan',
            'amount_real' => 1500000,
            'amount_estimate' => 0,
        ]);

        // Baris baru tanpa nama ditolak.
        $this->postJson("/import-purchases/{$import->id}/receive", [
            'costs' => [['amount_real' => 5000]],
        ])->assertStatus(422);

        // Jumlah diterima belum boleh diisi di tahap payment.
        $item = $import->items->first();
        $this->postJson("/import-purchases/{$import->id}/receive", [
            'items' => [['id' => $item->id, 'quantity_received' => 100]],
        ])->assertStatus(422);
    }

    public function test_receive_with_payment_tranches_uses_single_bank_total(): void
    {
        $import = $this->makeImport();

        // Tranche pertama wajib bukti; tranche kedua opsional.
        $this->postJson("/import-purchases/{$import->id}/receive", [
            'payments' => [[
                'amount' => 280000000,
                'paid_at' => '2026-10-18',
            ]],
        ])->assertStatus(422)
            ->assertJsonPath('success', false);

        // Urutan array bukan acuan: tanggal paling awal tetap wajib bukti.
        $this->postJson("/import-purchases/{$import->id}/receive", [
            'payments' => [
                [
                    'amount' => 10000000,
                    'paid_at' => '2026-10-20',
                    'proof_path' => 'documents/ImportPurchase/bayar-2.webp',
                ],
                [
                    'amount' => 280000000,
                    'paid_at' => '2026-10-18',
                ],
            ],
        ])->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->postJson("/import-purchases/{$import->id}/receive", [
            'payments' => [
                [
                    'amount' => 280000000,
                    'paid_at' => '2026-10-18',
                    'proof_path' => 'documents/ImportPurchase/bayar-1.webp',
                ],
                [
                    'amount' => 10000000,
                    'paid_at' => '2026-10-20',
                ],
            ],
        ])->assertOk();

        $this->assertSame(2, $import->payments()->count());

        // Net pembayaran = 290.000.000 − nilai barang 266.859.450.
        $derived = $import->costs()->where('source', 'payment')->firstOrFail();
        $this->assertSame('bank', $derived->category);
        $this->assertEqualsWithDelta(23140550, (float) $derived->amount_real, 0.01);

        // Total real = Σ pembayaran + PPh (6.671.486,25). Transfer Bank
        // manual tidak dihitung dua kali.
        $import->refresh();
        $this->assertEqualsWithDelta(296671486.25, (float) $import->total_real, 1.0);

        // Hapus pembayaran → baris derived hilang & real kembali ke input manual.
        $this->postJson("/import-purchases/{$import->id}/receive", [
            'payments' => [],
        ])->assertOk();

        $this->assertSame(0, $import->payments()->count());
        $this->assertSame(0, $import->costs()->where('source', 'payment')->count());
        $this->assertEqualsWithDelta(273969561.25, (float) $import->fresh()->total_real, 1.0);
    }

    public function test_notul_replaces_tax_and_aggregates_penalties(): void
    {
        $import = $this->makeImport();
        $import->update(['stage' => 'customs']);
        $tax = $import->costs()->where('category', 'tax')->firstOrFail();

        $this->postJson("/import-purchases/{$import->id}/receive", [
            'costs' => [
                // Real manual pajak lama harus diabaikan saat notul ada.
                ['id' => $tax->id, 'amount_real' => 999],
            ],
            'notuls' => [
                [
                    'notul_number' => 'NOTUL-001',
                    'notul_date' => '2026-10-19',
                    'pph_amount' => 12000000,
                    'ppn_amount' => 30000000,
                    'penalty_amount' => 1000000,
                    'other_amount' => 500000,
                ],
                [
                    'notul_number' => 'NOTUL-002',
                    'notul_date' => '2026-10-21',
                    'pph_amount' => 13000000,
                    'ppn_amount' => 31000000,
                    'penalty_amount' => 2000000,
                    'other_amount' => 200000,
                ],
            ],
        ])->assertOk();

        $this->assertSame(2, $import->notuls()->count());

        // PPh/PPN memakai notul terbaru; denda & biaya lain dijumlahkan.
        $pph = $import->costs()->where('name', 'PPh Impor (Notul)')->firstOrFail();
        $ppn = $import->costs()->where('name', 'PPN Impor (Notul)')->firstOrFail();
        $penalty = $import->costs()->where('name', 'Denda Administrasi (Notul)')->firstOrFail();
        $other = $import->costs()->where('name', 'Biaya Lain (Notul)')->firstOrFail();

        $this->assertEqualsWithDelta(13000000, (float) $pph->amount_real, 0.01);
        $this->assertEqualsWithDelta(31000000, (float) $ppn->amount_real, 0.01);
        $this->assertEqualsWithDelta(3000000, (float) $penalty->amount_real, 0.01);
        $this->assertEqualsWithDelta(700000, (float) $other->amount_real, 0.01);

        // 266.859.450 + 438.625 + 13jt + 31jt + 3jt + 700rb.
        $this->assertEqualsWithDelta(314998075, (float) $import->fresh()->total_real, 1.0);
    }

    public function test_notul_is_rejected_before_customs_stage(): void
    {
        $import = $this->makeImport();

        $this->postJson("/import-purchases/{$import->id}/receive", [
            'notuls' => [[
                'notul_date' => '2026-10-21',
                'pph_amount' => 1000,
            ]],
        ])->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_payment_proof_and_notul_document_paths_must_use_import_prefix(): void
    {
        $import = $this->makeImport();

        $this->postJson("/import-purchases/{$import->id}/receive", [
            'payments' => [[
                'amount' => 1000,
                'paid_at' => '2026-10-18',
                'proof_path' => 'images/Other/bukti.webp',
            ]],
        ])->assertStatus(422)
            ->assertJsonValidationErrors('payments.0.proof_path');

        $import->update(['stage' => 'customs']);

        $this->postJson("/import-purchases/{$import->id}/receive", [
            'notuls' => [[
                'notul_date' => '2026-10-21',
                'document_path' => 'documents/Other/notul.webp',
            ]],
        ])->assertStatus(422)
            ->assertJsonValidationErrors('notuls.0.document_path');
    }

    public function test_manual_discount_reduces_total(): void
    {
        $import = $this->makeImport();
        $bank = $import->costs->firstWhere(fn ($c) => $c->name === 'Transfer Bank');

        $this->postJson("/import-purchases/{$import->id}/receive", [
            'costs' => [
                ['id' => $bank->id, 'amount_real' => 438625],
                [
                    'name' => 'Diskon Supplier',
                    'stage' => 'payment',
                    'category' => 'discount',
                    'amount_real' => 10000000,
                ],
            ],
        ])->assertOk();

        $this->assertSame(1, $import->costs()->where('category', 'discount')->count());

        // HPP membaca diskon sebagai pengurang.
        $hpp = $this->getJson("/import-purchases/{$import->id}")->assertOk()->json('hpp');
        $row = collect($hpp['costs'])->firstWhere('category', 'discount');
        $this->assertEqualsWithDelta(-10000000, (float) $row['real'], 0.01);

        // 266.859.450 + 438.625 − 10jt + PPh 6.671.486,25.
        $this->assertEqualsWithDelta(263969561.25, (float) $import->fresh()->total_real, 1.0);
    }

    public function test_show_includes_hpp_and_checklist(): void
    {
        $import = $this->makeImport();

        // Staff tidak boleh membaca data impor (seluruh endpoint khusus
        // admin/super_admin).
        Sanctum::actingAs($this->userWithRole('staff'));
        $this->getJson("/import-purchases/{$import->id}")->assertForbidden();
        $this->getJson('/import-purchases')->assertForbidden();

        // Admin melihat detail + hasil hitung + checklist.
        Sanctum::actingAs($this->userWithRole('admin'));
        $res = $this->getJson("/import-purchases/{$import->id}");

        $res->assertOk()
            ->assertJsonPath('data.stage', 'draft')
            ->assertJsonPath('next_stage', 'payment');
        $this->assertNotEmpty($res->json('hpp.items'));
        $this->assertArrayHasKey('payment', $res->json('checklist'));
    }

    public function test_index_stage_counts_is_json_object_when_table_empty(): void
    {
        // Reproduksi crash mobile "List<dynamic> is not a subtype of
        // Map<String, dynamic>?": array PHP kosong dari pluck() diserialisasi
        // menjadi JSON [] (list), bukan {}. Transaksi di-rollback — DB dev
        // dipakai bersama.
        \DB::beginTransaction();
        try {
            ImportPurchase::query()->delete();

            Sanctum::actingAs($this->userWithRole('admin'));

            $counts = $this->getJson('/import-purchases')
                ->assertOk()
                ->json('meta.stage_counts');

            $this->assertIsArray($counts);
            $this->assertFalse(array_is_list($counts), 'stage_counts harus object JSON, bukan list.');
            foreach ([...ImportHppService::STAGES, ImportHppService::STAGE_CANCELLED] as $stage) {
                $this->assertSame(0, $counts[$stage] ?? null, "Tahap {$stage} harus terisi 0.");
            }
        } finally {
            \DB::rollBack();
        }
    }

    public function test_show_checklist_documents_empty_is_json_object(): void
    {
        $import = $this->makeImport(); // draft: tanpa dokumen wajib & belum upload.

        Sanctum::actingAs($this->userWithRole('admin'));

        $res = $this->getJson("/import-purchases/{$import->id}")->assertOk();

        // documents kosong harus {} (object), bukan [] — mobile meng-cast Map.
        $this->assertStringContainsString('"documents":{}', $res->getContent());
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

    public function test_staff_cannot_delete_import(): void
    {
        $import = $this->makeImport();
        Sanctum::actingAs($this->userWithRole('staff'));

        $this->deleteJson("/import-purchases/{$import->id}")->assertForbidden();
        $this->assertNotNull($import->fresh());
    }

    public function test_admin_can_delete_import_with_relations(): void
    {
        $import = $this->makeImport();
        $this->attachDocument($import, 'invoice');

        $this->deleteJson("/import-purchases/{$import->id}")->assertOk();
        $this->assertNull($import->fresh());
        $this->assertSame(0, ImportDocument::where('import_purchase_id', $import->id)->count());
    }

    public function test_finalized_import_cannot_be_deleted(): void
    {
        $import = $this->makeImport();
        $import->update(['stage' => 'finalized']);

        $this->deleteJson("/import-purchases/{$import->id}")->assertStatus(422);
        $this->assertNotNull($import->fresh());
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
