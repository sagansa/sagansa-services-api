<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RequestPurchase;
use App\Models\DetailRequest;
use App\Models\InvoicePurchase;
use App\Models\DetailInvoice;
use App\Models\Product;
use App\Models\Asset;
use App\Models\PaymentReceipt;
use App\Models\DailySalary;
use App\Models\FuelService;
use App\Models\Supplier;
use App\Services\ProcurementNotificationService;
use App\Services\QrisService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class ProcurementController extends Controller
{
    public function __construct(protected ProcurementNotificationService $procurementNotification)
    {
    }

    /**
     * Get list of products for creating request purchase.
     */
    public function products(Request $request)
    {
        $products = Product::with('unit')->where('payment_type_id', '!=', '3')->get();

        return response()->json([
            'success' => true,
            'data' => $products
        ]);
    }

    /**
     * Get list of request purchases.
     */
    public function index(Request $request)
    {
        $query = RequestPurchase::with(['store', 'user', 'detailRequests.product.unit']);

        // Staff only see their own requests
        if (!$request->user()->hasRole('admin')) {
            $query->where('user_id', $request->user()->id);
        }

        $perPage = $request->integer('per_page', 20);
        $paginated = $query->orderBy('date', 'desc')->orderBy('id', 'desc')->paginate($perPage);
        $requests = $paginated->items();

        // Hitung statistik invoice untuk user/admin
        $userId = $request->user()->id;
        $isAdmin = $request->user()->hasRole('admin');
        
        $invoiceQuery = InvoicePurchase::query();
        if (!$isAdmin) {
            $invoiceQuery->where('created_by_id', $userId);
        }

        $invoicesCount = [
            'draft' => (clone $invoiceQuery)->where('order_status', 1)->count(),
            'done' => (clone $invoiceQuery)->where('order_status', 2)->count(),
            'unpaid' => (clone $invoiceQuery)->where('payment_status', '!=', 3)->count(),
        ];

        return response()->json([
            'success' => true,
            'data' => $requests,
            'meta' => [
                'invoice_counts' => $invoicesCount
            ],
            'pagination' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
        ]);
    }

    /**
     * Get detail of a specific request purchase.
     */
    public function show($id, Request $request)
    {
        $requestPurchase = RequestPurchase::with([
            'store', 
            'user', 
            'detailRequests.product.unit', 
            'detailRequests.paymentType'
        ])
            ->withCount([
                'detailRequests as invoiced_items_count' => fn ($q) => $q
                    ->whereHas('detailInvoices'),
            ])
            ->find($id);

        if (!$requestPurchase) {
            return response()->json([
                'success' => false,
                'message' => 'Request Purchase tidak ditemukan.'
            ], 404);
        }

        // Staff checking guard
        if (!$request->user()->hasRole('admin') && $requestPurchase->user_id !== $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses ke data ini.'
            ], 403);
        }

        return response()->json([
            'success' => true,
            'data' => $requestPurchase
        ]);
    }

    /**
     * Store new request purchase.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'store_id' => 'required|exists:stores,id',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity_plan' => 'required|numeric|min:1',
            'items.*.payment_type_id' => 'nullable|in:1,2',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal.',
                'errors' => $validator->errors()
            ], 422);
        }

        return DB::transaction(function () use ($request) {
            $requestPurchase = RequestPurchase::create([
                'store_id' => $request->store_id,
                'date' => now()->toDateString(),
                'user_id' => $request->user()->id,
                'status' => 1, // Process
            ]);

            foreach ($request->items as $item) {
                $product = Product::find($item['product_id']);
                $productDefault = $product->payment_type_id ?? 1;
                $plannedPayment = $item['payment_type_id'] ?? $productDefault;

                DetailRequest::create([
                    'request_purchase_id' => $requestPurchase->id,
                    'product_id' => $item['product_id'],
                    'quantity_plan' => $item['quantity_plan'],
                    'store_id' => $request->store_id,
                    'payment_type_id' => $plannedPayment,
                    // Product default Transfer (1) tetapi berencana membayar Tunai (2) -> butuh approval (1)
                    // Selain itu -> langsung approved (4)
                    'status' => ($productDefault == 1 && $plannedPayment == 2) ? '1' : '4',
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Request Purchase berhasil dibuat.',
                'data' => $requestPurchase->load('detailRequests')
            ], 201);
        });
    }

    /**
     * Approve a specific detail request item (Admin only).
     */
    public function approveItem($itemId, Request $request)
    {
        if (!$request->user()->hasRole('admin')) {
            return response()->json([
                'success' => false,
                'message' => 'Hanya Admin yang dapat menyetujui request item.'
            ], 403);
        }

        $item = DetailRequest::find($itemId);

        if (!$item) {
            return response()->json([
                'success' => false,
                'message' => 'Item request tidak ditemukan.'
            ], 404);
        }

        if ($item->status != '1') {
            return response()->json([
                'success' => false,
                'message' => 'Item ini sudah tidak dalam status process.'
            ], 400);
        }

        $item->update(['status' => '4']); // Approved

        return response()->json([
            'success' => true,
            'message' => 'Item request disetujui.',
            'data' => $item
        ]);
    }

    /**
     * Reject a specific detail request item (Admin only).
     */
    public function rejectItem($itemId, Request $request)
    {
        if (!$request->user()->hasRole('admin')) {
            return response()->json([
                'success' => false,
                'message' => 'Hanya Admin yang dapat menolak request item.'
            ], 403);
        }

        $item = DetailRequest::find($itemId);

        if (!$item) {
            return response()->json([
                'success' => false,
                'message' => 'Item request tidak ditemukan.'
            ], 404);
        }

        if ($item->status != '1') {
            return response()->json([
                'success' => false,
                'message' => 'Item ini sudah tidak dalam status process.'
            ], 400);
        }

        $item->update(['status' => '3']); // Reject

        return response()->json([
            'success' => true,
            'message' => 'Item request ditolak.',
            'data' => $item
        ]);
    }

    /**
     * Mark a specific detail request item as Cancel / Not Used (Admin only).
     */
    public function cancelItem($itemId, Request $request)
    {
        if (!$request->user()->hasRole('admin')) {
            return response()->json([
                'success' => false,
                'message' => 'Hanya Admin yang dapat membatalkan request item.'
            ], 403);
        }

        $item = DetailRequest::find($itemId);

        if (!$item) {
            return response()->json([
                'success' => false,
                'message' => 'Item request tidak ditemukan.'
            ], 404);
        }

        if (in_array($item->status, ['2', '3', '5', '6'])) {
            return response()->json([
                'success' => false,
                'message' => 'Item ini tidak dapat dibatalkan (sudah selesai/ditolak/tidak aktif).'
            ], 400);
        }

        $item->update(['status' => '6']); // Not Used

        return response()->json([
            'success' => true,
            'message' => 'Item request berhasil ditandai sebagai tidak digunakan.',
            'data' => $item
        ]);
    }

    /**
     * Get list of invoice purchases.
     */
    public function invoices(Request $request)
    {
        $query = InvoicePurchase::with([
            'store', 'supplier', 'detailInvoices', 'createdBy', 'closingStores:id,date'
        ]);

        // Staff/supervisor only see invoices they created
        if (!$request->user()->hasRole('admin')) {
            $query->where('created_by_id', $request->user()->id);
        }

        if ($request->has('order_status')) {
            $query->where('order_status', $request->order_status);
        }

        if ($request->has('payment_status')) {
            $query->where('payment_status', $request->payment_status);
        }

        if ($request->has('store_id')) {
            $query->where('store_id', $request->store_id);
        }

        if ($request->has('supplier_id')) {
            $query->where('supplier_id', $request->supplier_id);
        }

        $perPage = $request->query('per_page', 10);
        $invoices = $query->orderBy('date', 'desc')->orderBy('id', 'desc')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $invoices->items(),
            'meta' => [
                'current_page' => $invoices->currentPage(),
                'last_page' => $invoices->lastPage(),
                'per_page' => $invoices->perPage(),
                'total' => $invoices->total(),
            ],
        ]);
    }

    /**
     * Kandidat invoice pembelian untuk dikaitkan dari sisi penjualan
     * (kartu di detail penjualan direct): invoice yang SUDAH DIBAYAR (payment_status: 2)
     * dan BELUM terkait order lain. Mendukung opsional param ?payment_status
     * (default '2'). Guard sama dengan perubahan kaitan:
     * admin/super_admin + storage-staff.
     */
    public function invoiceLinkCandidates(Request $request)
    {
        $user = $request->user();
        $allowed = $user
            && ($user->hasRole('admin') || $user->hasRole('super_admin')
                || $user->hasRole('storage-staff'));
        if (!$allowed) {
            return response()->json([
                'success' => false,
                'message' => 'Hanya admin dan storage-staff yang dapat mencari invoice pembelian.',
            ], 403);
        }

        $q = trim((string) $request->query('q', ''));
        $paymentStatus = (string) $request->query('payment_status', '2');

        $query = InvoicePurchase::query()
            ->leftJoin('suppliers', 'suppliers.id', '=', 'invoice_purchases.supplier_id')
            ->whereNull('invoice_purchases.sales_order_id');

        if ($paymentStatus === 'all') {
            // Tanpa filter payment_status
        } elseif (str_contains($paymentStatus, ',')) {
            $query->whereIn('invoice_purchases.payment_status', explode(',', $paymentStatus));
        } else {
            $query->where('invoice_purchases.payment_status', $paymentStatus);
        }

        $query->select(
            'invoice_purchases.id',
            'invoice_purchases.total_price',
            'invoice_purchases.payment_status',
            'invoice_purchases.order_status',
            'invoice_purchases.date',
            'suppliers.name as supplier_name'
        );

        if ($q !== '') {
            $query->where(function ($sub) use ($q) {
                if (is_numeric($q)) {
                    $sub->where('invoice_purchases.id', (int) $q);
                }
                $sub->orWhere('suppliers.name', 'like', "%{$q}%");
            });
            $limit = 10;
        } else {
            $limit = 20;
        }

        $rows = $query->orderByDesc('invoice_purchases.id')->limit($limit)->get();

        return response()->json([
            'success' => true,
            'data' => $rows,
        ]);
    }

    /**
     * Get detail of a specific invoice purchase.
     */
    public function showInvoice($id, Request $request)
    {
        $invoice = InvoicePurchase::with([
            'store', 'supplier.bank', 'createdBy',
            'detailInvoices.detailRequest.product.unit',
            'detailInvoices.detailRequest.paymentType',
            'salesOrder', 'salesOrder.store', 'closingStores:id,date',
        ])->find($id);

        if (!$invoice) {
            return response()->json([
                'success' => false,
                'message' => 'Invoice tidak ditemukan.'
            ], 404);
        }

        // Admin: append harga beli terakhir per item (lintas supplier) untuk
        // evaluasi. Staff tidak butuh info ini.
        $user = $request->user();
        $isAdmin = $user && ($user->hasRole('admin') || $user->hasRole('super_admin'));
        if ($isAdmin) {
            $invoice->detailInvoices->each(function ($detail) {
                $detail->append('last_purchase_price');
            });
        }

        return response()->json([
            'success' => true,
            'data' => $invoice
        ]);
    }

    /**
     * Mark an Invoice Purchase as received (order_status: 1 -> 2).
     * Allowed for staff, admin, super_admin. Not reversible from API.
     */
    public function receiveInvoice($id, Request $request)
    {
        $invoice = InvoicePurchase::find($id);

        if (!$invoice) {
            return response()->json([
                'success' => false,
                'message' => 'Invoice tidak ditemukan.'
            ], 404);
        }

        $user = $request->user();
        $canReceive = $user->hasRole('staff')
            || $user->hasRole('admin')
            || $user->hasRole('super_admin');

        if (!$canReceive) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses untuk menerima invoice ini.'
            ], 403);
        }

        if ($invoice->order_status !== '1') {
            return response()->json([
                'success' => false,
                'message' => 'Invoice sudah diterima atau berstatus lain.'
            ], 400);
        }

        $invoice->order_status = '2';
        $invoice->save();

        return response()->json([
            'success' => true,
            'message' => 'Invoice ditandai sudah diterima.',
            'data' => $invoice->load([
                'store', 'supplier', 'createdBy',
                'detailInvoices.detailRequest.product.unit',
                'detailInvoices.detailRequest.paymentType',
            ]),
        ]);
    }

    /**
     * Auto-create Invoice from approved items.
     */
    public function createInvoice($id, Request $request)
    {
        $request->validate([
            'supplier_id' => 'required|exists:suppliers,id',
            'items' => 'required|array|min:1',
            'items.*.detail_request_id' => 'required|exists:detail_requests,id',
            'items.*.price' => 'nullable|numeric|min:0',
            'items.*.subtotal_invoice' => 'nullable|numeric|min:0',
            'items.*.quantity' => 'required|numeric|min:1',
            'request_ids' => 'nullable|array',
            'request_ids.*' => 'integer|exists:request_purchases,id',
            'payment_type_id' => 'nullable|integer|in:1,2',
            'taxes' => 'nullable|numeric|min:0',
            'discounts' => 'nullable|numeric|min:0',
            'image' => 'nullable|string',
        ]);

        // Blacklist gating: tolak supplier yang diblacklist
        $supplier = Supplier::find($request->supplier_id);
        if ($supplier && $supplier->status == 3) {
            return response()->json([
                'success' => false,
                'message' => 'Supplier Blacklist tidak dapat dipakai.'
            ], 422);
        }

        // Frontend mengirim subtotal_invoice (source of truth) atau price
        // (backward compat). Minimal salah satu wajib ada per item.
        foreach ($request->items as $item) {
            $hasPrice = array_key_exists('price', $item) && $item['price'] !== null && $item['price'] !== '';
            $hasSubtotal = array_key_exists('subtotal_invoice', $item) && $item['subtotal_invoice'] !== null && $item['subtotal_invoice'] !== '';
            if (!$hasPrice && !$hasSubtotal) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tiap item wajib mengirim price atau subtotal_invoice.'
                ], 422);
            }
        }

        $requestPurchase = RequestPurchase::find($id);

        if (!$requestPurchase) {
            return response()->json([
                'success' => false,
                'message' => 'Request Purchase tidak ditemukan.'
            ], 404);
        }

        // Kumpulkan request-id yang diizinkan untuk item lintas-request.
        // Default: hanya request dari URL ($id). Bila frontend mengirim
        // 'request_ids', item boleh berasal dari request mana pun di dalamnya
        // (selama berasal dari store yang sama & status disetujui).
        $allowedRequestIds = collect($request->input('request_ids', []));
        if ($allowedRequestIds->isEmpty()) {
            $allowedRequestIds = collect([$id]);
        }

        $detailRequestIds = collect($request->items)->pluck('detail_request_id');

        // Verifikasi item: disetujui (status 4) DAN milik salah satu request
        // yang diizinkan DAN store-nya sama dengan request utama.
        $validItems = DetailRequest::with('requestPurchase')
            ->whereIn('id', $detailRequestIds)
            ->where('status', '4')
            ->whereIn('request_purchase_id', $allowedRequestIds)
            ->get()
            ->filter(function ($dr) use ($requestPurchase) {
                return $dr->requestPurchase
                    && $dr->requestPurchase->store_id == $requestPurchase->store_id;
            });

        if ($validItems->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Item yang dipilih tidak valid, belum disetujui, atau bukan dari toko yang sama.'
            ], 400);
        }

        // Check for duplicate items from different requests (already scoped by request id)
        $result = DB::transaction(function () use ($requestPurchase, $validItems, $request) {
            $totalPrice = 0;
            $itemData = [];
            foreach ($request->items as $item) {
                $detailRequest = $validItems->firstWhere('id', $item['detail_request_id']);
                if (!$detailRequest) continue;

                // subtotal_invoice (total per item dari user) adalah source of
                // truth; price × qty hanya fallback utk backward-compat.
                $subtotal = (array_key_exists('subtotal_invoice', $item) && $item['subtotal_invoice'] !== null)
                    ? (int) $item['subtotal_invoice']
                    : (int) ($item['price'] ?? 0) * (int) $item['quantity'];
                $totalPrice += $subtotal;
                $itemData[] = [
                    'detail_request' => $detailRequest,
                    'price' => (int) ($item['price'] ?? 0),
                    'quantity' => (int) $item['quantity'],
                    'subtotal' => $subtotal,
                ];
            }

            $taxes = (int) ($request->taxes ?? 0);
            $discounts = (int) ($request->discounts ?? 0);
            $totalPrice = $totalPrice + $taxes - $discounts;

            $invoice = InvoicePurchase::create([
                'store_id' => $requestPurchase->store_id,
                'date' => now()->toDateString(),
                'payment_status' => '1',
                'order_status' => '1',
                'created_by_id' => $request->user()->id,
                'payment_type_id' => $request->input('payment_type_id', 2),
                'total_price' => $totalPrice,
                'supplier_id' => $request->supplier_id,
                'taxes' => $taxes,
                'discounts' => $discounts,
                'image' => $request->image,
            ]);

            $assetsCreated = 0;

            foreach ($itemData as $data) {
                $detailRequest = $data['detail_request'];

                $detailInvoice = DetailInvoice::create([
                    'invoice_purchase_id' => $invoice->id,
                    'detail_request_id' => $detailRequest->id,
                    'quantity_product' => $data['quantity'],
                    'subtotal_invoice' => $data['subtotal'],
                    'status' => '3',
                ]);

                // Auto-create assets if product is flagged as asset
                $product = Product::with('assetCategory')->find($detailRequest->product_id);
                if ($product && $product->is_asset && $product->asset_category_id) {
                    $qty = max(1, $data['quantity']);
                    for ($i = 0; $i < $qty; $i++) {
                        Asset::create([
                            'code' => Asset::generateCode(),
                            'name' => $product->name,
                            'product_id' => $product->id,
                            'asset_category_id' => $product->asset_category_id,
                            'store_id' => $requestPurchase->store_id,
                            'condition' => Asset::CONDITION_BAIK,
                            'status' => Asset::STATUS_AKTIF,
                            'purchase_date' => now()->toDateString(),
                            'next_check_at' => $product->assetCategory
                                ? $product->assetCategory->computeNextCheckAt()
                                : now()->addDays(30),
                            'source_detail_invoice_id' => $detailInvoice->id,
                            'created_by_id' => $request->user()->id,
                        ]);
                        $assetsCreated++;
                    }
                }
            }

            return [
                'invoice' => $invoice,
                'assets_created' => $assetsCreated,
            ];
        });

        // Push only untuk invoice Transfer (payment_type_id == 1), di luar
        // transaction agar kegagalan FCM tidak me-rollback invoice. Kegagalan
        // notifikasi (mis. tabel device_tokens belum ada) tidak boleh
        // menggagalkan request utama — invoice sudah tersimpan.
        if ((int) $result['invoice']->payment_type_id === 1) {
            try {
                $this->procurementNotification->notifyInvoiceTransferCreated(
                    $result['invoice'],
                    $request->user()->id
                );
            } catch (\Throwable $e) {
                Log::error('Gagal kirim notifikasi invoice transfer (createInvoice).', [
                    'invoice_id' => $result['invoice']->id,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Invoice berhasil dibuat.'
                . ($result['assets_created'] > 0 ? " {$result['assets_created']} aset baru otomatis tercatat." : ''),
            'invoice_id' => $result['invoice']->id,
            'assets_created' => $result['assets_created'],
        ]);
    }

    public function updateInvoice($id, Request $request)
    {
        $user = $request->user();
        $invoice = InvoicePurchase::with('detailInvoices')->find($id);

        if (!$invoice) {
            return response()->json([
                'success' => false,
                'message' => 'Invoice tidak ditemukan.'
            ], 404);
        }

        $isAdmin = $user->hasRole('admin') || $user->hasRole('super_admin');
        $isStaff = $user->hasRole('staff');
        $isStorageStaff = $user->hasRole('storage-staff');

        // Kaitan Penjualan Terkait (sales_order_id) hanya untuk storage-staff
        // dan admin — termasuk menolak staf/pembuat invoice sendiri.
        if ($request->has('sales_order_id') && !$isAdmin && !$isStorageStaff) {
            return response()->json([
                'success' => false,
                'message' => 'Hanya storage-staff dan admin yang dapat mengubah kaitan penjualan.',
            ], 403);
        }

        // Link/unlink murni (tanpa field edit lain) bukan edit penuh: pemanggil
        // storage-staff tetap boleh meski bukan staf/pembuat invoice.
        $onlySalesLink = $request->has('sales_order_id') &&
            !$request->hasAny(['supplier_id', 'payment_type_id', 'taxes',
                'discounts', 'notes', 'image', 'items']);

        // Edit penuh (items, harga, supplier, diskon, pajak, catatan, dll.)
        // hanya boleh untuk invoice draft. Kaitan penjualan (sales_order_id)
        // boleh diubah untuk invoice yang sudah dibayar (payment_status 2).
        if (!$onlySalesLink && $invoice->payment_status != '1') {
            return response()->json([
                'success' => false,
                'message' => 'Hanya invoice draft yang dapat diedit.'
            ], 400);
        }

        if (!$isAdmin && !$isStaff && !$isStorageStaff && !$onlySalesLink &&
            $invoice->created_by_id != $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses untuk mengedit invoice ini.'
            ], 403);
        }

        $request->validate([
            'supplier_id' => 'nullable|exists:suppliers,id',
            'payment_type_id' => 'nullable|integer',
            'taxes' => 'nullable|numeric|min:0',
            'discounts' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
            'image' => 'nullable|string',
            'items' => 'nullable|array',
            'items.*.detail_invoice_id' => 'required_with:items|exists:detail_invoices,id',
            'items.*.price' => 'nullable|numeric|min:0',
            'items.*.subtotal_invoice' => 'nullable|numeric|min:0',
            'items.*.quantity' => 'required_with:items|numeric|min:1',
            'sales_order_id' => 'nullable',
        ]);

        // Blacklist gating: tolak supplier yang diblacklist
        if ($request->filled('supplier_id')) {
            $supplier = Supplier::find($request->supplier_id);
            if ($supplier && $supplier->status == 3) {
                return response()->json([
                    'success' => false,
                    'message' => 'Supplier Blacklist tidak dapat dipakai.'
                ], 422);
            }
        }

        // Minimal salah satu dari price / subtotal_invoice wajib ada per item.
        if ($request->has('items')) {
            foreach ($request->items as $item) {
                $hasPrice = array_key_exists('price', $item) && $item['price'] !== null && $item['price'] !== '';
                $hasSubtotal = array_key_exists('subtotal_invoice', $item) && $item['subtotal_invoice'] !== null && $item['subtotal_invoice'] !== '';
                if (!$hasPrice && !$hasSubtotal) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Tiap item wajib mengirim price atau subtotal_invoice.'
                    ], 422);
                }
            }
        }

        // Validasi sales_order_id SEKALI di sini, sebelum transaksi & sebelum
        // penulisan apa pun, agar 422 tidak meninggalkan partial write.
        // null / '' (setelah trim) → lepas kaitan (null); numerik → set
        // (+ cek eksistensi, 422 bila tidak ada); terisi non-numerik
        // ('abc', '12abc', array, dsb.) → 422, BUKAN diam-diam melepas kaitan.
        if ($request->has('sales_order_id')) {
            $value = $request->sales_order_id;
            $trimmed = is_string($value) ? trim($value) : $value;
            if ($trimmed === null || $trimmed === '') {
                $invoice->sales_order_id = null;
            } elseif (!is_numeric($trimmed) || is_array($trimmed)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Sales order tidak valid.',
                ], 422);
            } else {
                $invoice->sales_order_id = (int) $trimmed;
                // Validasi eksistensi manual (termasuk soft-deleted → tidak ada).
                if (!\Illuminate\Support\Facades\DB::table('sales_orders')
                    ->where('id', $invoice->sales_order_id)
                    ->whereNull('deleted_at')
                    ->exists()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Sales order tidak ditemukan.',
                    ], 422);
                }
            }
        }

        return DB::transaction(function () use ($invoice, $request) {
            if ($request->filled('supplier_id')) {
                $invoice->supplier_id = $request->supplier_id;
            }
            if ($request->filled('payment_type_id')) {
                $invoice->payment_type_id = $request->payment_type_id;
            }
            if ($request->has('taxes')) {
                $invoice->taxes = (int) ($request->taxes ?? 0);
            }
            if ($request->has('discounts')) {
                $invoice->discounts = (int) ($request->discounts ?? 0);
            }
            if ($request->has('notes')) {
                $invoice->notes = $request->notes;
            }
            if ($request->has('image')) {
                $invoice->image = $request->image;
            }

            $totalPrice = 0;
            if ($request->has('items')) {
                foreach ($request->items as $item) {
                    $detail = $invoice->detailInvoices
                        ->firstWhere('id', $item['detail_invoice_id']);
                    if (!$detail) continue;
                    // subtotal_invoice (total per item dari user) adalah source of
                    // truth; price × qty hanya fallback utk backward-compat.
                    $subtotal = (array_key_exists('subtotal_invoice', $item) && $item['subtotal_invoice'] !== null)
                        ? (int) $item['subtotal_invoice']
                        : (int) ($item['price'] ?? 0) * (int) $item['quantity'];
                    $detail->update([
                        'quantity_product' => $item['quantity'],
                        'subtotal_invoice' => $subtotal,
                    ]);
                    $totalPrice += $subtotal;
                }
            } else {
                $totalPrice = $invoice->detailInvoices->sum('subtotal_invoice');
            }

            $invoice->total_price = $totalPrice + ($invoice->taxes ?? 0) - ($invoice->discounts ?? 0);
            $invoice->save();

            return response()->json([
                'success' => true,
                'message' => 'Invoice berhasil diperbarui.',
                'data' => $invoice->load([
                    'store', 'supplier', 'createdBy', 'salesOrder',
                    'detailInvoices.detailRequest.product.unit',
                    'detailInvoices.detailRequest.paymentType',
                ]),
            ]);
        });
    }

    /**
     * Ganti image invoice SAJA — admin/super_admin/staff.
     * Skenario: bayar dulu, invoice (foto) final dari supplier keluar belakangan.
     * TERKECUALI invoice yang sudah final: payment_status '2' (dibayar) DAN
     * order_status '2' (diterima) → foto dikunci (422).
     * Tidak menyentuh payment_status / field lain.
     */
    public function updateInvoiceImage($id, Request $request)
    {
        $user = $request->user();
        $allowed = $user
            && ($user->hasRole('admin') || $user->hasRole('super_admin')
                || $user->hasRole('staff'));
        if (!$allowed) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses untuk mengubah foto invoice.',
            ], 403);
        }

        $invoice = InvoicePurchase::find($id);
        if (!$invoice) {
            return response()->json([
                'success' => false,
                'message' => 'Invoice tidak ditemukan.',
            ], 404);
        }

        // Foto dikunci ketika invoice sudah final: SUDAH DIBAYAR
        // (payment_status '2') DAN SUDAH DITERIMA (order_status '2').
        // Kondisi lain (mis. dibayar tapi barang belum diterima) tetap
        // boleh diganti — skenario invoice final keluar belakangan.
        if ($invoice->payment_status == '2' && $invoice->order_status == '2') {
            return response()->json([
                'success' => false,
                'message' => 'Foto invoice tidak dapat diganti karena invoice sudah dibayar dan diterima.',
            ], 422);
        }

        $request->validate([
            'image' => 'required|string',
        ]);

        // Img service men-escape '/' menjadi '\/' di body JSON (default
        // json_encode PHP); build app lama mengekstraknya via regex sehingga
        // backslash ikut terkirim ke sini. Normalkan sebelum divalidasi.
        $newPath = str_replace('\\/', '/', trim((string) $request->input('image')));

        // Endpoint ini menghapus file lama — path baru wajib berada di folder
        // invoice agar tidak bisa menunjuk path storage arbitrer (mis.
        // 'images/Delivery/evil.webp').
        if (!str_starts_with($newPath, 'images/InvoicePurchase/')) {
            // Nilai yang ditolak dicatat & ditampilkan agar penyimpangan format
            // path dari img service langsung terlihat saat insiden (mis. build
            // img lama yang mengembalikan path tanpa directory).
            \Illuminate\Support\Facades\Log::warning('updateInvoiceImage: path image ditolak.', [
                'invoice_id' => $invoice->id,
                'user_id' => $user->id,
                'received' => $newPath,
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Path image tidak valid. Diterima: ' . \Illuminate\Support\Str::limit($newPath, 80, ''),
            ], 422);
        }

        // Baris lama di DB bisa tersimpan ter-escape juga (upload via build
        // app lama) — normalkan agar penghapusan file lama mengenai path benar.
        $oldPath = $invoice->image
            ? str_replace('\\/', '/', $invoice->image)
            : null;

        if ($oldPath && $oldPath !== $newPath) {
            try {
                app(\App\Contracts\ImageStorageContract::class)->delete($oldPath);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Gagal hapus image invoice lama.', [
                    'invoice_id' => $invoice->id,
                    'path' => $oldPath,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        $invoice->image = $newPath;
        $invoice->save();

        return response()->json([
            'success' => true,
            'message' => 'Foto invoice berhasil diperbarui.',
            'data' => [
                'id' => $invoice->id,
                'image' => $invoice->image,
                'image_url' => \App\Support\ImageUrlResolver::resolve($invoice->image),
            ],
        ]);
    }

    /**
     * Get list of payment receipts (for invoice purchases).
     */
    public function paymentReceipts(Request $request)
    {
        // Filter by payment_for (1=FuelService, 2=DailySalary, 3=InvoicePurchase).
        // Default to InvoicePurchase (3) hanya bila param TIDAK dikirim, agar
        // tetap backward-compatible dengan caller lama yang mengharapkan list
        // invoice receipts. Caller baru (mis. tab Pembayaran fuel service) kirim
        // payment_for=1 untuk mendapatkan receipt fuel/service.
        $query = PaymentReceipt::with([
            'invoicePurchases.store', 'invoicePurchases.supplier', 'supplier',
            'fuelServices.vehicle', 'fuelServices.createdBy',
            'dailySalaries.createdBy',
        ]);

        if ($request->filled('payment_for')) {
            $query->where('payment_for', $request->input('payment_for'));
        } else {
            $query->where('payment_for', '3'); // backward-compat default
        }

        if ($request->has('invoice_id')) {
            $query->whereHas('invoicePurchases', fn ($q) => $q->where('invoice_purchase_id', $request->invoice_id));
        }

        $perPage = $request->query('per_page', 10);
        $receipts = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $receipts->items(),
            'meta' => [
                'current_page' => $receipts->currentPage(),
                'last_page' => $receipts->lastPage(),
                'per_page' => $receipts->perPage(),
                'total' => $receipts->total(),
            ],
        ]);
    }

    /**
     * Get detail of a specific payment receipt.
     */
    public function showPaymentReceipt($id, Request $request)
    {
        $receipt = PaymentReceipt::with([
            'invoicePurchases.store',
            'invoicePurchases.supplier',
            'invoicePurchases.detailInvoices.detailRequest.product.unit',
            'fuelServices.vehicle',
            'fuelServices.createdBy',
            'fuelServices.supplier',
            'dailySalaries.createdBy',
            'supplier',
        ])->find($id);

        if (!$receipt) {
            return response()->json([
                'success' => false,
                'message' => 'Payment receipt tidak ditemukan.'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $receipt
        ]);
    }

    /**
     * Get QRIS payload for a payment receipt.
     */
    public function paymentReceiptQris($id, Request $request)
    {
        if ($request->user()->hasRole('staff')) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses untuk generate QRIS.'
            ], 403);
        }

        $receipt = PaymentReceipt::with('supplier')->find($id);

        if (!$receipt) {
            return response()->json([
                'success' => false,
                'message' => 'Payment receipt tidak ditemukan.'
            ], 404);
        }

        if (!$receipt->supplier || !$receipt->supplier->qris) {
            Log::warning('paymentReceiptQris: supplier/qris kosong', [
                'receipt_id' => $receipt->id,
                'supplier_id' => $receipt->supplier_id,
                'supplier_name' => $receipt->supplier?->name,
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Supplier tidak memiliki data QRIS.'
            ], 400);
        }

        $receiptAmount = $receipt->transfer_amount ?? $receipt->total_amount ?? 0;
        if (empty($receiptAmount) || $receiptAmount <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'Nominal payment receipt kosong. QRIS dinamis butuh transfer_amount/total_amount lebih dari nol.'
            ], 400);
        }

        try {
            $qrisService = app(QrisService::class);
            $dynamicPayload = $qrisService->generateDynamicPayload(
                $receipt->supplier->qris,
                $receiptAmount
            );

            $parsed = $qrisService->parsePayload($receipt->supplier->qris);

            return response()->json([
                'success' => true,
                'data' => [
                    'payload' => $dynamicPayload,
                    'merchant_name' => $parsed['merchant_name'] ?? null,
                    'merchant_nmid' => $qrisService->getMerchantNmid($parsed),
                    'amount' => $receipt->transfer_amount ?? $receipt->total_amount,
                    'raw_supplier_qris' => $receipt->supplier->qris,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal generate QRIS: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get QRIS payload for an invoice purchase (nominal = total invoice).
     */
    public function invoiceQris($id, Request $request)
    {
        if ($request->user()->hasRole('staff')) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses untuk generate QRIS.'
            ], 403);
        }

        $invoice = InvoicePurchase::with('supplier')->find($id);

        if (!$invoice) {
            return response()->json([
                'success' => false,
                'message' => 'Invoice tidak ditemukan.'
            ], 404);
        }

        if (!$invoice->supplier || !$invoice->supplier->qris) {
            Log::warning('invoiceQris: supplier/qris kosong', [
                'invoice_id' => $invoice->id,
                'supplier_id' => $invoice->supplier_id,
                'supplier_name' => $invoice->supplier?->name,
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Supplier belum memiliki data QRIS — lengkapi field QRIS di form supplier.'
            ], 400);
        }

        $invoiceAmount = $invoice->total_price ?? 0;
        if (empty($invoiceAmount) || $invoiceAmount <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'Nominal invoice kosong. QRIS dinamis butuh total_price lebih dari nol.'
            ], 400);
        }

        try {
            $qrisService = app(QrisService::class);
            $dynamicPayload = $qrisService->generateDynamicPayload(
                $invoice->supplier->qris,
                $invoiceAmount
            );

            $parsed = $qrisService->parsePayload($invoice->supplier->qris);

            return response()->json([
                'success' => true,
                'data' => [
                    'payload' => $dynamicPayload,
                    'merchant_name' => $parsed['merchant_name'] ?? null,
                    'merchant_nmid' => $qrisService->getMerchantNmid($parsed),
                    'amount' => $invoice->total_price,
                    'raw_supplier_qris' => $invoice->supplier->qris,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal generate QRIS: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create a new payment receipt for invoice purchases.
     */
    public function storePaymentReceipt(Request $request)
    {
        $user = $request->user();
        if ($user->hasRole('staff') || $user->hasRole('storage-staff')) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses untuk membuat bukti pembayaran (payment receipt).'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'payment_for' => 'nullable|in:2,3',
            'invoice_ids' => 'nullable|array|min:1',
            'invoice_ids.*' => 'exists:invoice_purchases,id',
            'daily_salary_ids' => 'nullable|array|min:1',
            'daily_salary_ids.*' => 'exists:daily_salaries,id',
            'transfer_amount' => 'required|numeric|min:1',
            'total_amount' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:500',
            'image' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal.',
                'errors' => $validator->errors()
            ], 422);
        }

        $paymentFor = $request->input('payment_for', '3');

        // Loose comparison: client multipart mengirim string '2', client JSON
        // mengirim int 2.
        if ($paymentFor == '2') {
            return $this->storeDailySalaryPaymentReceipt($request);
        }

        $invoiceIds = $request->invoice_ids;

        // Verify all invoices are unpaid and Transfer payment type
        $invoices = InvoicePurchase::whereIn('id', $invoiceIds)->get();
        foreach ($invoices as $inv) {
            // Loose comparison (!=): payment_status bisa string '1' atau int 1.
            // Lihat InvoicePurchase::$casts yang menormalisasi ke string.
            if ($inv->payment_status != '1') {
                return response()->json([
                    'success' => false,
                    'message' => "Invoice #{$inv->id} sudah dibayar atau tidak valid."
                ], 400);
            }
            if ($inv->payment_type_id != 1) {
                return response()->json([
                    'success' => false,
                    'message' => "Invoice #{$inv->id} bukan metode Transfer."
                ], 400);
            }
        }

        $receipt = DB::transaction(function () use ($request, $invoiceIds, $invoices) {
            $totalAmount = $request->total_amount ?? $invoices->sum('total_price');
            $firstInvoice = $invoices->first();

            $imagePath = null;
            if ($request->filled('image')) {
                $imagePath = $request->input('image');
            }

            $receipt = PaymentReceipt::create([
                'payment_for' => '3',
                'total_amount' => (int) $totalAmount,
                'transfer_amount' => (int) $request->transfer_amount,
                'supplier_id' => $firstInvoice->supplier_id,
                'user_id' => $request->user()->id,
                'notes' => $request->notes,
                'image' => $imagePath,
            ]);

            // Attach invoices to receipt
            $receipt->invoicePurchases()->attach($invoiceIds);

            // Update invoice payment status to paid
            foreach ($invoices as $inv) {
                $inv->update(['payment_status' => '2']);
            }

            return $receipt;
        });

        // Di luar transaction: kegagalan FCM tidak boleh membatalkan pembayaran.
        $this->procurementNotification->notifyPaymentReceiptPaid($receipt);

        return response()->json([
            'success' => true,
            'message' => 'Payment receipt berhasil dibuat.',
            'data' => $receipt->load('invoicePurchases')
        ], 201);
    }

    /**
     * Create a payment receipt untuk daily salary (transfer payment).
     *
     * payment_for = '2' (DailySalary). Daily salary harus berstatus '1'
     * (belum dibayar) atau '3' (siap dibayar) dan metode Transfer, lalu
     * di-update menjadi '2' (dibayar) setelah receipt dibuat (mirip invoice
     * yang berubah payment_status).
     */
    private function storeDailySalaryPaymentReceipt(Request $request)
    {
        $dailySalaryIds = $request->daily_salary_ids;

        $salaries = DailySalary::whereIn('id', $dailySalaryIds)->get();

        if ($salaries->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Daily salary tidak ditemukan.'
            ], 400);
        }

        foreach ($salaries as $salary) {
            if (!in_array($salary->status, ['1', '3'])) {
                return response()->json([
                    'success' => false,
                    'message' => "Daily salary #{$salary->id} tidak dapat dibayar (status saat ini tidak diizinkan)."
                ], 400);
            }
            if ($salary->payment_type_id != 1) {
                return response()->json([
                    'success' => false,
                    'message' => "Daily salary #{$salary->id} bukan metode Transfer."
                ], 400);
            }
        }

        // Semua daily salary harus milik karyawan yang sama (aturan "satu
        // karyawan" yang selama ini hanya ada di client mobile). Backend
        // menurunkan penerima receipt dari created_by_id salary, bukan dari
        // admin yang login.
        $owners = $salaries->pluck('created_by_id')->filter()->unique();
        if ($owners->count() > 1) {
            return response()->json([
                'success' => false,
                'message' => 'Pilih daily salary dari karyawan yang sama.'
            ], 400);
        }

        $receipt = DB::transaction(function () use ($request, $dailySalaryIds, $salaries) {
            $totalAmount = $request->total_amount ?? $salaries->sum('amount');

            $imagePath = null;
            if ($request->filled('image')) {
                $imagePath = $request->input('image');
            }

            $receipt = PaymentReceipt::create([
                'payment_for' => '2',
                'total_amount' => (int) $totalAmount,
                'transfer_amount' => (int) $request->transfer_amount,
                'user_id' => $salaries->first()->created_by_id,
                'notes' => $request->notes,
                'image' => $imagePath,
            ]);

            // Attach daily salaries to receipt
            $receipt->dailySalaries()->attach($dailySalaryIds);

            // Update daily salary status menjadi dibayar (2)
            foreach ($salaries as $salary) {
                $salary->update(['status' => '2']);
            }

            return $receipt;
        });

        // Di luar transaction: kegagalan FCM tidak boleh membatalkan pembayaran.
        // payer = admin yang melakukan pembayaran (bukan penerima receipt yang
        // kini = karyawan), supaya admin tidak menerima notifikasi atas
        // pembayaran yang dia sendiri lakukan.
        $this->procurementNotification->notifyPaymentReceiptPaid($receipt, $request->user()->id);

        return response()->json([
            'success' => true,
            'message' => 'Payment receipt gaji berhasil dibuat.',
            'data' => $receipt->load('dailySalaries.createdBy')
        ], 201);
    }

    /**
     * Create a new payment receipt for fuel services (transfer payment).
     *
     * Mirror dengan storePaymentReceipt (invoice) tapi untuk fuel_services.
     * payment_for = '1' (FuelService).
     */
    public function storeFuelServicePaymentReceipt(Request $request)
    {
        $user = $request->user();
        if ($user->hasRole('staff') || $user->hasRole('storage-staff')) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses untuk membuat bukti pembayaran (payment receipt).'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'fuel_service_ids' => 'required|array|min:1',
            'fuel_service_ids.*' => 'exists:fuel_services,id',
            'transfer_amount' => 'required|numeric|min:1',
            'total_amount' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:500',
            'image' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal.',
                'errors' => $validator->errors()
            ], 422);
        }

        $fuelServiceIds = $request->fuel_service_ids;

        // Verify all fuel services are Transfer + pending (status=1).
        $fuelServices = FuelService::with('supplier')->whereIn('id', $fuelServiceIds)->get();
        foreach ($fuelServices as $fs) {
            if ($fs->status != '1') {
                return response()->json([
                    'success' => false,
                    'message' => "Bensin/Servis #{$fs->id} sudah dibayar atau tidak valid."
                ], 400);
            }
            if ($fs->payment_type_id != 1) {
                return response()->json([
                    'success' => false,
                    'message' => "Bensin/Servis #{$fs->id} bukan metode Transfer."
                ], 400);
            }
        }

        // Validasi supplier: hanya item Servis (fuel_service == '2') yang
        // dibayar ke rekening supplier, sehingga wajib satu supplier sama.
        // Item Bensin (fuel_service == '1') adalah reimbursement ke rekening
        // user pembuat item — beda supplier (atau tanpa supplier) diijinkan.
        $serviceItems = $fuelServices->where('fuel_service', '2');
        $serviceSupplierIds = $serviceItems->pluck('supplier_id')->unique()->filter()->values();
        if (!$serviceItems->isEmpty()) {
            if ($serviceSupplierIds->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Item servis wajib memiliki data supplier. Lengkapi data supplier pada item servis terlebih dahulu.'
                ], 422);
            }
            if ($serviceSupplierIds->count() > 1) {
                return response()->json([
                    'success' => false,
                    'message' => 'Item servis yang dipilih berasal dari supplier berbeda. Pilih item servis dari supplier yang sama untuk satu pembayaran.'
                ], 422);
            }
            $commonSupplierId = $serviceSupplierIds->first();
        } else {
            // Semua item Bensin: supplier_id hanya diisi bila seluruh item
            // berasal dari satu supplier yang sama; selain itu null karena
            // pembayaran ditujukan ke rekening pembuat, bukan supplier.
            $fuelSupplierIds = $fuelServices->pluck('supplier_id')->unique()->filter()->values();
            $commonSupplierId = $fuelSupplierIds->count() === 1 ? $fuelSupplierIds->first() : null;
        }

        $receipt = DB::transaction(function () use ($request, $fuelServiceIds, $fuelServices, $commonSupplierId) {
            $totalAmount = $request->total_amount ?? $fuelServices->sum('amount');

            $imagePath = null;
            if ($request->filled('image')) {
                $imagePath = $request->input('image');
            }

            $receipt = PaymentReceipt::create([
                'payment_for' => '1', // FuelService
                'total_amount' => (int) $totalAmount,
                'transfer_amount' => (int) $request->transfer_amount,
                'supplier_id' => $commonSupplierId,
                'user_id' => $request->user()->id,
                'notes' => $request->notes,
                'image' => $imagePath,
            ]);

            // Attach fuel services to receipt
            $receipt->fuelServices()->attach($fuelServiceIds);

            // Update fuel service status to paid (status='2').
            // DIAGNOSTIC: log before/after status per id + affected-rows count
            // untuk menyelidiki laporan "sebagian item tetap Pending setelah
            // dibayar". Jika affected_rows < jumlah id, berarti update diam-diam
            // melewatkan beberapa baris (mass-assignment / scope / cache).
            $before = FuelService::whereIn('id', $fuelServiceIds)
                ->pluck('status', 'id')
                ->all();

            foreach ($fuelServices as $fs) {
                $fs->update(['status' => '2']);
            }

            $after = FuelService::whereIn('id', $fuelServiceIds)
                ->pluck('status', 'id')
                ->all();

            Log::info('FuelService payment receipt created', [
                'receipt_id' => $receipt->id,
                'requested_ids' => $fuelServiceIds,
                'requested_count' => count($fuelServiceIds),
                'queried_count' => $fuelServices->count(),
                'status_before' => $before,
                'status_after' => $after,
                'still_pending_after' => collect($after)
                    ->filter(fn ($s) => $s != '2')
                    ->keys()
                    ->values()
                    ->all(),
            ]);

            return $receipt;
        });

        // Di luar transaction: kegagalan FCM tidak boleh membatalkan pembayaran.
        $this->procurementNotification->notifyPaymentReceiptPaid($receipt);

        return response()->json([
            'success' => true,
            'message' => 'Payment receipt berhasil dibuat.',
            'data' => $receipt->load('fuelServices.supplier')
        ], 201);
    }

    /**
     * Update fuel-service payment receipt: edit daftar item (add/remove)
     * dengan sinkronisasi status dua arah, plus metadata (transfer_amount,
     * notes, image). total_amount selalu dihitung ulang dari sum amount item.
     *
     * Otorisasi: hanya admin/super_admin. Hanya berlaku payment_for == '1'.
     */
    public function updateFuelServicePaymentReceipt(Request $request, $id)
    {
        $user = $request->user();
        if (!($user->hasRole('admin') || $user->hasRole('super_admin'))) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses untuk mengedit payment receipt.'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'fuel_service_ids' => 'required|array|min:1',
            'fuel_service_ids.*' => 'exists:fuel_services,id',
            'transfer_amount' => 'required|numeric|min:1',
            'notes' => 'nullable|string|max:500',
            'image' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal.',
                'errors' => $validator->errors()
            ], 422);
        }

        $receipt = PaymentReceipt::find($id);
        if (!$receipt) {
            return response()->json([
                'success' => false,
                'message' => 'Payment receipt tidak ditemukan.'
            ], 404);
        }

        // Hanya receipt fuel service yang bisa diedit via endpoint ini.
        // NB: payment_for dibandingkan sebagai string ('1' = FuelService)
        // karena enum PaymentFor hanya ada di app admin, bukan di service API.
        if ((string) $receipt->payment_for !== '1') {
            return response()->json([
                'success' => false,
                'message' => 'Endpoint ini hanya untuk payment receipt Fuel & Service.'
            ], 400);
        }

        $newFuelServiceIds = $request->fuel_service_ids;

        // Load item-item baru (state target) dengan eager-load supplier.
        $newFuelServices = FuelService::with('supplier')->whereIn('id', $newFuelServiceIds)->get();
        foreach ($newFuelServices as $fs) {
            // Pre-check: harus Transfer.
            if ($fs->payment_type_id != 1) {
                return response()->json([
                    'success' => false,
                    'message' => "Bensin/Servis #{$fs->id} bukan metode Transfer."
                ], 400);
            }
            // Pre-check: harus pending (1), KECUALI jika sudah ter-attach ke
            // receipt ini (status '2' karena receipt ini sendiri). Item yang
            // lunas karena receipt lain ditolak.
            $attachedToThis = $receipt->fuelServices()
                ->where('fuel_service_id', $fs->id)
                ->exists();
            if ($fs->status != '1' && !$attachedToThis) {
                return response()->json([
                    'success' => false,
                    'message' => "Bensin/Servis #{$fs->id} sudah dibayar di receipt lain atau tidak valid."
                ], 400);
            }
        }

        // Validasi supplier (aturan sama dengan store): hanya item Servis
        // (fuel_service == '2') yang wajib satu supplier sama; item Bensin
        // (== '1') boleh beda supplier karena dibayar ke rekening pembuat.
        $serviceItems = $newFuelServices->where('fuel_service', '2');
        $serviceSupplierIds = $serviceItems->pluck('supplier_id')->unique()->filter()->values();
        if (!$serviceItems->isEmpty()) {
            if ($serviceSupplierIds->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Item servis wajib memiliki data supplier. Lengkapi data supplier pada item servis terlebih dahulu.'
                ], 422);
            }
            if ($serviceSupplierIds->count() > 1) {
                return response()->json([
                    'success' => false,
                    'message' => 'Item servis yang dipilih berasal dari supplier berbeda. Pilih item servis dari supplier yang sama untuk satu pembayaran.'
                ], 422);
            }
            $commonSupplierId = $serviceSupplierIds->first();
        } else {
            $fuelSupplierIds = $newFuelServices->pluck('supplier_id')->unique()->filter()->values();
            $commonSupplierId = $fuelSupplierIds->count() === 1 ? $fuelSupplierIds->first() : null;
        }

        return DB::transaction(function () use ($request, $receipt, $newFuelServiceIds, $newFuelServices, $commonSupplierId) {
            $currentAttachedIds = $receipt->fuelServices()->pluck('fuel_services.id')->all();

            $toDetach = array_values(array_diff($currentAttachedIds, $newFuelServiceIds));
            $toAttach = array_values(array_diff($newFuelServiceIds, $currentAttachedIds));

            // Remove: detach + status kembali pending (1).
            if (!empty($toDetach)) {
                $receipt->fuelServices()->detach($toDetach);
                FuelService::whereIn('id', $toDetach)->update(['status' => '1']);
            }

            // Add: attach + status jadi lunas (2).
            if (!empty($toAttach)) {
                $receipt->fuelServices()->attach($toAttach);
                FuelService::whereIn('id', $toAttach)->update(['status' => '2']);
            }

            // Update metadata. total_amount computed dari sum amount item final.
            $receipt->update([
                'transfer_amount' => (int) $request->transfer_amount,
                'total_amount' => (int) $newFuelServices->sum('amount'),
                'supplier_id' => $commonSupplierId,
                'notes' => $request->notes,
                'image' => $request->filled('image') ? $request->input('image') : $receipt->image,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Payment receipt berhasil diperbarui.',
                'data' => $receipt->load(['fuelServices.vehicle', 'fuelServices.createdBy', 'fuelServices.supplier'])
            ], 200);
        });
    }

    /**
     * Update payment receipt daily salary (transfer payment).
     *
     * Mirror dengan updateFuelServicePaymentReceipt: edit daftar daily
     * salary (add/remove) + metadata. Status disinkron dua arah — item yang
     * di-remove kembali ke '3' (siap dibayar, konvensi yang sama dengan
     * DetachAction admin Filament), item yang ditambah menjadi '2' (dibayar).
     * Receipt tetap milik satu karyawan: user_id diisi ulang dari
     * created_by_id item final.
     *
     * Otorisasi: hanya admin/super_admin. Hanya berlaku payment_for == '2'.
     */
    public function updateDailySalaryPaymentReceipt(Request $request, $id)
    {
        $user = $request->user();
        if (!($user->hasRole('admin') || $user->hasRole('super_admin'))) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses untuk mengedit payment receipt.'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'daily_salary_ids' => 'required|array|min:1',
            'daily_salary_ids.*' => 'exists:daily_salaries,id',
            'transfer_amount' => 'required|numeric|min:1',
            'notes' => 'nullable|string|max:500',
            'image' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal.',
                'errors' => $validator->errors()
            ], 422);
        }

        $receipt = PaymentReceipt::find($id);
        if (!$receipt) {
            return response()->json([
                'success' => false,
                'message' => 'Payment receipt tidak ditemukan.'
            ], 404);
        }

        // Hanya receipt daily salary yang bisa diedit via endpoint ini.
        if ((string) $receipt->payment_for !== '2') {
            return response()->json([
                'success' => false,
                'message' => 'Endpoint ini hanya untuk payment receipt Gaji Harian.'
            ], 400);
        }

        $newDailySalaryIds = $request->daily_salary_ids;

        // Load item-item baru (state target).
        $newSalaries = DailySalary::whereIn('id', $newDailySalaryIds)->get();
        foreach ($newSalaries as $salary) {
            // Pre-check: harus Transfer.
            if ($salary->payment_type_id != 1) {
                return response()->json([
                    'success' => false,
                    'message' => "Daily salary #{$salary->id} bukan metode Transfer."
                ], 400);
            }
            // Pre-check: harus masih bisa dibayar ('1'/'3'), KECUALI jika sudah
            // ter-attach ke receipt ini (status '2' karena receipt ini sendiri).
            // Item yang lunas karena receipt lain ditolak.
            $attachedToThis = $receipt->dailySalaries()
                ->where('daily_salary_id', $salary->id)
                ->exists();
            if (!in_array($salary->status, ['1', '3']) && !$attachedToThis) {
                return response()->json([
                    'success' => false,
                    'message' => "Daily salary #{$salary->id} sudah dibayar di receipt lain atau tidak valid."
                ], 400);
            }
        }

        // Aturan "satu karyawan" (sama dengan create).
        $owners = $newSalaries->pluck('created_by_id')->filter()->unique();
        if ($owners->count() > 1) {
            return response()->json([
                'success' => false,
                'message' => 'Pilih daily salary dari karyawan yang sama.'
            ], 400);
        }

        return DB::transaction(function () use ($request, $receipt, $newDailySalaryIds, $newSalaries) {
            $currentAttachedIds = $receipt->dailySalaries()->pluck('daily_salaries.id')->all();

            $toDetach = array_values(array_diff($currentAttachedIds, $newDailySalaryIds));
            $toAttach = array_values(array_diff($newDailySalaryIds, $currentAttachedIds));

            // Remove: detach + status kembali siap dibayar (3).
            if (!empty($toDetach)) {
                $receipt->dailySalaries()->detach($toDetach);
                DailySalary::whereIn('id', $toDetach)->update(['status' => '3']);
            }

            // Add: attach + status jadi dibayar (2).
            if (!empty($toAttach)) {
                $receipt->dailySalaries()->attach($toAttach);
                DailySalary::whereIn('id', $toAttach)->update(['status' => '2']);
            }

            // Update metadata. total_amount computed dari sum amount item final;
            // user_id = karyawan pemilik item final (bukan admin peng-edit).
            $receipt->update([
                'transfer_amount' => (int) $request->transfer_amount,
                'total_amount' => (int) $newSalaries->sum('amount'),
                'user_id' => $newSalaries->first()->created_by_id,
                'notes' => $request->notes,
                'image' => $request->filled('image') ? $request->input('image') : $receipt->image,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Payment receipt berhasil diperbarui.',
                'data' => $receipt->load(['dailySalaries.createdBy'])
            ], 200);
        });
    }

    /**
     * Update invoice payment receipt (transfer payment).
     *
     * Edit daftar invoice purchases (add/remove) + metadata (transfer_amount,
     * notes, image). Status invoice disinkron dua arah — invoice yang di-remove
     * kembali ke '1' (belum dibayar), invoice yang ditambah menjadi '2' (sudah dibayar).
     *
     * Otorisasi: hanya admin/super_admin.
     */
    public function updatePaymentReceipt(Request $request, $id)
    {
        $user = $request->user();
        if (!($user->hasRole('admin') || $user->hasRole('super_admin'))) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses untuk mengedit payment receipt.'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'invoice_ids' => 'required|array|min:1',
            'invoice_ids.*' => 'exists:invoice_purchases,id',
            'transfer_amount' => 'required|numeric|min:1',
            'notes' => 'nullable|string|max:500',
            'image' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal.',
                'errors' => $validator->errors()
            ], 422);
        }

        $receipt = PaymentReceipt::find($id);
        if (!$receipt) {
            return response()->json([
                'success' => false,
                'message' => 'Payment receipt tidak ditemukan.'
            ], 404);
        }

        // Pastikan receipt ini untuk invoice purchase (bukan fuel service '1' atau daily salary '2')
        if ((string) $receipt->payment_for === '1' || (string) $receipt->payment_for === '2') {
            return response()->json([
                'success' => false,
                'message' => 'Endpoint ini hanya untuk payment receipt Invoice Pembelian.'
            ], 400);
        }

        $newInvoiceIds = $request->invoice_ids;

        // Load invoice baru (state target).
        $newInvoices = InvoicePurchase::whereIn('id', $newInvoiceIds)->get();
        foreach ($newInvoices as $inv) {
            // Pre-check: harus pending (1), KECUALI jika sudah ter-attach ke
            // receipt ini (status '2' karena receipt ini sendiri).
            $attachedToThis = $receipt->invoicePurchases()
                ->where('invoice_purchase_id', $inv->id)
                ->exists();
            if ($inv->payment_status != '1' && !$attachedToThis) {
                return response()->json([
                    'success' => false,
                    'message' => "Invoice #{$inv->id} sudah dibayar di receipt lain atau tidak valid."
                ], 400);
            }
        }

        return DB::transaction(function () use ($request, $receipt, $newInvoiceIds, $newInvoices) {
            $currentAttachedIds = $receipt->invoicePurchases()->pluck('invoice_purchases.id')->all();

            $toDetach = array_values(array_diff($currentAttachedIds, $newInvoiceIds));
            $toAttach = array_values(array_diff($newInvoiceIds, $currentAttachedIds));

            // Remove: detach + status kembali belum dibayar (1).
            if (!empty($toDetach)) {
                $receipt->invoicePurchases()->detach($toDetach);
                InvoicePurchase::whereIn('id', $toDetach)->update(['payment_status' => '1']);
            }

            // Add: attach + status jadi dibayar (2).
            if (!empty($toAttach)) {
                $receipt->invoicePurchases()->attach($toAttach);
                InvoicePurchase::whereIn('id', $toAttach)->update(['payment_status' => '2']);
            }

            // Update metadata. total_amount computed dari sum total_price invoice final.
            $firstInvoice = $newInvoices->first();
            $receipt->update([
                'transfer_amount' => (int) $request->transfer_amount,
                'total_amount' => (int) $newInvoices->sum('total_price'),
                'supplier_id' => $firstInvoice ? $firstInvoice->supplier_id : $receipt->supplier_id,
                'notes' => $request->notes,
                'image' => $request->filled('image') ? $request->input('image') : $receipt->image,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Payment receipt berhasil diperbarui.',
                'data' => $receipt->load(['invoicePurchases.supplier', 'supplier'])
            ], 200);
        });
    }

    /**
     * Hapus payment receipt daily salary.
     *
     * Pivot dihapus dan SEMUA daily salary ter-attach dikembalikan ke status
     * '3' (siap dibayar) — "seperti semula" sebelum dibayar — lalu receipt
     * dihapus. Untuk saat ini hanya payment_for == '2' yang didukung.
     *
     * Otorisasi: hanya admin/super_admin.
     */
    public function destroyPaymentReceipt(Request $request, $id)
    {
        $user = $request->user();
        if (!($user->hasRole('admin') || $user->hasRole('super_admin'))) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses untuk menghapus payment receipt.'
            ], 403);
        }

        $receipt = PaymentReceipt::find($id);
        if (!$receipt) {
            return response()->json([
                'success' => false,
                'message' => 'Payment receipt tidak ditemukan.'
            ], 404);
        }

        if ((string) $receipt->payment_for !== '2') {
            return response()->json([
                'success' => false,
                'message' => 'Hanya payment receipt Gaji Harian yang dapat dihapus.'
            ], 400);
        }

        DB::transaction(function () use ($receipt) {
            $salaryIds = $receipt->dailySalaries()->pluck('daily_salaries.id')->all();

            $receipt->dailySalaries()->detach();

            if (!empty($salaryIds)) {
                DailySalary::whereIn('id', $salaryIds)->update(['status' => '3']);
            }

            $receipt->delete();
        });

        return response()->json([
            'success' => true,
            'message' => 'Payment receipt berhasil dihapus. Status daily salary dikembalikan menjadi siap dibayar.',
        ]);
    }

    public function detailRequests(Request $request)
    {
        $request->validate([
            'store_id' => 'required|exists:stores,id',
            'payment_type_id' => 'nullable|integer',
        ]);

        $query = DetailRequest::with([
            'product.unit', 'requestPurchase.store', 'paymentType'
        ])
        ->where('store_id', $request->store_id)
        ->where('status', '4'); // Approved items only

        if ($request->filled('payment_type_id')) {
            $query->where('payment_type_id', $request->payment_type_id);
        }

        $items = $query->orderBy('id', 'desc')->get();

        // Admin: append harga beli terakhir per item untuk evaluasi harga.
        $user = $request->user();
        $isAdmin = $user && ($user->hasRole('admin') || $user->hasRole('super_admin'));
        if ($isAdmin) {
            $items->each(function ($item) {
                $item->append('last_purchase_price');
            });
        }

        return response()->json([
            'success' => true,
            'data' => $items,
        ]);
    }

    public function storeInvoice(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'supplier_id' => 'required|exists:suppliers,id',
            'store_id' => 'required|exists:stores,id',
            'payment_type_id' => 'required|integer',
            'date' => 'required|date',
            'items' => 'required|array|min:1',
            'items.*.detail_request_id' => 'required|exists:detail_requests,id',
            'items.*.quantity_product' => 'required|numeric|min:1',
            'items.*.subtotal_invoice' => 'required|numeric|min:0',
            'taxes' => 'nullable|numeric|min:0',
            'discounts' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
            'image' => 'nullable|string',
            'sales_order_id' => 'nullable|integer|exists:sales_orders,id',
        ]);

        $invoice = DB::transaction(function () use ($request, $user) {
            $totalPrice = 0;
            foreach ($request->items as $item) {
                $totalPrice += (int) $item['subtotal_invoice'];
            }
            $taxes = (int) ($request->taxes ?? 0);
            $discounts = (int) ($request->discounts ?? 0);
            $totalPrice = $totalPrice + $taxes - $discounts;

            $invoice = InvoicePurchase::create([
                'store_id' => $request->store_id,
                'supplier_id' => $request->supplier_id,
                'payment_type_id' => $request->payment_type_id,
                'date' => $request->date,
                'total_price' => $totalPrice,
                'taxes' => $taxes,
                'discounts' => $discounts,
                'notes' => $request->notes,
                'image' => $request->image,
                'created_by_id' => $user->id,
                'payment_status' => '1',
                'order_status' => '1',
                'sales_order_id' => $request->sales_order_id,
            ]);

            foreach ($request->items as $item) {
                DetailInvoice::create([
                    'invoice_purchase_id' => $invoice->id,
                    'detail_request_id' => $item['detail_request_id'],
                    'quantity_product' => $item['quantity_product'],
                    'subtotal_invoice' => $item['subtotal_invoice'],
                    'status' => '3',
                ]);
            }

            return $invoice;
        });

        // Push only untuk invoice Transfer (payment_type_id == 1), di luar
        // transaction agar kegagalan FCM tidak me-rollback invoice. Kegagalan
        // notifikasi (mis. tabel device_tokens belum ada) tidak boleh
        // menggagalkan request utama — invoice sudah tersimpan.
        if ((int) $invoice->payment_type_id === 1) {
            try {
                $this->procurementNotification->notifyInvoiceTransferCreated(
                    $invoice,
                    $user->id
                );
            } catch (\Throwable $e) {
                Log::error('Gagal kirim notifikasi invoice transfer (storeInvoice).', [
                    'invoice_id' => $invoice->id,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Invoice berhasil dibuat.',
            'data' => $invoice->load([
                'store', 'supplier', 'createdBy',
                'detailInvoices.detailRequest.product.unit',
            ]),
        ], 201);
    }

    /**
     * Hapus invoice (Admin & Super Admin only).
     *
     * Safety: invoice yang sudah dibayar (payment_status '2') tidak bisa
     * dihapus untuk melindungi data keuangan. Invoice draft boleh dihapus:
     * pivot payment_receipt / closing_store di-detach, lalu status DetailRequest
     * yang sudah di-invoice ('2') dikembalikan ke approved ('4') sehingga item
     * bisa di-invoice-kan ulang (membalik boot hook DetailInvoice::created).
     *
     * Super Admin dapat menghapus invoice dalam kondisi apapun (termasuk yang
     * sudah dibayar) untuk keperluan cleanup data trial.
     */
    public function destroyInvoice($id, Request $request)
    {
        $user = $request->user();
        if (!$user->hasRole('admin') && !$user->hasRole('super_admin')) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses untuk menghapus invoice.'
            ], 403);
        }

        $isSuperAdmin = $user->hasRole('super_admin');

        $invoice = InvoicePurchase::with('detailInvoices')->find($id);

        if (!$invoice) {
            return response()->json([
                'success' => false,
                'message' => 'Invoice tidak ditemukan.'
            ], 404);
        }

        // Loose comparison: payment_status bisa string '1'/'2' (lihat casts).
        // Super Admin boleh menghapus invoice yang sudah dibayar (force delete).
        if (!$isSuperAdmin && $invoice->payment_status == '2') {
            return response()->json([
                'success' => false,
                'message' => 'Invoice sudah dibayar dan tidak dapat dihapus.'
            ], 400);
        }

        return DB::transaction(function () use ($invoice) {
            // Lepas pivot agar tidak ada dangling rows.
            $invoice->paymentReceipts()->detach();
            $invoice->closingStores()->detach();

            // Revert status DetailRequest child dari invoiced ('2') kembali ke
            // approved ('4') — kebalikan dari boot hook DetailInvoice::created.
            foreach ($invoice->detailInvoices as $detail) {
                if ($detail->detail_request_id) {
                    DetailRequest::where('id', $detail->detail_request_id)
                        ->where('status', '2')
                        ->update(['status' => '4']);
                }
            }

            $invoice->detailInvoices()->delete();
            $invoice->delete();

            return response()->json([
                'success' => true,
                'message' => 'Invoice berhasil dihapus.'
            ]);
        });
    }

    /**
     * Hapus request purchase beserta seluruh item-nya (Admin & Super Admin only).
     *
     * Safety: bila ada DetailRequest yang sudah menjadi invoice, hapus ditolak
     * agar DetailInvoice tidak jadi orphan — user harus menghapus invoice
     * terlebih dahulu.
     *
     * Super Admin dapat menghapus request dalam kondisi apapun; seluruh
     * DetailInvoice yang terhubung akan dihapus cascade bersama pivot-nya
     * (untuk keperluan cleanup data trial).
     */
    public function destroyRequest($id, Request $request)
    {
        $user = $request->user();
        if (!$user->hasRole('admin') && !$user->hasRole('super_admin')) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses untuk menghapus request.'
            ], 403);
        }

        $isSuperAdmin = $user->hasRole('super_admin');

        $requestPurchase = RequestPurchase::with('detailRequests.detailInvoices')->find($id);

        if (!$requestPurchase) {
            return response()->json([
                'success' => false,
                'message' => 'Request tidak ditemukan.'
            ], 404);
        }

        $hasInvoiced = $requestPurchase->detailRequests
            ->contains(fn ($dr) => $dr->detailInvoices->isNotEmpty());

        if ($hasInvoiced && !$isSuperAdmin) {
            return response()->json([
                'success' => false,
                'message' => 'Request sudah memiliki invoice, hapus invoice terlebih dahulu.'
            ], 400);
        }

        return DB::transaction(function () use ($requestPurchase, $isSuperAdmin) {
            // Super Admin: hapus cascade seluruh invoice yang terhubung agar
            // tidak ada orphan DetailInvoice/PaymentReceipt.
            if ($isSuperAdmin) {
                $invoiceIds = $requestPurchase->detailRequests
                    ->flatMap(fn ($dr) => $dr->detailInvoices->pluck('invoice_purchase_id'))
                    ->filter()
                    ->unique();

                foreach ($invoiceIds as $invoiceId) {
                    $inv = InvoicePurchase::with('detailInvoices')->find($invoiceId);
                    if (!$inv) continue;

                    $inv->paymentReceipts()->detach();
                    $inv->closingStores()->detach();

                    foreach ($inv->detailInvoices as $detail) {
                        if ($detail->detail_request_id) {
                            DetailRequest::where('id', $detail->detail_request_id)
                                ->where('status', '2')
                                ->update(['status' => '4']);
                        }
                    }

                    $inv->detailInvoices()->delete();
                    $inv->delete();
                }
            }

            $requestPurchase->detailRequests()->delete();
            $requestPurchase->delete();

            return response()->json([
                'success' => true,
                'message' => 'Request berhasil dihapus.'
            ]);
        });
    }
}
