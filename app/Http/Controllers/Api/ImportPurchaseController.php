<?php

namespace App\Http\Controllers\Api;

use App\Contracts\ImageStorageContract;
use App\Http\Controllers\Controller;
use App\Models\ImportCost;
use App\Models\ImportDocument;
use App\Models\ImportPurchase;
use App\Services\ImportHppService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

use function Illuminate\Support\defer;

/**
 * Impor barang: tahapan fixed + kelengkapan dokumen + HPP per produk.
 *
 * SELURUH endpoint (baca & tulis) khusus admin/super_admin — guard
 * in-controller, pola ProcurementController. Di UI mobile, menu hanya
 * dimunculkan untuk role admin.
 */
class ImportPurchaseController extends Controller
{
    public function __construct(
        protected ImportHppService $hpp,
        protected ImageStorageContract $storage,
    ) {}

    private function isAdmin(Request $request): bool
    {
        $user = $request->user();

        return $user && ($user->hasRole('admin') || $user->hasRole('super_admin'));
    }

    private function forbidden(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Hanya admin/super_admin yang dapat melakukan aksi ini.',
        ], 403);
    }

    /* ----------------------------------------------------------------- READ */

    public function index(Request $request)
    {
        if (! $this->isAdmin($request)) {
            return $this->forbidden();
        }

        $query = ImportPurchase::with(['supplier', 'createdBy', 'items.product.unit', 'costs'])
            ->withCount('documents')
            ->withSum('payments as payments_total', 'amount');

        if ($stage = $request->query('stage')) {
            $query->where('stage', $stage);
        }
        if ($supplierId = $request->query('supplier_id')) {
            $query->where('supplier_id', $supplierId);
        }
        if ($from = $request->query('date_from')) {
            $query->whereDate('created_at', '>=', $from);
        }
        if ($to = $request->query('date_to')) {
            $query->whereDate('created_at', '<=', $to);
        }

        $perPage = $request->integer('per_page', 20);
        $paginated = $query->orderByDesc('id')->paginate($perPage);

        // Harga satuan per produk untuk kartu list: realisasi bila barang
        // sudah diterima/final, selain itu estimasi. Nilai dihitung dari
        // item + biaya (tidak mengubah snapshot hpp di DB).
        $rows = collect($paginated->items())
            ->map(fn (ImportPurchase $import) => $this->withUnitPrices($import))
            ->values()
            ->all();

        return response()->json([
            'success' => true,
            'data' => $rows,
            'meta' => [
                'stage_counts' => $this->stageCounts(),
            ],
            'pagination' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
        ]);
    }

    public function summary(Request $request)
    {
        if (! $this->isAdmin($request)) {
            return $this->forbidden();
        }

        return response()->json([
            'success' => true,
            'data' => [
                'stage_counts' => $this->stageCounts(),
                'stages' => ImportHppService::STAGES,
                'stage_labels' => ImportHppService::STAGE_LABELS,
                'document_types' => ImportHppService::DOCUMENT_TYPES,
                'document_labels' => ImportHppService::DOCUMENT_LABELS,
                'required_documents' => ImportHppService::REQUIRED_DOCUMENTS,
            ],
        ]);
    }

    /**
     * Trend harga satuan seluruh produk dari semua proses impor (kecuali
     * dibatalkan). Tiap titik membawa harga valuta asli (USD) dan harga
     * rupiah per satuan: HPP realisasi bila barang sudah diterima/final,
     * selain itu estimasi.
     */
    public function priceTrends(Request $request)
    {
        if (! $this->isAdmin($request)) {
            return $this->forbidden();
        }

        $imports = ImportPurchase::with(['supplier', 'items.product.unit', 'costs'])
            ->where('stage', '!=', ImportHppService::STAGE_CANCELLED)
            ->orderBy('purchase_date')
            ->orderBy('id')
            ->get();

        $products = [];

        foreach ($imports as $import) {
            $computed = $this->computeFor($import);
            $computedItems = collect($computed['items'])->keyBy('id');
            $hasRealization = $this->importHasRealization($import);

            foreach ($import->items as $item) {
                $row = $computedItems->get($item->id);
                if (! $row) {
                    continue;
                }

                $productId = (int) $item->product_id;
                $product = $item->product;
                $isRealized = $hasRealization;
                $unitPriceIdr = $isRealized
                    ? (float) $row['hpp_real_unit']
                    : (float) $row['hpp_estimate_unit'];

                if (! isset($products[$productId])) {
                    $products[$productId] = [
                        'product_id' => $productId,
                        'product_name' => $product?->name ?? 'Produk #'.$productId,
                        'unit_name' => $product?->unit?->unit ?? '',
                        'currency' => $import->currency,
                        'points' => [],
                    ];
                }

                $products[$productId]['points'][] = [
                    'import_id' => $import->id,
                    'date' => $import->purchase_date?->toDateString()
                        ?? $import->created_at?->toDateString(),
                    'supplier_name' => $import->supplier?->name,
                    'currency' => $import->currency,
                    'exchange_rate' => (float) $import->exchange_rate,
                    'quantity' => (float) $item->quantity,
                    'quantity_received' => $item->quantity_received === null
                        ? null
                        : (float) $item->quantity_received,
                    'unit_price_foreign' => round((float) $item->unit_price_foreign, 4),
                    'unit_price_idr' => round($unitPriceIdr, 2),
                    'unit_price_idr_estimate' => round((float) $row['hpp_estimate_unit'], 2),
                    'unit_price_idr_real' => round((float) $row['hpp_real_unit'], 2),
                    'is_realized' => $isRealized,
                ];
            }
        }

        $data = collect($products)
            ->map(function (array $product) {
                usort($product['points'], function (array $a, array $b) {
                    return [$a['date'], $a['import_id']] <=> [$b['date'], $b['import_id']];
                });

                return $product;
            })
            ->sortBy('product_name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    private function stageCounts(): array
    {
        $counts = ImportPurchase::query()
            ->select('stage', DB::raw('count(*) as total'))
            ->groupBy('stage')
            ->pluck('total', 'stage')
            ->all();

        // Array PHP kosong diserialisasi menjadi JSON [] (list), bukan {},
        // dan client mobile meng-cast nilai ini ke Map. Prefill seluruh
        // tahap dengan 0 agar bentuknya selalu object.
        return array_merge(
            array_fill_keys([...ImportHppService::STAGES, ImportHppService::STAGE_CANCELLED], 0),
            $counts,
        );
    }

    public function show($id, Request $request)
    {
        if (! $this->isAdmin($request)) {
            return $this->forbidden();
        }

        $import = ImportPurchase::with([
            'supplier', 'store', 'createdBy',
            'items.product.unit', 'costs', 'documents', 'payments', 'notuls',
        ])->find($id);

        if (! $import) {
            return response()->json([
                'success' => false,
                'message' => 'Proses impor tidak ditemukan.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $import,
            'hpp' => $this->computeFor($import),
            'checklist' => $this->hpp->stageChecklist($import->stage, $import->uploadedDocumentTypes()),
            'next_stage' => $this->hpp->nextStage($import->stage),
        ]);
    }

    /* ---------------------------------------------------------------- WRITE */

    public function store(Request $request)
    {
        if (! $this->isAdmin($request)) {
            return $this->forbidden();
        }

        $validated = $this->validateImportPayload($request);

        $import = DB::transaction(function () use ($request, $validated) {
            $import = ImportPurchase::create([
                'supplier_id' => $validated['supplier_id'],
                'store_id' => $validated['store_id'] ?? null,
                'currency' => $validated['currency'] ?? 'USD',
                'exchange_rate' => $validated['exchange_rate'],
                'invoice_number' => $validated['invoice_number'] ?? null,
                'purchase_date' => $validated['purchase_date'] ?? now()->toDateString(),
                'stage' => 'draft',
                'notes' => $validated['notes'] ?? null,
                'created_by_id' => $request->user()->id,
            ]);

            $this->syncItems($import, $validated['items']);
            $this->syncCosts($import, $validated['costs'] ?? []);
            $this->syncDerivedCosts($import);

            return $import;
        });

        $this->refreshTotals($import);

        return response()->json([
            'success' => true,
            'message' => 'Proses impor berhasil dibuat (draft).',
            'data' => $import->load(['supplier', 'items.product.unit', 'costs', 'payments', 'notuls']),
        ], 201);
    }

    public function update($id, Request $request)
    {
        if (! $this->isAdmin($request)) {
            return $this->forbidden();
        }

        $import = ImportPurchase::find($id);
        if (! $import) {
            return response()->json(['success' => false, 'message' => 'Proses impor tidak ditemukan.'], 404);
        }
        if (in_array($import->stage, ['finalized', 'cancelled'], true)) {
            return response()->json(['success' => false, 'message' => 'Proses impor yang sudah final/dibatalkan tidak dapat diubah.'], 422);
        }

        $validated = $this->validateImportPayload($request, isUpdate: true);

        DB::transaction(function () use ($import, $validated) {
            $import->fill([
                'supplier_id' => $validated['supplier_id'] ?? $import->supplier_id,
                'store_id' => array_key_exists('store_id', $validated) ? $validated['store_id'] : $import->store_id,
                'currency' => $validated['currency'] ?? $import->currency,
                'exchange_rate' => $validated['exchange_rate'] ?? $import->exchange_rate,
                'invoice_number' => array_key_exists('invoice_number', $validated) ? $validated['invoice_number'] : $import->invoice_number,
                'purchase_date' => array_key_exists('purchase_date', $validated) ? $validated['purchase_date'] : $import->purchase_date,
                'notes' => array_key_exists('notes', $validated) ? $validated['notes'] : $import->notes,
            ])->save();

            // Full-replace items hanya sebelum barang diterima-timbang ulang
            // (receiving) — replace setelah itu akan menghapus jumlah diterima.
            if (array_key_exists('items', $validated)) {
                if (! in_array($import->stage, ['draft', 'payment', 'shipment', 'customs'], true)) {
                    abort(response()->json([
                        'success' => false,
                        'message' => 'Item hanya dapat diganti sebelum tahap kedatangan (jumlah diterima sudah/proses diisi).',
                    ], 422));
                }
                $import->items()->delete();
                $this->syncItems($import, $validated['items']);
            }

            if (array_key_exists('costs', $validated)) {
                $import->costs()->delete();
                $this->syncCosts($import, $validated['costs']);
            }
            $this->syncDerivedCosts($import);
        });

        $this->refreshTotals($import);

        return response()->json([
            'success' => true,
            'message' => 'Proses impor berhasil diperbarui.',
            'data' => $import->load(['supplier', 'items.product.unit', 'costs', 'payments', 'notuls']),
        ]);
    }

    public function storeDocument($id, Request $request)
    {
        if (! $this->isAdmin($request)) {
            return $this->forbidden();
        }

        $import = ImportPurchase::find($id);
        if (! $import) {
            return response()->json(['success' => false, 'message' => 'Proses impor tidak ditemukan.'], 404);
        }
        if (in_array($import->stage, ['finalized', 'cancelled'], true)) {
            return response()->json(['success' => false, 'message' => 'Proses impor sudah final/dibatalkan.'], 422);
        }

        $request->validate([
            'type' => 'required|in:'.implode(',', ImportHppService::DOCUMENT_TYPES),
            // Path hasil upload single-hop ke img service — prefix mencegah
            // path milik modul lain dipakai sebagai dokumen impor (anti-forgery,
            // pola ProcurementController pada image invoice).
            'file_path' => ['required', 'string', 'starts_with:documents/ImportPurchase/'],
            'original_name' => 'nullable|string|max:255',
            'mime_type' => 'nullable|string|max:100',
            'size_bytes' => 'nullable|integer|min:0',
        ]);

        $document = ImportDocument::create([
            'import_purchase_id' => $import->id,
            'type' => $request->string('type'),
            'file_path' => $request->string('file_path'),
            'original_name' => $request->input('original_name'),
            'mime_type' => $request->input('mime_type'),
            'size_bytes' => $request->input('size_bytes'),
            'uploaded_by' => $request->user()->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Dokumen berhasil dilampirkan.',
            'data' => $document,
        ], 201);
    }

    public function destroyDocument($id, $docId, Request $request)
    {
        if (! $this->isAdmin($request)) {
            return $this->forbidden();
        }

        $document = ImportDocument::where('import_purchase_id', $id)->find($docId);
        if (! $document) {
            return response()->json(['success' => false, 'message' => 'Dokumen tidak ditemukan.'], 404);
        }

        DB::transaction(function () use ($document) {
            $document->delete();
        });

        // Best-effort hapus file fisik di img service; kegagalan tidak boleh
        // menggagalkan request (record sudah terhapus).
        try {
            $this->storage->delete($document->file_path);
        } catch (\Throwable $e) {
            Log::error('Gagal hapus file dokumen impor di img service.', [
                'path' => $document->file_path,
                'message' => $e->getMessage(),
            ]);
        }

        return response()->json(['success' => true, 'message' => 'Dokumen berhasil dihapus.']);
    }

    public function receive($id, Request $request)
    {
        if (! $this->isAdmin($request)) {
            return $this->forbidden();
        }

        $import = ImportPurchase::find($id);
        if (! $import) {
            return response()->json(['success' => false, 'message' => 'Proses impor tidak ditemukan.'], 404);
        }
        // Realisasi boleh diisi pada semua tahap non-terminal (bypass alur):
        // biaya, jumlah diterima, pembayaran, dan notul tidak lagi menunggu
        // tahap tertentu. Final/cancelled tetap terkunci.
        if (in_array($import->stage, ['finalized', 'cancelled'], true)) {
            return response()->json(['success' => false, 'message' => 'Realisasi tidak dapat diisi pada proses yang sudah final/dibatalkan.'], 422);
        }

        $request->validate([
            'items' => 'nullable|array',
            'items.*.id' => 'required|integer|distinct',
            'items.*.quantity_received' => 'required|numeric|min:0',
            'costs' => 'nullable|array',
            // id null → baris biaya BARU (denda/biaya lain yang muncul belakangan).
            'costs.*.id' => 'nullable|integer',
            'costs.*.amount_real' => 'nullable|numeric|min:0',
            'costs.*.stage' => 'nullable|in:purchase,payment,shipment,customs,arrival',
            'costs.*.name' => 'nullable|string|max:100',
            'costs.*.category' => 'nullable|in:goods,bank,tax,logistics,other,discount',
            'costs.*.tax_rate' => 'nullable|numeric|min:0|max:100',
            'costs.*.is_foreign_currency' => 'nullable|boolean',
            'costs.*.amount_foreign' => 'nullable|numeric|min:0',
            'payments' => 'nullable|array',
            'payments.*.id' => 'nullable|integer|distinct',
            'payments.*.amount' => 'required|numeric|gt:0',
            'payments.*.paid_at' => 'required|date',
            'payments.*.note' => 'nullable|string',
            'payments.*.proof_path' => ['nullable', 'string', 'max:500', 'starts_with:documents/ImportPurchase/'],
            'payments.*.proof_original_name' => 'nullable|string|max:255',
            'payments.*.proof_mime_type' => 'nullable|string|max:100',
            'payments.*.proof_size_bytes' => 'nullable|integer|min:0',
            'notuls' => 'nullable|array',
            'notuls.*.id' => 'nullable|integer|distinct',
            'notuls.*.notul_number' => 'nullable|string|max:100',
            'notuls.*.notul_date' => 'required|date',
            'notuls.*.pph_amount' => 'nullable|numeric|min:0',
            'notuls.*.ppn_amount' => 'nullable|numeric|min:0',
            'notuls.*.penalty_amount' => 'nullable|numeric|min:0',
            'notuls.*.other_amount' => 'nullable|numeric|min:0',
            'notuls.*.other_description' => 'nullable|string|max:100',
            'notuls.*.note' => 'nullable|string',
            'notuls.*.document_path' => ['nullable', 'string', 'max:500', 'starts_with:documents/ImportPurchase/'],
            'notuls.*.document_original_name' => 'nullable|string|max:255',
            'notuls.*.document_mime_type' => 'nullable|string|max:100',
            'notuls.*.document_size_bytes' => 'nullable|integer|min:0',
        ]);

        $attachmentsBefore = $this->attachmentPaths($import);

        DB::transaction(function () use ($request, $import) {
            foreach ($request->input('items', []) as $row) {
                $this->findOrFailOwned($import, 'items', $row['id'])
                    ->update(['quantity_received' => $row['quantity_received']]);
            }
            $seenIds = [];
            foreach ($request->input('costs', []) as $row) {
                if (! empty($row['id'])) {
                    $seenIds[] = (int) $row['id'];
                    $cost = $this->findOrFailOwned($import, 'costs', $row['id']);
                    if ($cost->source !== null) {
                        abort(response()->json([
                            'success' => false,
                            'message' => 'Biaya pembayaran/notul dihitung otomatis dan tidak dapat diubah manual.',
                        ], 422));
                    }
                    $cost->update(['amount_real' => $row['amount_real'] ?? null]);

                    continue;
                }
                // Baris baru: minimal nama; stage/kategori di-default wajar.
                if (empty($row['name'])) {
                    abort(response()->json([
                        'success' => false,
                        'message' => 'Baris biaya baru wajib memiliki nama.',
                    ], 422));
                }
                $import->costs()->create([
                    'stage' => $row['stage'] ?? $import->stage,
                    'name' => $row['name'],
                    'category' => $row['category'] ?? 'other',
                    'tax_rate' => $row['tax_rate'] ?? null,
                    'is_foreign_currency' => $row['is_foreign_currency'] ?? false,
                    'amount_foreign' => $row['amount_foreign'] ?? null,
                    'amount_estimate' => 0,
                    'amount_real' => $row['amount_real'] ?? null,
                ]);
            }
            if (count($seenIds) !== count(array_unique($seenIds))) {
                abort(response()->json([
                    'success' => false,
                    'message' => 'Baris biaya yang sama dikirim lebih dari sekali.',
                ], 422));
            }

            if ($request->has('payments')) {
                $this->syncPayments($import, $request->input('payments') ?? []);
            }
            if ($request->has('notuls')) {
                $this->syncNotuls($import, $request->input('notuls') ?? []);
            }
            $this->syncDerivedCosts($import);
        });

        $this->refreshTotals($import);
        $this->deleteRemovedAttachments($attachmentsBefore, $this->attachmentPaths($import));

        return response()->json([
            'success' => true,
            'message' => 'Data penerimaan, pembayaran, dan realisasi biaya tersimpan.',
            'data' => $import->load(['items.product.unit', 'costs', 'payments', 'notuls']),
        ]);
    }

    public function advance($id, Request $request)
    {
        if (! $this->isAdmin($request)) {
            return $this->forbidden();
        }

        $request->validate([
            'bypass_documents' => 'sometimes|boolean',
        ]);

        $import = ImportPurchase::with(['items', 'documents'])->find($id);
        if (! $import) {
            return response()->json(['success' => false, 'message' => 'Proses impor tidak ditemukan.'], 404);
        }

        $bypass = $request->boolean('bypass_documents');

        $blockers = $this->hpp->blockersForAdvance(
            $import->stage,
            $import->uploadedDocumentTypes(),
            $import->items->map(fn ($i) => [
                'quantity' => $i->quantity,
                'quantity_received' => $i->quantity_received,
            ])->all(),
            (float) $import->exchange_rate,
            $bypass,
        );

        if ($blockers) {
            return response()->json([
                'success' => false,
                'message' => 'Syarat tahap belum terpenuhi.',
                'data' => ['blockers' => $blockers],
            ], 422);
        }

        // Tangkap sebelum stage dimutasi — untuk log jejak bypass.
        $from = $import->stage;
        $skipped = $bypass ? $this->hpp->missingDocuments($from, $import->uploadedDocumentTypes()) : [];

        $import->stage = $this->hpp->nextStage($from);
        $import->save();
        $this->refreshTotals($import);

        if ($skipped !== []) {
            Log::info('Impor: advance bypass dokumen', [
                'user_id' => $request->user()->id,
                'import_purchase_id' => $import->id,
                'from_stage' => $from,
                'to_stage' => $import->stage,
                'skipped_documents' => array_map(
                    fn (string $type) => ImportHppService::DOCUMENT_LABELS[$type] ?? $type,
                    $skipped,
                ),
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Tahap menjadi: '.ImportHppService::STAGE_LABELS[$import->stage],
            'data' => $import,
        ]);
    }

    public function finalize($id, Request $request)
    {
        if (! $this->isAdmin($request)) {
            return $this->forbidden();
        }

        $import = ImportPurchase::with(['items.product', 'costs'])->find($id);
        if (! $import) {
            return response()->json(['success' => false, 'message' => 'Proses impor tidak ditemukan.'], 404);
        }
        if ($import->stage !== 'receiving') {
            return response()->json(['success' => false, 'message' => 'Finalisasi hanya bisa dari tahap penerimaan.'], 422);
        }

        $blockers = $this->hpp->blockersForFinalize(
            $import->items->map(fn ($i) => [
                'quantity' => $i->quantity,
                'quantity_received' => $i->quantity_received,
            ])->all(),
        );
        if ($blockers) {
            return response()->json([
                'success' => false,
                'message' => 'Syarat finalisasi belum terpenuhi.',
                'data' => ['blockers' => $blockers],
            ], 422);
        }

        $computed = DB::transaction(function () use ($import) {
            $this->syncDerivedCosts($import);
            $import->load(['items', 'costs']);
            $computed = $this->computeFor($import);

            foreach ($import->items as $item) {
                $row = collect($computed['items'])->firstWhere('id', $item->id);
                $item->update([
                    'hpp_estimate' => round($row['hpp_estimate_unit'], 2),
                    'hpp_real' => round($row['hpp_real_unit'], 2),
                ]);

                // HPP finalisasi meng-update harga pokok produk (dasar harga
                // jual minimum) — permintaan fitur: "harga satuan dari setiap
                // produk".
                $item->product->update([
                    'hpp' => round($row['hpp_real_unit'], 2),
                    'hpp_updated_at' => now(),
                ]);
            }

            $import->update([
                'stage' => 'finalized',
                'total_estimate' => round($computed['total_estimate'], 2),
                'total_real' => round($computed['total_real'], 2),
            ]);

            return $computed;
        });

        return response()->json([
            'success' => true,
            'message' => 'Proses impor final: HPP per produk sudah di-update.',
            'data' => $import->fresh(['supplier', 'items.product.unit', 'costs', 'documents']),
            'hpp' => $computed,
        ]);
    }

    public function cancel($id, Request $request)
    {
        if (! $this->isAdmin($request)) {
            return $this->forbidden();
        }

        $import = ImportPurchase::find($id);
        if (! $import) {
            return response()->json(['success' => false, 'message' => 'Proses impor tidak ditemukan.'], 404);
        }
        if ($import->stage === 'finalized' || $import->stage === 'cancelled') {
            return response()->json(['success' => false, 'message' => 'Proses impor sudah final/dibatalkan.'], 422);
        }

        $import->update(['stage' => 'cancelled']);

        return response()->json(['success' => true, 'message' => 'Proses impor dibatalkan.', 'data' => $import]);
    }

    /**
     * Hapus permanen proses impor beserta items, costs & dokumennya.
     *
     * Diperbolehkan untuk semua tahap KECUALI finalized — proses final sudah
     * meng-update HPP master produk sehingga harus tetap tersimpan sebagai
     * sumber historis (pakai cancel untuk mengarsipkan yang batal).
     */
    public function destroy($id, Request $request)
    {
        if (! $this->isAdmin($request)) {
            return $this->forbidden();
        }

        $import = ImportPurchase::with('documents')->find($id);
        if (! $import) {
            return response()->json(['success' => false, 'message' => 'Proses impor tidak ditemukan.'], 404);
        }
        if ($import->stage === 'finalized') {
            return response()->json([
                'success' => false,
                'message' => 'Proses impor sudah final dan tidak dapat dihapus (HPP produk tercatat dari proses ini).',
            ], 422);
        }

        $attachments = $this->attachmentPaths($import);

        DB::transaction(function () use ($import) {
            $import->documents()->delete();
            $import->items()->delete();
            $import->costs()->delete();
            $import->delete();
        });

        $this->deleteAttachmentFiles($attachments);

        return response()->json(['success' => true, 'message' => 'Proses impor dihapus permanen.']);
    }

    /* --------------------------------------------------------------- HELPER */

    /**
     * Cari item/cost milik import tertentu — 404 bila id tidak termasuk
     * anak import ini (cegah update lintas proses lewat receive).
     */
    private function findOrFailOwned(ImportPurchase $import, string $relation, int $id)
    {
        $model = $import->$relation()->find($id);
        if (! $model) {
            abort(response()->json([
                'success' => false,
                'message' => "Data {$relation} #{$id} bukan bagian dari proses impor ini.",
            ], 404));
        }

        return $model;
    }

    private function validateImportPayload(Request $request, bool $isUpdate = false): array
    {
        $required = $isUpdate ? 'sometimes' : 'required';

        return $request->validate([
            'supplier_id' => $required.'|exists:suppliers,id',
            'store_id' => 'nullable|exists:stores,id',
            'currency' => 'sometimes|string|size:3',
            'exchange_rate' => $required.'|numeric|gt:0',
            'invoice_number' => 'nullable|string|max:100',
            'purchase_date' => 'sometimes|date',
            'notes' => 'nullable|string',
            'items' => $required.'|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|numeric|gt:0',
            'items.*.unit_price_foreign' => 'required|numeric|min:0',
            'items.*.quantity_received' => 'sometimes|nullable|numeric|min:0',
            'costs' => 'sometimes|array',
            'costs.*.stage' => 'required|in:purchase,payment,shipment,customs,arrival',
            'costs.*.name' => 'required|string|max:100',
            'costs.*.category' => 'required|in:goods,bank,tax,logistics,other,discount',
            'costs.*.tax_rate' => 'nullable|numeric|min:0|max:100',
            'costs.*.is_foreign_currency' => 'sometimes|boolean',
            'costs.*.amount_foreign' => 'nullable|numeric|min:0',
            'costs.*.amount_estimate' => 'required|numeric|min:0',
            'costs.*.amount_real' => 'sometimes|nullable|numeric|min:0',
        ]);
    }

    private function syncPayments(ImportPurchase $import, array $payments): void
    {
        $seenIds = [];
        foreach ($payments as $row) {
            $data = [
                'amount' => $row['amount'],
                'paid_at' => $row['paid_at'],
                'note' => $row['note'] ?? null,
            ];
            foreach (['proof_path', 'proof_original_name', 'proof_mime_type', 'proof_size_bytes'] as $field) {
                if (array_key_exists($field, $row)) {
                    $data[$field] = $row[$field];
                }
            }

            if (! empty($row['id'])) {
                $payment = $this->findOrFailOwned($import, 'payments', (int) $row['id']);
                $seenIds[] = $payment->id;
                $payment->update($data);

                continue;
            }

            $payment = $import->payments()->create($data);
            $seenIds[] = $payment->id;
        }

        if ($seenIds === []) {
            $import->payments()->delete();
        } else {
            $import->payments()->whereNotIn('id', $seenIds)->delete();
        }
    }

    private function syncNotuls(ImportPurchase $import, array $notuls): void
    {
        $seenIds = [];
        foreach ($notuls as $row) {
            $data = [
                'notul_number' => $row['notul_number'] ?? null,
                'notul_date' => $row['notul_date'],
                'pph_amount' => $row['pph_amount'] ?? 0,
                'ppn_amount' => $row['ppn_amount'] ?? 0,
                'penalty_amount' => $row['penalty_amount'] ?? 0,
                'other_amount' => $row['other_amount'] ?? 0,
                'other_description' => $row['other_description'] ?? null,
                'note' => $row['note'] ?? null,
            ];
            foreach (['document_path', 'document_original_name', 'document_mime_type', 'document_size_bytes'] as $field) {
                if (array_key_exists($field, $row)) {
                    $data[$field] = $row[$field];
                }
            }

            if (! empty($row['id'])) {
                $notul = $this->findOrFailOwned($import, 'notuls', (int) $row['id']);
                $seenIds[] = $notul->id;
                $notul->update($data);

                continue;
            }

            $notul = $import->notuls()->create($data);
            $seenIds[] = $notul->id;
        }

        if ($seenIds === []) {
            $import->notuls()->delete();
        } else {
            $import->notuls()->whereNotIn('id', $seenIds)->delete();
        }
    }

    /**
     * Baris biaya turunan dari pembayaran & notul. Row ini yang membuat
     * total real = Σ pembayaran + biaya non-bank lain, tanpa double count
     * Transfer Bank/Bank/Discounts manual.
     */
    private function syncDerivedCosts(ImportPurchase $import): void
    {
        $import->costs()->whereIn('source', ['payment', 'notul'])->delete();

        $exchangeRate = (float) $import->exchange_rate;
        $goodsTotal = $import->items()->get()->sum(
            fn ($item) => (float) $item->quantity * (float) $item->unit_price_foreign * $exchangeRate
        );

        $payments = $import->payments()->get();
        if ($payments->isNotEmpty()) {
            $import->costs()->create([
                'stage' => 'payment',
                'name' => 'Pembayaran Bank (net)',
                'category' => 'bank',
                'source' => 'payment',
                'amount_estimate' => 0,
                'amount_real' => round($payments->sum('amount') - $goodsTotal, 2),
            ]);
        }

        $notuls = $import->notuls()->get()->sortByDesc('id')->sortByDesc('notul_date');
        if ($notuls->isNotEmpty()) {
            $latest = $notuls->first();
            $import->costs()->createMany([
                [
                    'stage' => 'customs',
                    'name' => 'PPh Impor (Notul)',
                    'category' => 'tax',
                    'source' => 'notul',
                    'amount_estimate' => 0,
                    'amount_real' => (float) $latest->pph_amount,
                ],
                [
                    'stage' => 'customs',
                    'name' => 'PPN Impor (Notul)',
                    'category' => 'tax',
                    'source' => 'notul',
                    'amount_estimate' => 0,
                    'amount_real' => (float) $latest->ppn_amount,
                ],
            ]);

            $penalty = $notuls->sum('penalty_amount');
            if ($penalty > 0) {
                $import->costs()->create([
                    'stage' => 'customs',
                    'name' => 'Denda Administrasi (Notul)',
                    'category' => 'other',
                    'source' => 'notul',
                    'amount_estimate' => 0,
                    'amount_real' => (float) $penalty,
                ]);
            }

            $other = $notuls->sum('other_amount');
            if ($other > 0) {
                $import->costs()->create([
                    'stage' => 'customs',
                    'name' => 'Biaya Lain (Notul)',
                    'category' => 'other',
                    'source' => 'notul',
                    'amount_estimate' => 0,
                    'amount_real' => (float) $other,
                ]);
            }
        }
    }

    private function syncItems(ImportPurchase $import, array $items): void
    {
        foreach ($items as $item) {
            $import->items()->create([
                'product_id' => $item['product_id'],
                'quantity' => $item['quantity'],
                'unit_price_foreign' => $item['unit_price_foreign'],
                'quantity_received' => $item['quantity_received'] ?? null,
            ]);
        }
    }

    private function syncCosts(ImportPurchase $import, array $costs): void
    {
        foreach ($costs as $cost) {
            $import->costs()->create([
                'stage' => $cost['stage'],
                'name' => $cost['name'],
                'category' => $cost['category'],
                'tax_rate' => $cost['tax_rate'] ?? null,
                'is_foreign_currency' => $cost['is_foreign_currency'] ?? false,
                'amount_foreign' => $cost['amount_foreign'] ?? null,
                'amount_estimate' => $cost['amount_estimate'],
                'amount_real' => $cost['amount_real'] ?? null,
            ]);
        }
    }

    /**
     * Semua path file fisik yang dimiliki proses impor (dokumen, bukti bayar,
     * dokumen notul) — untuk pembersihan saat baris/proses dihapus.
     *
     * @return list<string>
     */
    private function attachmentPaths(ImportPurchase $import): array
    {
        $import->load(['documents', 'payments', 'notuls']);

        return collect()
            ->merge($import->documents->pluck('file_path'))
            ->merge($import->payments->pluck('proof_path'))
            ->merge($import->notuls->pluck('document_path'))
            ->filter(fn ($path) => is_string($path) && $path !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $before
     * @param  list<string>  $after
     */
    private function deleteRemovedAttachments(array $before, array $after): void
    {
        $this->deleteAttachmentFiles(array_values(array_diff($before, $after)));
    }

    /**
     * Best-effort hapus file fisik di img service; kegagalan tidak boleh
     * menggagalkan request (record DB sudah berubah).
     *
     * @param  list<string>  $paths
     */
    private function deleteAttachmentFiles(array $paths): void
    {
        if ($paths === []) {
            return;
        }

        // Bersihkan setelah response agar img service yang lambat/down tidak
        // menahan request simpan/hapus.
        defer(function () use ($paths) {
            foreach ($paths as $path) {
                try {
                    $this->storage->delete($path);
                } catch (\Throwable $e) {
                    Log::error('Gagal hapus file lampiran impor di img service.', [
                        'path' => $path,
                        'message' => $e->getMessage(),
                    ]);
                }
            }
        });
    }

    /**
     * Hitung HPP untuk import terkait (relasi harus sudah fresh).
     */
    private function computeFor(ImportPurchase $import): array
    {
        return $this->hpp->compute(
            $import->items,
            $import->costs,
            (float) $import->exchange_rate,
        );
    }

    /**
     * Tambahkan harga satuan per item pada payload list tanpa menyentuh
     * snapshot DB. Realisasi dipakai bila qty diterima sudah ada atau proses
     * final; selain itu estimasi.
     */
    private function withUnitPrices(ImportPurchase $import): ImportPurchase
    {
        $computed = $this->computeFor($import);
        $rows = collect($computed['items'])->keyBy('id');
        $isRealized = $this->importHasRealization($import);

        foreach ($import->items as $item) {
            $row = $rows->get($item->id);
            if (! $row) {
                continue;
            }

            $unitPriceIdr = $isRealized
                ? (float) $row['hpp_real_unit']
                : (float) $row['hpp_estimate_unit'];

            $item->setAttribute('hpp_estimate_unit', round((float) $row['hpp_estimate_unit'], 2));
            $item->setAttribute('hpp_real_unit', round((float) $row['hpp_real_unit'], 2));
            $item->setAttribute('unit_price_idr', round($unitPriceIdr, 2));
            $item->setAttribute('price_is_realized', $isRealized);
        }

        return $import;
    }

    /**
     * Realisasi sudah ada bila qty diterima diisi, salah satu biaya punya
     * nominal real, atau proses sudah final. Ini membuat input realisasi
     * (mis. Rp 250.000.000) langsung menggeser harga satuan list dari
     * estimasi ke realisasi tanpa harus menunggu penerimaan barang.
     */
    private function importHasRealization(ImportPurchase $import): bool
    {
        if ($import->stage === 'finalized') {
            return true;
        }

        if ($import->items->contains(
            fn ($item) => (float) ($item->quantity_received ?? 0) > 0
        )) {
            return true;
        }

        return $import->costs->contains(
            fn (ImportCost $cost) => $cost->amount_real !== null
        );
    }

    /**
     * Simpan snapshot total estimate/real ke header setelah mutasi data.
     */
    private function refreshTotals(ImportPurchase $import): void
    {
        $import->load(['items', 'costs']);
        $computed = $this->computeFor($import);
        $import->update([
            'total_estimate' => round($computed['total_estimate'], 2),
            'total_real' => round($computed['total_real'], 2),
        ]);
    }
}
