<?php

namespace Tests\Unit;

use App\Models\ImportCost;
use App\Models\ImportItem;
use App\Services\ImportHppService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Fixture mengikuti angka asli sheet "HPP METE" (impor mete 19.500 kg BB2,
 * kurs 17.545) — estimasi tervalidasi persis dengan sheet; realisasi memakai
 * kombinasi nilai real per biaya (subtotal barang real pada sheet memuat
 * selisih kurs yang tidak dimodelkan, jadi total real dihitung dari fixture).
 *
 * Semua asersi angka memakai delta: harga valuta (mis. 0,78) tidak
 * representable eksak di float biner sehingga jumlah total membawa epsilon.
 */
class ImportHppServiceTest extends TestCase
{
    private const KURS = 17545.0;
    private const QTY = 19500.0;
    private const PRICE_USD = 0.78;
    private const GOODS = 266859450.0; // 19.500 × 0,78 × 17.545

    private ImportHppService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ImportHppService();
    }

    private function item(float $qty, float $price, ?float $received = null): ImportItem
    {
        return new ImportItem([
            'id' => rand(),
            'product_id' => 1,
            'quantity' => $qty,
            'unit_price_foreign' => $price,
            'quantity_received' => $received,
        ]);
    }

    private function cost(array $attrs): ImportCost
    {
        return new ImportCost(array_merge([
            'id' => rand(),
            'stage' => 'payment',
            'name' => 'Biaya',
            'category' => 'other',
            'tax_rate' => null,
            'is_foreign_currency' => false,
            'amount_foreign' => null,
            'amount_estimate' => 0,
            'amount_real' => null,
        ], $attrs));
    }

    /**
     * Replika penuh sheet: total estimate 324.164.616; HPP/kg 16.624;
     * hpp_pajak = total − seluruh pajak − seluruh biaya bank.
     */
    #[Test]
    public function compute_matches_mete_sheet_estimate(): void
    {
        $items = [$this->item(self::QTY, self::PRICE_USD)];
        $costs = [
            // Bank: transfer $25 + fee Rp35.000 (realisasi sama).
            $this->cost(['name' => 'Transfer Bank', 'category' => 'bank', 'is_foreign_currency' => true, 'amount_foreign' => 25]),
            $this->cost(['name' => 'Bank', 'category' => 'bank', 'amount_estimate' => 35000]),
            $this->cost(['name' => 'Perjalanan', 'amount_estimate' => 200000, 'amount_real' => 200000]),
            $this->cost(['name' => 'Unloading', 'amount_estimate' => 3750000, 'amount_real' => 3350000]),
            // PPh impor: 2,5% & 11% × nilai barang (auto tax_rate).
            $this->cost(['name' => 'PPh 2,5%', 'category' => 'tax', 'tax_rate' => 2.5, 'amount_real' => 11579050]),
            $this->cost(['name' => 'PPh 11%', 'category' => 'tax', 'tax_rate' => 11, 'amount_real' => 55947825]),
            $this->cost(['name' => 'Handling Koja', 'amount_estimate' => 1601730, 'amount_real' => 2031300]),
            $this->cost(['name' => 'D/O', 'amount_estimate' => 2230305, 'amount_real' => 1794990]),
            $this->cost(['name' => 'Repair', 'amount_estimate' => 0, 'amount_real' => 510600]),
            $this->cost(['name' => 'Handling, trucking, SSM', 'amount_estimate' => 9800000, 'amount_real' => 10100000]),
            $this->cost(['name' => 'Storage', 'amount_estimate' => 2478980, 'amount_real' => 2447550]),
            $this->cost(['name' => 'PNBP', 'amount_estimate' => 23000, 'amount_real' => 24500]),
            $this->cost(['name' => 'Lift-off Depo SPM', 'amount_estimate' => 721500, 'amount_real' => 987900]),
        ];

        $result = $this->service->compute($items, $costs, self::KURS);

        $this->assertEqualsWithDelta(self::GOODS, $result['goods_total'], 1.0);

        // Sheet: 266.859.450 + 438.625 + 35.000 + 6.671.486 + 29.354.540
        //        + 200.000 + 3.750.000 + 1.601.730 + 2.230.305 + 9.800.000
        //        + 2.478.980 + 23.000 + 721.500 = 324.164.616.
        $this->assertEqualsWithDelta(324164616.0, $result['total_estimate'], 1.0);

        // Real = barang + real per biaya (transfer & bank tanpa real → estimate).
        $expectedReal = self::GOODS + 438625.0 + 35000.0 + 200000.0 + 3350000.0
            + 11579050.0 + 55947825.0 + 2031300.0 + 1794990.0 + 510600.0
            + 10100000.0 + 2447550.0 + 24500.0 + 987900.0;
        $this->assertEqualsWithDelta($expectedReal, $result['total_real'], 1.0);

        // HPP/kg (dibagi 19.500 kg) — sheet: 16.624 (est); real dari fixture.
        $this->assertEqualsWithDelta(16623.83, $result['items'][0]['hpp_estimate_unit'], 0.01);
        $this->assertEqualsWithDelta($expectedReal / 19500, $result['items'][0]['hpp_real_unit'], 0.01);

        // hpp_pajak = total est − PPh 2,5% (6.671.486,25) − PPh 11%
        // (29.354.539,50) − bank (473.625) = 287.664.965,25.
        $this->assertEqualsWithDelta(287664965.25, $result['hpp_pajak'], 1.0);
    }

    #[Test]
    public function hpp_real_unit_divides_by_quantity_received(): void
    {
        // Yield 60%: diterima 11.700 dari 19.500 kg — HPP real per kg naik.
        $items = [$this->item(self::QTY, self::PRICE_USD, 11700.0)];
        $costs = [
            $this->cost(['category' => 'tax', 'tax_rate' => 2.5]),
            $this->cost(['amount_estimate' => 10000000, 'amount_real' => 11000000]),
        ];

        $result = $this->service->compute($items, $costs, self::KURS);

        $estimate = self::GOODS * 1.025 + 10000000; // pajak auto 2,5% × barang
        $real = self::GOODS + (self::GOODS * 0.025) + 11000000; // pajak tanpa real → estimate
        $this->assertEqualsWithDelta($estimate, $result['total_estimate'], 1.0);
        $this->assertEqualsWithDelta($real, $result['total_real'], 1.0);
        $this->assertEqualsWithDelta($estimate / 19500, $result['items'][0]['hpp_estimate_unit'], 0.001);
        $this->assertEqualsWithDelta($real / 11700, $result['items'][0]['hpp_real_unit'], 0.001);
    }

    #[Test]
    public function shared_costs_allocated_by_weight_and_taxes_by_value(): void
    {
        // 2 item: BB2 12.000 kg × $0,80 = $9.600; BB3 8.000 kg × $0,60 = $4.800.
        // Bobot 60/40, nilai 66,67/33,33.
        $items = [
            $this->item(12000, 0.80, 12000),
            $this->item(8000, 0.60, 8000),
        ];
        $costs = [
            // Logistik 10 jt → 6 jt / 4 jt (bobot).
            $this->cost(['amount_estimate' => 10000000]),
            // Pajak 10% dari nilai barang total $14.400 × kurs → 2/3 dan 1/3 (nilai).
            $this->cost(['category' => 'tax', 'tax_rate' => 10]),
        ];

        $result = $this->service->compute($items, $costs, self::KURS);

        $goods1 = 12000 * 0.80 * self::KURS; // 168.432.000
        $goods2 = 8000 * 0.60 * self::KURS;  // 84.216.000
        $taxTotal = ($goods1 + $goods2) * 0.10;

        $this->assertEqualsWithDelta($goods1 + 6000000 + $taxTotal * (2 / 3), $result['items'][0]['allocated_estimate'], 1.0);
        $this->assertEqualsWithDelta($goods2 + 4000000 + $taxTotal * (1 / 3), $result['items'][1]['allocated_estimate'], 1.0);
        $this->assertEqualsWithDelta($result['items'][0]['allocated_estimate'] / 12000, $result['items'][0]['hpp_estimate_unit'], 0.001);
    }

    #[Test]
    public function foreign_currency_cost_converted_via_exchange_rate(): void
    {
        $items = [$this->item(1000, 1.0)];
        $costs = [
            $this->cost(['category' => 'bank', 'is_foreign_currency' => true, 'amount_foreign' => 25]),
        ];

        $result = $this->service->compute($items, $costs, self::KURS);

        $this->assertEqualsWithDelta(438625.0, $result['cost_total_estimate'], 0.01); // 25 × 17.545
        $this->assertEqualsWithDelta(438625.0, $result['bank_total_estimate'], 0.01);
    }

    #[Test]
    public function tax_amount_real_overrides_rate_computation(): void
    {
        $items = [$this->item(self::QTY, self::PRICE_USD)];
        $costs = [
            $this->cost(['category' => 'tax', 'tax_rate' => 2.5, 'amount_real' => 11579050]),
        ];

        $result = $this->service->compute($items, $costs, self::KURS);

        // Estimate: 2,5% × nilai barang; real: angka manual.
        $this->assertEqualsWithDelta(6671486.25, $result['tax_total_estimate'], 1.0);
        $this->assertEqualsWithDelta(11579050.0, $result['tax_total_real'], 0.01);
    }

    #[Test]
    public function advance_blockers_follow_stage_gates(): void
    {
        $noDocs = [];
        $withInvoice = ['invoice', 'payment_proof'];

        // Draft tanpa item/kurs → 2 blocker data.
        $blockers = $this->service->blockersForAdvance('draft', $noDocs, [], 0.0);
        $this->assertCount(2, $blockers);

        // Draft lengkap → bebas blocker.
        $this->assertSame(
            [],
            $this->service->blockersForAdvance('draft', $noDocs, [['quantity' => 1, 'quantity_received' => null]], self::KURS)
        );

        // Payment tanpa bukti → blocker dokumen.
        $blockers = $this->service->blockersForAdvance('payment', $noDocs, [], self::KURS);
        $this->assertCount(2, $blockers);
        $this->assertSame('document', $blockers[0]['kind']);
        $this->assertSame([], $this->service->blockersForAdvance('payment', $withInvoice, [], self::KURS));

        // Shipment dengan B/L ter-upload → kurang packing list saja.
        $blockers = $this->service->blockersForAdvance('shipment', ['bl'], [], self::KURS);
        $this->assertCount(1, $blockers);
        $this->assertStringContainsString('Packing List', $blockers[0]['message']);

        // Customs dengan PIB ter-upload → kurang bukti setor pajak saja.
        $blockers = $this->service->blockersForAdvance('customs', ['pib'], [], self::KURS);
        $this->assertCount(1, $blockers);
        $this->assertStringContainsString('Bukti Setor Pajak', $blockers[0]['message']);

        // Arrival tanpa syarat; receiving dialuikan ke finalize.
        $this->assertSame([], $this->service->blockersForAdvance('arrival', [], [], self::KURS));
        $receiving = $this->service->blockersForAdvance('receiving', [], [], self::KURS);
        $this->assertSame('state', $receiving[0]['kind']);
        $this->assertStringContainsString('finalize', $receiving[0]['message']);

        // Terminal states.
        $this->assertNotSame([], $this->service->blockersForAdvance('finalized', [], [], self::KURS));
        $this->assertNotSame([], $this->service->blockersForAdvance('cancelled', [], [], self::KURS));
    }

    #[Test]
    public function missing_documents_lists_required_not_uploaded(): void
    {
        $this->assertSame(
            ['invoice', 'payment_proof'],
            $this->service->missingDocuments('payment', [])
        );
        $this->assertSame([], $this->service->missingDocuments('payment', ['invoice', 'payment_proof']));
        $this->assertSame([], $this->service->missingDocuments('arrival', []));
    }

    #[Test]
    public function bypass_documents_skips_only_document_blockers(): void
    {
        // Payment tanpa dokumen + bypass → bebas blocker.
        $this->assertSame(
            [],
            $this->service->blockersForAdvance('payment', [], [], self::KURS, true)
        );

        // Data draft tetap memblokir meski bypass (item kosong + kurs 0).
        $this->assertCount(
            2,
            $this->service->blockersForAdvance('draft', [], [], 0.0, true)
        );

        // State terminal tetap memblokir meski bypass.
        $this->assertNotSame(
            [],
            $this->service->blockersForAdvance('finalized', [], [], self::KURS, true)
        );
    }

    #[Test]
    public function finalize_blockers_require_received_quantity(): void
    {
        $this->assertCount(1, $this->service->blockersForFinalize([
            ['quantity' => 100, 'quantity_received' => 90],
            ['quantity' => 50, 'quantity_received' => null],
        ]));
        $this->assertSame([], $this->service->blockersForFinalize([
            ['quantity' => 100, 'quantity_received' => 90],
        ]));
    }

    #[Test]
    public function next_stage_walks_the_pipeline(): void
    {
        $s = $this->service;
        $this->assertSame('payment', $s->nextStage('draft'));
        $this->assertSame('receiving', $s->nextStage('arrival'));
        $this->assertSame('finalized', $s->nextStage('receiving'));
        $this->assertNull($s->nextStage('finalized'));
        $this->assertNull($s->nextStage('cancelled'));
    }

    #[Test]
    public function stage_checklist_marks_required_and_present_documents(): void
    {
        $checklist = $this->service->stageChecklist('payment', ['invoice']);

        $this->assertSame('done', $checklist['draft']['state']);
        $this->assertSame('current', $checklist['payment']['state']);
        $this->assertSame('upcoming', $checklist['customs']['state']);

        $paymentDocs = $checklist['payment']['documents'];
        $this->assertTrue($paymentDocs['invoice']['required']);
        $this->assertTrue($paymentDocs['invoice']['present']);
        $this->assertTrue($paymentDocs['payment_proof']['required']);
        $this->assertFalse($paymentDocs['payment_proof']['present']);
    }
}
