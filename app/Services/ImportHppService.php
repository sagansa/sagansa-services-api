<?php

namespace App\Services;

use App\Models\ImportCost;
use App\Models\ImportItem;

/**
 * Perhitungan HPP impor — replika logika sheet "HPP METE".
 *
 * Murni (tanpa akses DB): menerima koleksi ImportItem/ImportCost yang sudah
 * di-load (atau instance in-memory untuk unit test) plus kurs, mengembalikan
 * total estimate/real, alokasi biaya per item, dan HPP satuan per item.
 *
 * Rumus:
 * - Nilai barang item (IDR) = quantity × unit_price_foreign × kurs.
 * - Biaya valuta (is_foreign_currency) = amount_foreign × kurs.
 * - Pajak (category=tax + tax_rate) = tax_rate% × Σ nilai barang; amount_real
 *   meng-override hasil hitung; tanpa tax_rate memakai amount manual.
 * - Alokasi biaya ke item: non-pajak proporsional quantity (bobot), pajak
 *   proporsional nilai barang.
 * - HPP satuan = (nilai barang + alokasi) ÷ quantity (estimate) atau ÷
 *   quantity_received (real; fallback quantity bila belum diisi).
 * - hpp_pajak = total_estimate − Σ pajak − Σ biaya bank (metrik sheet).
 */
class ImportHppService
{
    public const STAGES = [
        'draft',
        'payment',
        'shipment',
        'customs',
        'arrival',
        'receiving',
        'finalized',
    ];

    public const STAGE_CANCELLED = 'cancelled';

    public const DOCUMENT_TYPES = [
        'po',
        'invoice',
        'payment_proof',
        'packing_list',
        'bl',
        'coo',
        'insurance',
        'pib',
        'tax_proof',
        'notul',
        'delivery_order',
        'unloading_receipt',
        'port_receipt',
        'other',
    ];

    /**
     * Dokumen wajib untuk MENYELESAIKAN (advance dari) suatu tahap.
     * `receiving` tidak ada di sini: tahap terakhir diselesaikan lewat
     * endpoint finalize, bukan advance.
     */
    public const REQUIRED_DOCUMENTS = [
        'draft' => [],
        'payment' => ['invoice', 'payment_proof'],
        'shipment' => ['bl', 'packing_list'],
        'customs' => ['pib', 'tax_proof'],
        'arrival' => [],
    ];

    public const STAGE_LABELS = [
        'draft' => 'Draft',
        'payment' => 'Pembayaran',
        'shipment' => 'Pengiriman',
        'customs' => 'Kepabeanan',
        'arrival' => 'Kedatangan',
        'receiving' => 'Penerimaan',
        'finalized' => 'Final',
        'cancelled' => 'Dibatalkan',
    ];

    public const DOCUMENT_LABELS = [
        'po' => 'Purchase Order',
        'invoice' => 'Invoice',
        'payment_proof' => 'Bukti Pembayaran',
        'packing_list' => 'Packing List',
        'bl' => 'Bill of Lading / AWB',
        'coo' => 'Certificate of Origin',
        'insurance' => 'Asuransi Kargo',
        'pib' => 'PIB / SPPB',
        'tax_proof' => 'Bukti Setor Pajak',
        'notul' => 'NOTUL',
        'delivery_order' => 'Delivery Order',
        'unloading_receipt' => 'Kuitansi Bongkar',
        'port_receipt' => 'Kuitansi Pelabuhan',
        'other' => 'Lainnya',
    ];

    /**
     * Hitung HPP lengkap.
     *
     * @param  iterable<ImportItem>  $items
     * @param  iterable<ImportCost>  $costs
     * @return array{
     *   exchange_rate: float,
     *   goods_total: float,
     *   items: list<array<string, float|int|null>>,
     *   costs: list<array<string, float|int|string|null>>,
     *   cost_total_estimate: float, cost_total_real: float,
     *   tax_total_estimate: float, tax_total_real: float,
     *   bank_total_estimate: float, bank_total_real: float,
     *   total_estimate: float, total_real: float,
     *   variance: float, variance_percent: float|null,
     *   hpp_pajak: float
     * }
     */
    public function compute(iterable $items, iterable $costs, float $exchangeRate): array
    {
        $items = collect($items)->values();
        $costs = collect($costs)->values();

        // --- Nilai barang per item ---
        $itemRows = $items->map(function (ImportItem $item) use ($exchangeRate) {
            $goodsValue = $item->quantity * $item->unit_price_foreign * $exchangeRate;

            return [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'quantity' => $item->quantity,
                'unit_price_foreign' => $item->unit_price_foreign,
                'goods_value' => $goodsValue,
                'quantity_received' => $item->quantity_received,
            ];
        });

        $goodsTotal = $itemRows->sum('goods_value');
        $totalQuantity = $items->sum('quantity');

        // Pembayaran/notul yang ada menggantikan real manual bank/diskon/pajak;
        // estimasi manual tetap dipakai untuk kolom estimate.
        $hasPaymentCosts = $costs->contains(fn (ImportCost $cost) => $cost->source === 'payment');
        $hasNotulCosts = $costs->contains(fn (ImportCost $cost) => $cost->source === 'notul');

        // --- Biaya (estimate & real, dalam IDR) ---
        $costRows = $costs->map(function (ImportCost $cost) use (
            $exchangeRate, $goodsTotal, $hasPaymentCosts, $hasNotulCosts
        ) {
            $isDerived = $cost->source !== null;
            $estimate = $isDerived ? 0 : $this->costEstimateIdr($cost, $exchangeRate, $goodsTotal);

            $suppressReal = ! $isDerived && (
                ($hasPaymentCosts && in_array($cost->category, ['bank', 'discount'], true))
                || ($hasNotulCosts && $cost->category === 'tax')
            );
            $real = $suppressReal ? 0 : $this->costRealIdr($cost, $exchangeRate, $goodsTotal);

            return [
                'id' => $cost->id,
                'name' => $cost->name,
                'stage' => $cost->stage,
                'category' => $cost->category,
                'source' => $cost->source,
                'tax_rate' => $cost->tax_rate,
                'estimate' => $estimate,
                'real' => $real,
            ];
        });

        $costTotalEstimate = $costRows->sum('estimate');
        $costTotalReal = $costRows->sum('real');
        $taxTotalEstimate = $costRows->where('category', 'tax')->sum('estimate');
        $taxTotalReal = $costRows->where('category', 'tax')->sum('real');
        $bankTotalEstimate = $costRows->where('category', 'bank')->sum('estimate');
        $bankTotalReal = $costRows->where('category', 'bank')->sum('real');

        // Biaya non-pajak dialokasikan proporsional bobot (quantity);
        // pajak proporsional nilai barang.
        $nonTaxEstimate = $costTotalEstimate - $taxTotalEstimate;
        $nonTaxReal = $costTotalReal - $taxTotalReal;

        $totalEstimate = $goodsTotal + $costTotalEstimate;
        $totalReal = $goodsTotal + $costTotalReal;

        $itemsOut = $itemRows->map(function (array $row) use (
            $totalQuantity, $goodsTotal, $nonTaxEstimate, $nonTaxReal, $taxTotalEstimate, $taxTotalReal
        ) {
            $qtyShare = $totalQuantity > 0 ? $row['quantity'] / $totalQuantity : 0;
            $valueShare = $goodsTotal > 0 ? $row['goods_value'] / $goodsTotal : 0;

            $allocatedEstimate = $row['goods_value']
                + $nonTaxEstimate * $qtyShare
                + $taxTotalEstimate * $valueShare;
            $allocatedReal = $row['goods_value']
                + $nonTaxReal * $qtyShare
                + $taxTotalReal * $valueShare;

            // Pembagi real: qty diterima; fallback qty beli bila belum diisi.
            $realDivisor = $row['quantity_received'] > 0 ? $row['quantity_received'] : $row['quantity'];

            $row['qty_share'] = $qtyShare;
            $row['value_share'] = $valueShare;
            $row['allocated_estimate'] = $allocatedEstimate;
            $row['allocated_real'] = $allocatedReal;
            $row['hpp_estimate_unit'] = $row['quantity'] > 0 ? $allocatedEstimate / $row['quantity'] : 0;
            $row['hpp_real_unit'] = $realDivisor > 0 ? $allocatedReal / $realDivisor : 0;

            return $row;
        })->values()->all();

        return [
            'exchange_rate' => $exchangeRate,
            'goods_total' => $goodsTotal,
            'items' => $itemsOut,
            'costs' => $costRows->values()->all(),
            'cost_total_estimate' => $costTotalEstimate,
            'cost_total_real' => $costTotalReal,
            'tax_total_estimate' => $taxTotalEstimate,
            'tax_total_real' => $taxTotalReal,
            'bank_total_estimate' => $bankTotalEstimate,
            'bank_total_real' => $bankTotalReal,
            'total_estimate' => $totalEstimate,
            'total_real' => $totalReal,
            'variance' => $totalReal - $totalEstimate,
            'variance_percent' => $totalEstimate > 0 ? ($totalReal / $totalEstimate - 1) * 100 : null,
            // Metrik tambahan sheet: total estimate dikurangi pajak & biaya bank.
            'hpp_pajak' => $totalEstimate - $taxTotalEstimate - $bankTotalEstimate,
        ];
    }

    private function costEstimateIdr(ImportCost $cost, float $exchangeRate, float $goodsTotal): float
    {
        if ($cost->category === 'tax' && $cost->tax_rate !== null) {
            return $cost->tax_rate / 100 * $goodsTotal;
        }

        if ($cost->is_foreign_currency && $cost->amount_foreign !== null) {
            $value = $cost->amount_foreign * $exchangeRate;
        } else {
            $value = (float) $cost->amount_estimate;
        }

        // Diskon mengurangi total, bukan menambah.
        return $cost->category === 'discount' ? -abs($value) : $value;
    }

    private function costRealIdr(ImportCost $cost, float $exchangeRate, float $goodsTotal): float
    {
        if ($cost->source !== null) {
            return (float) ($cost->amount_real ?? 0);
        }

        if ($cost->amount_real !== null) {
            $value = (float) $cost->amount_real;

            return $cost->category === 'discount' ? -abs($value) : $value;
        }

        return $this->costEstimateIdr($cost, $exchangeRate, $goodsTotal);
    }

    /**
     * Tahap berikutnya dari alur; null bila tidak ada (finalized/cancelled/
     * nilai tak dikenal).
     */
    public function nextStage(string $stage): ?string
    {
        $idx = array_search($stage, self::STAGES, true);
        if ($idx === false || $idx >= count(self::STAGES) - 1) {
            return null;
        }

        return self::STAGES[$idx + 1];
    }

    /**
     * Dokumen wajib tahap ini yang belum ter-upload (kode tipe).
     *
     * @param  array<int, string>  $uploadedDocumentTypes
     * @return list<string>
     */
    public function missingDocuments(string $stage, array $uploadedDocumentTypes): array
    {
        $missing = [];
        foreach (self::REQUIRED_DOCUMENTS[$stage] ?? [] as $type) {
            if (! in_array($type, $uploadedDocumentTypes, true)) {
                $missing[] = $type;
            }
        }

        return $missing;
    }

    /**
     * Halangan untuk advance dari tahap saat ini.
     *
     * @param  array<int, string>  $uploadedDocumentTypes  tipe dokumen yang sudah ada (unik)
     * @param  array<int, array{quantity: float, quantity_received: float|null}>  $items
     * @param  bool  $bypassDocuments  lewati gerbang dokumen (syarat data/state tetap)
     * @return list<array{kind: string, message: string}>
     */
    public function blockersForAdvance(
        string $stage,
        array $uploadedDocumentTypes,
        array $items,
        float $exchangeRate,
        bool $bypassDocuments = false,
    ): array {
        $blockers = [];

        if ($stage === self::STAGE_CANCELLED || $stage === 'finalized') {
            return [['kind' => 'state', 'message' => 'Proses impor ini sudah selesai/dibatalkan.']];
        }

        $next = $this->nextStage($stage);
        // finalized tak bisa di-advance; transisi receiving→finalized harus
        // lewat endpoint finalize (yang menulis HPP ke products).
        if ($next === null || $next === 'finalized') {
            return [['kind' => 'state', 'message' => 'Tahap terakhir — gunakan endpoint finalize.']];
        }

        if ($stage === 'draft') {
            if (count($items) === 0) {
                $blockers[] = ['kind' => 'data', 'message' => 'Minimal 1 item produk harus diisi.'];
            }
            if ($exchangeRate <= 0) {
                $blockers[] = ['kind' => 'data', 'message' => 'Kurs (exchange_rate) harus lebih dari 0.'];
            }
        }

        if (! $bypassDocuments) {
            foreach ($this->missingDocuments($stage, $uploadedDocumentTypes) as $type) {
                $blockers[] = [
                    'kind' => 'document',
                    'message' => 'Dokumen wajib belum diupload: '.(self::DOCUMENT_LABELS[$type] ?? $type),
                ];
            }
        }

        return $blockers;
    }

    /**
     * Halangan untuk finalize (dari tahap receiving): semua item harus sudah
     * punya jumlah diterima > 0.
     *
     * @param  array<int, array{quantity: float, quantity_received: float|null}>  $items
     * @return list<array{kind: string, message: string}>
     */
    public function blockersForFinalize(array $items): array
    {
        $missing = 0;
        foreach ($items as $item) {
            if (($item['quantity_received'] ?? 0) <= 0) {
                $missing++;
            }
        }
        if ($missing > 0) {
            return [[
                'kind' => 'data',
                'message' => "{$missing} item belum mengisi jumlah diterima (quantity_received).",
            ]];
        }

        return [];
    }

    /**
     * Checklist kelengkapan per tahap untuk UI detail.
     *
     * @param  array<int, string>  $uploadedDocumentTypes
     * @return array<string, array{label: string, documents: array<string, array{label: string, required: bool, present: bool}>, state: string}>
     *                                                                                                                                           state: done | current | upcoming
     */
    public function stageChecklist(string $currentStage, array $uploadedDocumentTypes): array
    {
        $currentIdx = array_search($currentStage, self::STAGES, true);

        $out = [];
        foreach (self::STAGES as $i => $stage) {
            $docs = [];
            // Dokumen wajib tahap ini + tipe opsional yang sudah ter-upload.
            foreach (self::DOCUMENT_TYPES as $type) {
                $required = in_array($type, self::REQUIRED_DOCUMENTS[$stage] ?? [], true);
                $present = in_array($type, $uploadedDocumentTypes, true);
                if ($required || $present) {
                    $docs[$type] = [
                        'label' => self::DOCUMENT_LABELS[$type],
                        'required' => $required,
                        'present' => $present,
                    ];
                }
            }

            $out[$stage] = [
                'label' => self::STAGE_LABELS[$stage],
                // Array kosong menjadi JSON [] (list); client mobile meng-cast
                // ke Map, jadi tahap tanpa dokumen dikirim sebagai {}.
                'documents' => $docs === [] ? new \stdClass : $docs,
                'state' => $currentIdx === false
                    ? 'upcoming'
                    : ($i < $currentIdx ? 'done' : ($i === $currentIdx ? 'current' : 'upcoming')),
            ];
        }

        return $out;
    }
}
