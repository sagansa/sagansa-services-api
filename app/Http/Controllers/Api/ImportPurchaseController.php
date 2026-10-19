<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Contracts\ImageStorageContract;
use App\Models\ImportCost;
use App\Models\ImportDocument;
use App\Models\ImportPurchase;
use App\Services\ImportHppService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Impor barang: tahapan fixed + kelengkapan dokumen + HPP per produk.
 *
 * Baca: semua user login. Tulis (create/update/advance/finalize/cancel):
 * admin & super_admin — guard in-controller, pola ProcurementController.
 */
class ImportPurchaseController extends Controller
{
    public function __construct(
        protected ImportHppService $hpp,
        protected ImageStorageContract $storage,
    ) {
    }

    private function isAdmin(Request $request): bool
    {
        $user = $request->user();

        return $user && ($user->hasRole('admin') || $user->hasRole('super_admin'));
    }

    private function forbidden(): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Hanya admin/super_admin yang dapat melakukan aksi ini.',
        ], 403);
    }

    /* ----------------------------------------------------------------- READ */

    public function index(Request $request)
    {
        $query = ImportPurchase::with(['supplier', 'createdBy', 'items.product.unit'])
            ->withCount('documents');

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

        return response()->json([
            'success' => true,
            'data' => $paginated->items(),
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

    private function stageCounts(): array
    {
        return ImportPurchase::query()
            ->select('stage', DB::raw('count(*) as total'))
            ->groupBy('stage')
            ->pluck('total', 'stage')
            ->all();
    }

    public function show($id, Request $request)
    {
        $import = ImportPurchase::with([
            'supplier', 'store', 'createdBy',
            'items.product.unit', 'costs', 'documents',
        ])->find($id);

        if (!$import) {
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
        if (!$this->isAdmin($request)) {
            return $this->forbidden();
        }

        $validated = $this->validateImportPayload($request);

        $import = DB::transaction(function () use ($request, $validated) {
            $import = ImportPurchase::create([
                'supplier_id' => $validated['supplier_id'],
                'store_id' => $validated['store_id'] ?? null,
                'currency' => $validated['currency'] ?? 'USD',
                'exchange_rate' => $validated['exchange_rate'],
                'stage' => 'draft',
                'notes' => $validated['notes'] ?? null,
                'created_by_id' => $request->user()->id,
            ]);

            $this->syncItems($import, $validated['items']);
            $this->syncCosts($import, $validated['costs'] ?? []);

            return $import;
        });

        $this->refreshTotals($import);

        return response()->json([
            'success' => true,
            'message' => 'Proses impor berhasil dibuat (draft).',
            'data' => $import->load(['supplier', 'items.product.unit', 'costs']),
        ], 201);
    }

    public function update($id, Request $request)
    {
        if (!$this->isAdmin($request)) {
            return $this->forbidden();
        }

        $import = ImportPurchase::find($id);
        if (!$import) {
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
                'notes' => array_key_exists('notes', $validated) ? $validated['notes'] : $import->notes,
            ])->save();

            // Full-replace items hanya sebelum barang diterima-timbang ulang
            // (receiving) — replace setelah itu akan menghapus jumlah diterima.
            if (array_key_exists('items', $validated)) {
                if (!in_array($import->stage, ['draft', 'payment', 'shipment', 'customs'], true)) {
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
        });

        $this->refreshTotals($import);

        return response()->json([
            'success' => true,
            'message' => 'Proses impor berhasil diperbarui.',
            'data' => $import->load(['supplier', 'items.product.unit', 'costs']),
        ]);
    }

    public function storeDocument($id, Request $request)
    {
        if (!$this->isAdmin($request)) {
            return $this->forbidden();
        }

        $import = ImportPurchase::find($id);
        if (!$import) {
            return response()->json(['success' => false, 'message' => 'Proses impor tidak ditemukan.'], 404);
        }
        if (in_array($import->stage, ['finalized', 'cancelled'], true)) {
            return response()->json(['success' => false, 'message' => 'Proses impor sudah final/dibatalkan.'], 422);
        }

        $request->validate([
            'type' => 'required|in:' . implode(',', ImportHppService::DOCUMENT_TYPES),
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
        if (!$this->isAdmin($request)) {
            return $this->forbidden();
        }

        $document = ImportDocument::where('import_purchase_id', $id)->find($docId);
        if (!$document) {
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
            \Illuminate\Support\Facades\Log::error('Gagal hapus file dokumen impor di img service.', [
                'path' => $document->file_path,
                'message' => $e->getMessage(),
            ]);
        }

        return response()->json(['success' => true, 'message' => 'Dokumen berhasil dihapus.']);
    }

    public function receive($id, Request $request)
    {
        if (!$this->isAdmin($request)) {
            return $this->forbidden();
        }

        $import = ImportPurchase::find($id);
        if (!$import) {
            return response()->json(['success' => false, 'message' => 'Proses impor tidak ditemukan.'], 404);
        }
        if (!in_array($import->stage, ['arrival', 'receiving'], true)) {
            return response()->json(['success' => false, 'message' => 'Penerimaan hanya dapat diisi pada tahap kedatangan/penerimaan.'], 422);
        }

        $request->validate([
            'items' => 'nullable|array',
            'items.*.id' => 'required|integer|distinct',
            'items.*.quantity_received' => 'required|numeric|min:0',
            'costs' => 'nullable|array',
            'costs.*.id' => 'required|integer|distinct',
            'costs.*.amount_real' => 'nullable|numeric|min:0',
        ]);

        DB::transaction(function () use ($request, $import) {
            foreach ($request->input('items', []) as $row) {
                $this->findOrFailOwned($import, 'items', $row['id'])
                    ->update(['quantity_received' => $row['quantity_received']]);
            }
            foreach ($request->input('costs', []) as $row) {
                $this->findOrFailOwned($import, 'costs', $row['id'])
                    ->update(['amount_real' => $row['amount_real']]);
            }
        });

        $this->refreshTotals($import);

        return response()->json([
            'success' => true,
            'message' => 'Data penerimaan & realisasi biaya tersimpan.',
            'data' => $import->load(['items.product.unit', 'costs']),
        ]);
    }

    public function advance($id, Request $request)
    {
        if (!$this->isAdmin($request)) {
            return $this->forbidden();
        }

        $import = ImportPurchase::with(['items', 'documents'])->find($id);
        if (!$import) {
            return response()->json(['success' => false, 'message' => 'Proses impor tidak ditemukan.'], 404);
        }

        $blockers = $this->hpp->blockersForAdvance(
            $import->stage,
            $import->uploadedDocumentTypes(),
            $import->items->map(fn ($i) => [
                'quantity' => $i->quantity,
                'quantity_received' => $i->quantity_received,
            ])->all(),
            (float) $import->exchange_rate,
        );

        if ($blockers) {
            return response()->json([
                'success' => false,
                'message' => 'Syarat tahap belum terpenuhi.',
                'data' => ['blockers' => $blockers],
            ], 422);
        }

        $import->stage = $this->hpp->nextStage($import->stage);
        $import->save();
        $this->refreshTotals($import);

        return response()->json([
            'success' => true,
            'message' => 'Tahap menjadi: ' . ImportHppService::STAGE_LABELS[$import->stage],
            'data' => $import,
        ]);
    }

    public function finalize($id, Request $request)
    {
        if (!$this->isAdmin($request)) {
            return $this->forbidden();
        }

        $import = ImportPurchase::with(['items.product', 'costs'])->find($id);
        if (!$import) {
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
        if (!$this->isAdmin($request)) {
            return $this->forbidden();
        }

        $import = ImportPurchase::find($id);
        if (!$import) {
            return response()->json(['success' => false, 'message' => 'Proses impor tidak ditemukan.'], 404);
        }
        if ($import->stage === 'finalized' || $import->stage === 'cancelled') {
            return response()->json(['success' => false, 'message' => 'Proses impor sudah final/dibatalkan.'], 422);
        }

        $import->update(['stage' => 'cancelled']);

        return response()->json(['success' => true, 'message' => 'Proses impor dibatalkan.', 'data' => $import]);
    }

    /* --------------------------------------------------------------- HELPER */

    /**
     * Cari item/cost milik import tertentu — 404 bila id tidak termasuk
     * anak import ini (cegah update lintas proses lewat receive).
     */
    private function findOrFailOwned(ImportPurchase $import, string $relation, int $id)
    {
        $model = $import->$relation()->find($id);
        if (!$model) {
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
            'supplier_id' => $required . '|exists:suppliers,id',
            'store_id' => 'nullable|exists:stores,id',
            'currency' => 'sometimes|string|size:3',
            'exchange_rate' => $required . '|numeric|gt:0',
            'notes' => 'nullable|string',
            'items' => $required . '|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|numeric|gt:0',
            'items.*.unit_price_foreign' => 'required|numeric|min:0',
            'items.*.quantity_received' => 'sometimes|nullable|numeric|min:0',
            'costs' => 'sometimes|array',
            'costs.*.stage' => 'required|in:purchase,payment,shipment,customs,arrival',
            'costs.*.name' => 'required|string|max:100',
            'costs.*.category' => 'required|in:goods,bank,tax,logistics,other',
            'costs.*.tax_rate' => 'nullable|numeric|min:0|max:100',
            'costs.*.is_foreign_currency' => 'sometimes|boolean',
            'costs.*.amount_foreign' => 'nullable|numeric|min:0',
            'costs.*.amount_estimate' => 'required|numeric|min:0',
            'costs.*.amount_real' => 'sometimes|nullable|numeric|min:0',
        ]);
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
