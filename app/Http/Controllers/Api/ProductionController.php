<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DetailInvoice;
use App\Models\Production;
use App\Models\ProductionItem;
use App\Models\Recipe;
use App\Services\ProductionLedgerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * API produksi untuk mobile apps. Authorization: admin & super_admin (mobile
 * admin internal). Staff biasa tidak punya akses — operasi produksi adalah
 * operasi manajerial, bukan operasi harian staff toko.
 *
 * Endpoint:
 *   GET    /productions                    List (paginated, filter store/status/applied)
 *   GET    /productions/{id}               Detail + items
 *   POST   /productions                    Create (bisa otomatis prefill dari recipe_id)
 *   PUT    /productions/{id}               Update header (bila belum applied)
 *   POST   /productions/{id}/items         Tambah 1 item (bila belum applied)
 *   PUT    /productions/{id}/items/{itemId} Update item
 *   DELETE /productions/{id}/items/{itemId} Hapus item
 *   POST   /productions/{id}/apply         Apply mutasi stok
 *   POST   /productions/{id}/revert        Revert mutasi stok
 */
class ProductionController extends Controller
{
    public function __construct(protected ProductionLedgerService $ledger) {}

    /**
     * Gate: hanya admin / super_admin yang boleh akses modul produksi.
     */
    private function authorizeAdmin(Request $request): void
    {
        $user = $request->user();
        abort_unless(
            $user && ($user->hasRole('admin') || $user->hasRole('super_admin')),
            403,
            'Hanya admin yang dapat mengelola produksi.'
        );
    }

    public function index(Request $request)
    {
        $this->authorizeAdmin($request);

        $query = Production::with([
            'store:id,nickname,name',
            'recipe.product:id,name',
            'createdBy:id,name',
        ])->withCount('items');

        if ($storeId = $request->integer('store_id')) {
            $query->where('store_id', $storeId);
        }
        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }
        if ($request->has('applied')) {
            $request->boolean('applied')
                ? $query->whereNotNull('applied_at')
                : $query->whereNull('applied_at');
        }

        $perPage = $request->integer('per_page', 20);
        $records = $query->latest('id')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $records->items(),
            'pagination' => [
                'current_page' => $records->currentPage(),
                'last_page'    => $records->lastPage(),
                'per_page'     => $records->perPage(),
                'total'        => $records->total(),
            ],
        ]);
    }

    /**
     * Daftar DetailInvoice yang masih tersedia (status '1') untuk dijadikan
     * bahan baku produksi di sebuah toko — definisi yang sama dengan admin
     * (ProductionMainFromsRelationManager: wajib pilih detail invoice
     * berstatus '1'). Baris yang sudah terpakai produksi lain berstatus '2'
     * dan otomatis tersaring keluar.
     */
    public function availableDetailInvoices(Request $request)
    {
        $this->authorizeAdmin($request);

        $validated = $request->validate([
            'store_id' => ['required', 'integer', 'exists:stores,id'],
        ]);

        $rows = DetailInvoice::query()
            ->where('detail_invoices.status', '1')
            ->whereHas(
                'invoicePurchase',
                fn ($q) => $q->where('store_id', $validated['store_id'])
            )
            ->with(['detailRequest.product.unit', 'invoicePurchase:id,date'])
            ->orderBy('detail_invoices.id', 'asc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $rows->map(fn ($r) => [
                'id'                  => $r->id,
                'product_id'          => $r->detailRequest?->product_id,
                'product_name'        => $r->detailRequest?->product?->name ?? '-',
                'unit_id'             => $r->detailRequest?->product?->unit_id,
                'unit_name'           => $r->detailRequest?->product?->unit?->unit ?? '',
                'quantity_product'    => (float) $r->quantity_product,
                'invoice_purchase_id' => $r->invoice_purchase_id,
                'invoice_date'        => $r->invoicePurchase?->date,
            ])->values(),
        ]);
    }

    public function show(Request $request, $id)
    {
        $this->authorizeAdmin($request);

        $production = Production::with([
            'store:id,nickname,name',
            'recipe.product:id,name',
            'recipe.outputUnit:id,name',
            'createdBy:id,name',
            'approvedBy:id,name',
            'items.product:id,name,sku',
            'items.unit:id,name',
            'items.detailInvoice:id,quantity_product',
        ])->find($id);

        if (!$production) {
            return response()->json([
                'success' => false,
                'message' => 'Produksi tidak ditemukan.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $production,
        ]);
    }

    /**
     * Create production. Bila `recipe_id` diisi, ingredient resep otomatis
     * di-snapshot jadi production_items (boleh di-override setelahnya).
     *
     * Body:
     *   store_id: int (required)
     *   date: YYYY-MM-DD (required)
     *   recipe_id: int (opsional — bila ada, prefill items dari resep)
     *   notes: string (opsional)
     *   status: '1'|'2'|'3'|'4' (default '1')
     *   items: array (opsional — bila tidak ada recipe_id & user input manual)
     */
    public function store(Request $request)
    {
        $this->authorizeAdmin($request);

        $validator = Validator::make($request->all(), [
            'store_id'  => ['required', 'integer', 'exists:stores,id'],
            'date'      => ['required', 'date'],
            'recipe_id' => ['nullable', 'integer', 'exists:recipes,id'],
            'notes'     => ['nullable', 'string'],
            'status'    => ['nullable', Rule::in(['1', '2', '3', '4'])],
            'items'            => ['nullable', 'array'],
            'items.*.product_id'        => ['required_with:items', 'integer', 'exists:products,id'],
            'items.*.direction'         => ['required_with:items', Rule::in(['in', 'out'])],
            'items.*.source'            => ['nullable', Rule::in(['recipe_default', 'invoice', 'manual'])],
            'items.*.quantity'          => ['required_with:items', 'numeric', 'min:0'],
            'items.*.unit_id'           => ['nullable', 'integer', 'exists:units,id'],
            'items.*.detail_invoice_id' => ['nullable', 'integer', 'exists:detail_invoices,id'],
            'items.*.notes'             => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Input tidak valid.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        // Aturan definisi produksi (mengikuti admin ProductionMainFrom):
        // produksi wajib punya minimal satu bahan baku yang diambil dari
        // detail invoice pembelian, dan baris tersebut dipakai eksklusif
        // (detail invoice berubah jadi '2' begitu dipakai produksi).
        $invoiceDetailIds = collect($data['items'] ?? [])
            ->filter(fn ($i) => !empty($i['detail_invoice_id']))
            ->pluck('detail_invoice_id')
            ->unique()
            ->values()
            ->all();

        if (empty($invoiceDetailIds)) {
            return response()->json([
                'success' => false,
                'message' => 'Produksi wajib dibuat berdasarkan invoice: pilih minimal satu bahan baku dari invoice pembelian.',
            ], 422);
        }

        $consumed = DetailInvoice::whereIn('id', $invoiceDetailIds)
            ->where('status', '!=', '1')
            ->pluck('id');
        if ($consumed->isNotEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Detail invoice #' . $consumed->implode(', #') .
                    ' sudah dipakai produksi lain. Pilih bahan baku dari invoice yang belum terpakai.',
            ], 422);
        }

        try {
            $production = DB::connection('mysql')->transaction(function () use ($data, $request, $invoiceDetailIds) {
                $production = Production::create([
                    'store_id'      => $data['store_id'],
                    'date'          => $data['date'],
                    'status'        => $data['status'] ?? '1',
                    'notes'         => $data['notes'] ?? null,
                    'recipe_id'     => $data['recipe_id'] ?? null,
                    'created_by_id' => $request->user()->id,
                ]);

                // Prefill dari resep bila ada.
                if (!empty($data['recipe_id'])) {
                    $this->prefillFromRecipe($production, (int) $data['recipe_id']);
                }

                // Tambah item manual bila ada (di luar resep).
                foreach ($data['items'] ?? [] as $item) {
                    $hasInvoice = !empty($item['detail_invoice_id']);
                    $production->items()->create([
                        'product_id'        => $item['product_id'],
                        // Item bersumber invoice selalu bahan baku (input).
                        'direction'         => $hasInvoice ? 'in' : $item['direction'],
                        'source'            => $hasInvoice ? 'invoice' : ($item['source'] ?? 'manual'),
                        'quantity'          => $item['quantity'],
                        'unit_id'           => $item['unit_id'] ?? null,
                        'detail_invoice_id' => $item['detail_invoice_id'] ?? null,
                        'notes'             => $item['notes'] ?? null,
                    ]);
                }

                // Tandai detail invoice terpilih sudah terpakai (status '2'),
                // konsisten dengan afterCreate admin ProductionMainFroms.
                DetailInvoice::whereIn('id', $invoiceDetailIds)->update(['status' => '2']);

                return $production;
            });

            $production->load([
                'store:id,nickname',
                'recipe.product:id,name',
                'items.product:id,name,sku',
                'items.unit:id,name',
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Produksi dibuat.',
                'data'    => $production,
            ], 201);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal membuat produksi: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update header production. Dilarang bila sudah applied (stok sudah berubah).
     */
    public function update(Request $request, $id)
    {
        $this->authorizeAdmin($request);

        $production = Production::find($id);
        if (!$production) {
            return response()->json([
                'success' => false,
                'message' => 'Produksi tidak ditemukan.',
            ], 404);
        }
        if ($production->isApplied()) {
            return response()->json([
                'success' => false,
                'message' => 'Produksi yang sudah diterapkan stok-nya tidak dapat diubah. Batalkan stok dulu.',
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'store_id'  => ['sometimes', 'integer', 'exists:stores,id'],
            'date'      => ['sometimes', 'date'],
            'recipe_id' => ['nullable', 'integer', 'exists:recipes,id'],
            'notes'     => ['nullable', 'string'],
            'status'    => ['sometimes', Rule::in(['1', '2', '3', '4'])],
        ]);
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Input tidak valid.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $production->update($validator->validated());
        return response()->json(['success' => true, 'data' => $production->fresh()]);
    }

    public function addItem(Request $request, $id)
    {
        $this->authorizeAdmin($request);

        $production = Production::find($id);
        if (!$production) {
            return $this->notFound();
        }
        if ($production->isApplied()) {
            return response()->json([
                'success' => false,
                'message' => 'Produksi sudah diterapkan, tidak dapat menambah item.',
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'product_id'        => ['required', 'integer', 'exists:products,id'],
            'direction'         => ['required', Rule::in(['in', 'out'])],
            'source'            => ['nullable', Rule::in(['recipe_default', 'invoice', 'manual'])],
            'quantity'          => ['required', 'numeric', 'min:0'],
            'unit_id'           => ['nullable', 'integer', 'exists:units,id'],
            'detail_invoice_id' => ['nullable', 'integer', 'exists:detail_invoices,id'],
            'notes'             => ['nullable', 'string'],
        ]);
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Input tidak valid.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        // Detail invoice hanya boleh dipakai satu produksi (status harus '1').
        if ($detailInvoiceId = $request->input('detail_invoice_id')) {
            $taken = DetailInvoice::where('id', $detailInvoiceId)
                ->where('status', '!=', '1')
                ->exists();
            if ($taken) {
                return response()->json([
                    'success' => false,
                    'message' => 'Detail invoice #' . $detailInvoiceId .
                        ' sudah dipakai produksi lain. Pilih bahan baku dari invoice yang belum terpakai.',
                ], 422);
            }
        }

        $item = $production->items()->create(array_merge(
            ['source' => 'manual'],
            $validator->validated(),
            // Item bersumber invoice selalu bahan baku (input).
            !empty($request->input('detail_invoice_id'))
                ? ['direction' => 'in', 'source' => 'invoice']
                : [],
        ));

        // Konsumsi detail invoice (status '1' → '2') bila item merujuk invoice.
        if ($detailInvoiceId = $request->input('detail_invoice_id')) {
            DetailInvoice::where('id', $detailInvoiceId)
                ->where('status', '1')
                ->update(['status' => '2']);
        }

        return response()->json([
            'success' => true,
            'message' => 'Item ditambahkan.',
            'data'    => $item,
        ], 201);
    }

    public function updateItem(Request $request, $id, $itemId)
    {
        $this->authorizeAdmin($request);

        $production = Production::find($id);
        if (!$production) return $this->notFound();
        if ($production->isApplied()) {
            return response()->json([
                'success' => false,
                'message' => 'Produksi sudah diterapkan, tidak dapat mengubah item.',
            ], 422);
        }

        $item = ProductionItem::where('production_id', $id)->find($itemId);
        if (!$item) {
            return response()->json([
                'success' => false,
                'message' => 'Item tidak ditemukan.',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'product_id'        => ['sometimes', 'integer', 'exists:products,id'],
            'direction'         => ['sometimes', Rule::in(['in', 'out'])],
            'source'            => ['sometimes', Rule::in(['recipe_default', 'invoice', 'manual'])],
            'quantity'          => ['sometimes', 'numeric', 'min:0'],
            'unit_id'           => ['nullable', 'integer', 'exists:units,id'],
            'detail_invoice_id' => ['nullable', 'integer', 'exists:detail_invoices,id'],
            'notes'             => ['nullable', 'string'],
        ]);
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Input tidak valid.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $item->update($validator->validated());
        return response()->json(['success' => true, 'data' => $item->fresh()]);
    }

    public function deleteItem(Request $request, $id, $itemId)
    {
        $this->authorizeAdmin($request);

        $production = Production::find($id);
        if (!$production) return $this->notFound();
        if ($production->isApplied()) {
            return response()->json([
                'success' => false,
                'message' => 'Produksi sudah diterapkan, tidak dapat menghapus item.',
            ], 422);
        }

        $deleted = ProductionItem::where('production_id', $id)
            ->where('id', $itemId)->delete();

        if (!$deleted) {
            return response()->json([
                'success' => false,
                'message' => 'Item tidak ditemukan.',
            ], 404);
        }

        return response()->json(['success' => true, 'message' => 'Item dihapus.']);
    }

    /**
     * Apply mutasi stok. Idempoten via applied_at (dicek di service).
     */
    public function apply(Request $request, $id)
    {
        $this->authorizeAdmin($request);

        $production = Production::with('items')->find($id);
        if (!$production) return $this->notFound();

        if ($production->items()->count() === 0) {
            return response()->json([
                'success' => false,
                'message' => 'Tidak dapat apply: produksi belum punya item.',
            ], 422);
        }

        $ok = $this->ledger->apply($production);

        return $ok
            ? response()->json([
                'success' => true,
                'message' => 'Mutasi stok diterapkan.',
                'data'    => ['applied_at' => $production->fresh()->applied_at],
            ])
            : response()->json([
                'success' => false,
                'message' => 'Gagal menerapkan mutasi stok. Cek log untuk detail.',
            ], 500);
    }

    /**
     * Revert mutasi stok. Idempoten via applied_at.
     */
    public function revert(Request $request, $id)
    {
        $this->authorizeAdmin($request);

        $production = Production::with('items')->find($id);
        if (!$production) return $this->notFound();

        $ok = $this->ledger->revert($production);

        return $ok
            ? response()->json([
                'success' => true,
                'message' => 'Mutasi stok dibatalkan.',
                'data'    => ['applied_at' => null],
            ])
            : response()->json([
                'success' => false,
                'message' => 'Gagal membatalkan mutasi stok. Cek log untuk detail.',
            ], 500);
    }

    /**
     * Snapshot ingredient resep jadi production_items. Dipakai internal saat
     * create production dengan recipe_id.
     */
    private function prefillFromRecipe(Production $production, int $recipeId): void
    {
        $recipe = Recipe::with(['ingredients.product', 'product', 'outputUnit'])
            ->find($recipeId);
        if (!$recipe) return;

        // Baris OUTPUT: produk resep, qty = output_qty.
        $production->items()->create([
            'product_id'    => $recipe->product_id,
            'direction'     => 'out',
            'source'        => 'recipe_default',
            'quantity'      => $recipe->output_qty,
            'unit_id'       => $recipe->output_unit_id ?? $recipe->product?->unit_id,
        ]);

        // Baris INPUT: tiap ingredient resep.
        foreach ($recipe->ingredients as $ing) {
            $production->items()->create([
                'product_id'           => $ing->product_id,
                'direction'            => 'in',
                'source'               => 'recipe_default',
                'quantity'             => $ing->quantity,
                'unit_id'              => $ing->unit_id ?? $ing->product?->unit_id,
                'recipe_ingredient_id' => $ing->id,
            ]);
        }
    }

    private function notFound()
    {
        return response()->json([
            'success' => false,
            'message' => 'Produksi tidak ditemukan.',
        ], 404);
    }
}
