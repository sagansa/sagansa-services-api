<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SalesOrderOnline;
use App\Models\DetailSalesOrder;
use App\Models\OnlineShopProvider;
use App\Models\DeliveryService;
use App\Models\Product;
use App\Contracts\ImageStorageContract;
use App\Services\SalesOrderNotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class SalesOrderController extends Controller
{
    public function __construct(protected SalesOrderNotificationService $salesOrderNotification)
    {
    }

    public function search(Request $request)
    {
        $receiptNo = $request->query('receipt_no');
        $for = $request->query('for', '3');

        if ($receiptNo) {
            // Resi bisa duplikat (sync marketplace lama selalu INSERT);
            // pilih baris termuda agar konsisten dgn fallback updateDelivery.
            $order = $this->orderRowQuery()
                ->where('sales_orders.receipt_no', $receiptNo)
                ->where('sales_orders.for', $for)
                ->whereNull('sales_orders.deleted_at')
                ->orderByDesc('sales_orders.id')
                ->first();

            if (!$order) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order tidak ditemukan.'
                ], 404);
            }

            $this->enrichOrder($order);

            return response()->json([
                'success' => true,
                'data' => $order
            ]);
        }

        // List pagination when receipt_no is not provided
        $perPage = $request->query('per_page', 10);

        $orders = $this->orderRowQuery()
            ->where('sales_orders.for', $for)
            ->whereNull('sales_orders.deleted_at')
            ->when($request->filled('delivery_status'), function ($query) use ($request) {
                $query->where('sales_orders.delivery_status', $request->query('delivery_status'));
            })
            ->when($request->boolean('has_payment_proof'), function ($query) {
                $query->whereNotNull('sales_orders.image_payment')
                    ->where('sales_orders.image_payment', '!=', '');
            })
            ->when(
                $request->query('payment_proof_printed') === '0' && Schema::hasColumn('sales_orders', 'payment_proof_printed_at'),
                function ($query) {
                    $query->whereNull('sales_orders.payment_proof_printed_at');
                }
            )
            ->when(
                $request->query('payment_proof_printed') === '1' && Schema::hasColumn('sales_orders', 'payment_proof_printed_at'),
                function ($query) {
                    $query->whereNotNull('sales_orders.payment_proof_printed_at');
                }
            )
            ->orderBy('sales_orders.delivery_date', 'desc')
            ->orderBy('sales_orders.id', 'desc')
            ->paginate($perPage);

        // Map items for each order in the paginated response
        $itemsData = $orders->getCollection()->map(function ($order) {
            $this->enrichOrder($order);
            return $order;
        });

        $orders->setCollection($itemsData);

        return response()->json([
            'success' => true,
            'data' => $orders->items(),
            'meta' => [
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'per_page' => $orders->perPage(),
                'total' => $orders->total(),
            ]
        ]);
    }

    public function markPaymentProofsPrinted(Request $request)
    {
        if (!Schema::hasColumn('sales_orders', 'payment_proof_printed_at')) {
            return response()->json([
                'success' => false,
                'message' => 'Kolom status print bukti pembayaran belum tersedia. Jalankan migration terlebih dahulu.',
            ], 409);
        }

        $validator = Validator::make($request->all(), [
            'order_ids' => 'required|array|min:1',
            'order_ids.*' => 'integer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 400);
        }

        $orderIds = collect($request->input('order_ids'))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $updated = DB::table('sales_orders')
            ->whereIn('id', $orderIds)
            ->where('for', 3)
            ->whereNull('deleted_at')
            ->whereNotNull('image_payment')
            ->where('image_payment', '!=', '')
            ->update([
                'payment_proof_printed_at' => now(),
                'payment_proof_print_count' => DB::raw('COALESCE(payment_proof_print_count, 0) + 1'),
                'updated_at' => now(),
            ]);

        return response()->json([
            'success' => true,
            'message' => 'Status print bukti pembayaran berhasil diperbarui.',
            'data' => [
                'updated_count' => $updated,
                'printed_at' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * Ganti seluruh item (detail_sales_orders) sebuah order. Khusus admin —
     * menggantikan alur lama "hubungi admin backend" dari mobile. Hanya
     * boleh saat order belum terkunci (delivery_status 2/3/6 = locked).
     */
    public function updateItems(Request $request, $id)
    {
        $user = $request->user();
        if (!$user->hasRole('admin')) {
            return response()->json([
                'success' => false,
                'message' => 'Hanya admin yang dapat mengubah rincian produk.'
            ], 403);
        }

        $order = DB::table('sales_orders')
            ->where('id', $id)
            ->whereNull('deleted_at')
            ->first();

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'Order tidak ditemukan.'
            ], 404);
        }

        // Loose comparison: delivery_status bisa string maupun int.
        if (in_array((int) $order->delivery_status, [2, 3, 6])) {
            return response()->json([
                'success' => false,
                'message' => 'Order sudah terkunci (valid/terkirim/dikembalikan) sehingga rincian produk tidak dapat diubah.'
            ], 400);
        }

        $validator = Validator::make($request->all(), [
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit_price' => 'required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        DB::transaction(function () use ($request, $id) {
            DB::table('detail_sales_orders')->where('sales_order_id', $id)->delete();

            $totalPrice = 0;
            $now = now();
            foreach ($request->items as $item) {
                $quantity = (int) $item['quantity'];
                $unitPrice = (int) $item['unit_price'];
                $subtotal = $quantity * $unitPrice;
                $totalPrice += $subtotal;

                DB::table('detail_sales_orders')->insert([
                    'sales_order_id' => $id,
                    'product_id' => $item['product_id'],
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'subtotal_price' => $subtotal,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            DB::table('sales_orders')
                ->where('id', $id)
                ->update([
                    'total_price' => $totalPrice,
                    'updated_at' => $now,
                ]);
        });

        // Kembalikan order yang sudah diperkaya agar mobile langsung
        // memperbarui tampilan (items, total, dsb.).
        $updatedOrder = $this->orderRowQuery()
            ->where('sales_orders.id', $id)
            ->whereNull('sales_orders.deleted_at')
            ->first();
        $this->enrichOrder($updatedOrder);

        return response()->json([
            'success' => true,
            'message' => 'Rincian produk berhasil diperbarui.',
            'data' => $updatedOrder,
        ]);
    }

    /**
     * Tetapkan status bayar &/atau toko untuk order direct (for=1) yang
     * dibuat tanpa store. Khusus admin — menggantikan alur "hubungi admin
     * backend" dari mobile. Tidak boleh dilakukan saat order sudah terkirim
     * (delivery_status 3) dan pembayaran sudah valid (payment_status 2).
     */
    public function assign(Request $request, $id)
    {
        $user = $request->user();
        if (!$user->hasRole('admin')) {
            return response()->json([
                'success' => false,
                'message' => 'Hanya admin yang dapat menetapkan toko/status bayar.'
            ], 403);
        }

        $order = DB::table('sales_orders')
            ->where('id', $id)
            ->where('for', '1')
            ->whereNull('deleted_at')
            ->first();

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'Order tidak ditemukan.'
            ], 404);
        }

        // Loose comparison: delivery_status/payment_status bisa string maupun int.
        if ((int) $order->delivery_status === 3 && (int) $order->payment_status === 2) {
            return response()->json([
                'success' => false,
                'message' => 'Order sudah terkirim dan pembayaran sudah valid sehingga tidak dapat diubah.'
            ], 400);
        }

        $validator = Validator::make($request->all(), [
            'payment_status' => 'nullable|in:1,2,3,4',
            'store_id' => 'nullable|exists:stores,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        // Minimal salah satu field harus dikirim.
        if (!$request->filled('payment_status') && !$request->filled('store_id')) {
            return response()->json([
                'success' => false,
                'message' => 'Minimal salah satu dari status bayar atau toko harus dikirim.'
            ], 422);
        }

        $updateData = [
            'assigned_by_id' => $user->id,
            'updated_at' => now(),
        ];

        if ($request->filled('payment_status')) {
            $updateData['payment_status'] = $request->input('payment_status');
        }

        if ($request->filled('store_id')) {
            $updateData['store_id'] = $request->input('store_id');
        }

        DB::table('sales_orders')
            ->where('id', $id)
            ->update($updateData);

        // Kembalikan order yang sudah diperkaya agar mobile langsung
        // memperbarui tampilan (payment_status, store_id, store_name, dsb.).
        $updatedOrder = $this->orderRowQuery()
            ->where('sales_orders.id', $id)
            ->whereNull('sales_orders.deleted_at')
            ->first();
        $this->enrichOrder($updatedOrder);

        return response()->json([
            'success' => true,
            'message' => 'Toko & status bayar berhasil diperbarui.',
            'data' => $updatedOrder,
        ]);
    }

    public function updateDelivery(Request $request)
    {
        $validator = Validator::make($request->all(), [
            // order_id utama untuk semua tipe (direct for=1, online for=3);
            // receipt_no fallback untuk app versi lama. Salah satu harus diisi.
            'receipt_no' => 'nullable|string',
            'order_id' => 'nullable|integer',
            // image_delivery: bisa string tunggal (legacy) ATAU array path
            // (multi-upload). Required hanya saat status=3 (sudah dikirim).
            'image_delivery' => 'nullable',
            'received_by' => 'nullable|string|max:255',
            'delivery_status' => 'nullable|in:3,6',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors()
            ], 400);
        }

        // Lookup order: utamakan order_id (mobile mengirim id baris yang
        // persis dilihat user — direct for=1 maupun online for=3). Fallback
        // receipt_no hanya untuk app versi lama. Resi bisa duplikat di DB
        // (sync marketplace-bot dulu selalu INSERT), maka fallback wajib
        // deterministik: pilih baris termuda (id terbesar) — baris teratas
        // di list mobile — agar update tidak mendarat di duplikat acak.
        $order = null;
        $receiptNo = $request->input('receipt_no');
        $orderId = $request->input('order_id');

        if ($orderId) {
            $order = DB::table('sales_orders')
                ->where('id', $orderId)
                ->whereIn('for', [1, 3])
                ->whereNull('deleted_at')
                ->first();
        } elseif ($receiptNo) {
            $order = DB::table('sales_orders')
                ->where('receipt_no', $receiptNo)
                ->where('for', 3)
                ->whereNull('deleted_at')
                ->orderByDesc('id')
                ->first();
        }

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'Order tidak ditemukan.'
            ], 404);
        }

        if (in_array((int)$order->delivery_status, [2, 6])) {
            $statusName = $order->delivery_status == 2 ? 'valid' : 'dikembalikan';
            return response()->json([
                'success' => false,
                'message' => "Order sudah berstatus {$statusName} dan tidak dapat diubah."
            ], 400);
        }

        $deliveryStatus = (int) $request->input('delivery_status', 3);

        // Validasi image_delivery wajib saat status=3 (sudah dikirim).
        // Bisa string tunggal atau array path.
        $imageInput = $request->input('image_delivery');
        $hasImages = is_array($imageInput)
            ? count($imageInput) > 0
            : filled($imageInput);
        if ($deliveryStatus === 3 && !$hasImages) {
            return response()->json([
                'success' => false,
                'message' => 'Foto bukti pengiriman wajib diunggah untuk status sudah dikirim.',
            ], 400);
        }

        // Normalisasi image_delivery menjadi JSON array path.
        // Hapus foto lama yang tidak ada di list baru.
        $newImagePaths = is_array($imageInput)
            ? array_values(array_filter($imageInput, fn ($p) => filled($p)))
            : (filled($imageInput) ? [$imageInput] : []);
        $oldImagePaths = $this->decodeImagePaths($order->image_delivery);
        foreach (array_diff($oldImagePaths, $newImagePaths) as $dropped) {
            app(\App\Contracts\ImageStorageContract::class)->delete($dropped);
        }
        $imagePathJson = !empty($newImagePaths) ? json_encode($newImagePaths) : null;

        $notes = $request->input('notes');

        $updateData = [
            'delivery_status' => $deliveryStatus,
            'image_delivery' => $imagePathJson,
            'updated_at' => now(),
        ];

        if ($deliveryStatus === 6) {
            $updateData['notes'] = $notes;
            $updateData['received_by'] = null;
        } else {
            $updateData['received_by'] = $request->input('received_by');
        }

        // Update database
        DB::table('sales_orders')
            ->where('id', $order->id)
            ->update($updateData);

        $statusMsg = $deliveryStatus === 6 ? 'dikembalikan' : 'sudah dikirim';

        return response()->json([
            'success' => true,
            'message' => "Status pengiriman berhasil diperbarui menjadi {$statusMsg}.",
            'data' => [
                'receipt_no' => $order->receipt_no,
                'order_id' => $order->id,
                'delivery_status' => $deliveryStatus,
                // Kirim array URL agar mobile bisa render semua foto.
                'image_delivery_urls' => array_map(
                    fn ($p) => $this->getStorageUrl($p),
                    $newImagePaths
                ),
                'image_delivery_url' => !empty($newImagePaths)
                    ? $this->getStorageUrl($newImagePaths[0])
                    : null,
            ]
        ]);
    }

    /**
     * Decode kolom image_delivery yang mungkin berisi:
     * - string tunggal (path lama, sebelum multi-upload)
     * - JSON array path (multi-upload baru)
     * - null
     *
     * @return string[]
     */
    private function decodeImagePaths(?string $raw): array
    {
        if (blank($raw)) return [];
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) return array_values(array_filter($decoded, fn ($p) => filled($p)));
        // String tunggal (legacy).
        return [$raw];
    }

    /**
     * Resolve `image_delivery` (JSON array path) menjadi URL absolut.
     *
     * Mengisi dua field agar kompatibel dengan konsumer lama maupun baru:
     * - `image_delivery_urls`: array URL lengkap (konsisten dgn response
     *   `updateDelivery()` yang sudah memakai field plural).
     * - `image_delivery_url`: URL pertama (untuk konsumer lama yang membaca
     *   field singular). Null bila kosong.
     *
     * Sebelumnya `search()` me-resolve raw JSON string utuh sebagai satu path
     * → menghasilkan URL rusak (mis. `.../storage/["images/..."]`) yang
     * menyebabkan image load gagal / force-close di client.
     */
    private function resolveImageDeliveryFields($order): void
    {
        $paths = $this->decodeImagePaths($order->image_delivery);
        $urls = array_map(fn ($p) => $this->getStorageUrl($p), $paths);
        $order->image_delivery_urls = $urls;
        $order->image_delivery_url = $urls[0] ?? null;
    }

    public function markReadyToShip(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'required|integer',
            'for' => 'nullable|in:1,3',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 400);
        }

        $orderId = (int) $request->input('id');
        $for = $request->input('for', '3');

        $order = DB::table('sales_orders')
            ->where('id', $orderId)
            ->where('for', $for)
            ->whereNull('deleted_at')
            ->first();

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'Order tidak ditemukan.',
            ], 404);
        }

        // Idempoten: client bisa mengirim ulang (double-tap / list stale)
        // setelah status berhasil diubah — balas sukses, bukan error.
        if ((int) $order->delivery_status === 4) {
            return response()->json([
                'success' => true,
                'message' => 'Order sudah siap dikirim.',
                'data' => [
                    'id' => $order->id,
                    'receipt_no' => $order->receipt_no,
                    'delivery_status' => 4,
                ],
            ]);
        }

        if ((int) $order->delivery_status !== 1) {
            return response()->json([
                'success' => false,
                'message' => 'Hanya order berstatus belum dikirim yang dapat diubah menjadi siap dikirim.',
            ], 400);
        }

        DB::table('sales_orders')
            ->where('id', $order->id)
            ->update([
                'delivery_status' => 4,
                'updated_at' => now(),
            ]);

        return response()->json([
            'success' => true,
            'message' => 'Order berhasil ditandai siap dikirim.',
            'data' => [
                'id' => $order->id,
                'receipt_no' => $order->receipt_no,
                'delivery_status' => 4,
            ],
        ]);
    }

    /**
     * Daftar online shop provider untuk dropdown form.
     */
    public function onlineShopProviders(Request $request)
    {
        $providers = OnlineShopProvider::orderBy('name', 'asc')->get(['id', 'name']);

        return response()->json([
            'success' => true,
            'data' => $providers,
        ]);
    }

    /**
     * Daftar delivery service (yang aktif) untuk dropdown form.
     */
    public function deliveryServices(Request $request)
    {
        $services = DeliveryService::where('status', '1')
            ->orderBy('name', 'asc')
            ->get(['id', 'name']);

        return response()->json([
            'success' => true,
            'data' => $services,
        ]);
    }

    /**
     * Daftar produk yang bisa dijual online (whereNotIn online_category_id [4]).
     */
    public function onlineProducts(Request $request)
    {
        $products = Product::with('unit')
            ->whereNotIn('online_category_id', [4])
            ->orderBy('name', 'asc')
            ->get(['id', 'name', 'unit_id']);

        return response()->json([
            'success' => true,
            'data' => $products->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'unit' => $p->unit?->unit ?? '',
            ]),
        ]);
    }

    /**
     * Buat sales order online (for = 3).
     */
    public function storeOnline(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'store_id' => 'required|exists:stores,id',
            'delivery_date' => 'required|date',
            'online_shop_provider_id' => 'required|exists:online_shop_providers,id',
            'delivery_service_id' => 'required|exists:delivery_services,id',
            'receipt_no' => 'required|string|max:255',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit_price' => 'required|integer|min:0',
            'image_payment' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal.',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Hitung subtotal & total
        $items = [];
        $totalPrice = 0;
        foreach ($request->items as $item) {
            $subtotal = (int) $item['quantity'] * (int) $item['unit_price'];
            $items[] = [
                'product_id' => $item['product_id'],
                'quantity' => $item['quantity'],
                'unit_price' => $item['unit_price'],
                'subtotal_price' => $subtotal,
            ];
            $totalPrice += $subtotal;
        }

        DB::beginTransaction();
        try {
            // Upload image_payment (jika ada) via ImageStorageContract
            $imagePaymentPath = null;
            if ($request->filled('image_payment')) {
                $imagePaymentPath = $request->input('image_payment');
            }

            $order = SalesOrderOnline::create([
                'for' => '3',
                'store_id' => $request->store_id,
                'delivery_date' => $request->delivery_date,
                'online_shop_provider_id' => $request->online_shop_provider_id,
                'delivery_service_id' => $request->delivery_service_id,
                'receipt_no' => $request->receipt_no,
                'image_payment' => $imagePaymentPath,
                'payment_status' => '2',
                'delivery_status' => '1',
                'shipping_cost' => 0,
                'ordered_by_id' => $request->user()->id,
                'total_price' => $totalPrice,
            ]);

            foreach ($items as $item) {
                DetailSalesOrder::create(array_merge(
                    ['sales_order_id' => $order->id],
                    $item
                ));
            }

            DB::commit();

            // Notifikasi ke storage-staff (di luar transaction): kegagalan
            // notifikasi tidak boleh menggagalkan request pembuatan order.
            try {
                $this->salesOrderNotification->notifyOnlineSalesOrderCreated(
                    $order,
                    $request->user()->id
                );
            } catch (\Throwable $e) {
                Log::warning('SalesOrderNotification: gagal kirim notifikasi order online.', [
                    'sales_order_id' => $order->id,
                    'message' => $e->getMessage(),
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Sales order online berhasil dibuat.',
                'data' => $order->load('detailSalesOrders'),
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Gagal membuat sales order online: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Detail satu order online (for = 3) beserta items, untuk deep-link
     * notifikasi mobile. Tanpa restriksi role tambahan (konsisten dengan
     * `search` yang hanya `auth:sanctum`).
     */
    public function showOnline(int $id)
    {
        $order = $this->orderRowQuery()
            ->where('sales_orders.id', $id)
            ->where('sales_orders.for', '3')
            ->whereNull('sales_orders.deleted_at')
            ->first();

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'Order tidak ditemukan.'
            ], 404);
        }

        $this->enrichOrder($order);

        return response()->json([
            'success' => true,
            'data' => $order
        ]);
    }

    /**
     * Query dasar row order (join store/provider/delivery service/address/
     * bank/ordered_by) — dipakai `search` (single & pagination) dan `showOnline`.
     */
    private function orderRowQuery()
    {
        return DB::table('sales_orders')
            ->leftJoin('stores', 'sales_orders.store_id', '=', 'stores.id')
            ->leftJoin('online_shop_providers', 'sales_orders.online_shop_provider_id', '=', 'online_shop_providers.id')
            ->leftJoin('delivery_services', 'sales_orders.delivery_service_id', '=', 'delivery_services.id')
            ->leftJoin('transfer_to_accounts', 'sales_orders.transfer_to_account_id', '=', 'transfer_to_accounts.id')
            ->leftJoin('banks', 'transfer_to_accounts.bank_id', '=', 'banks.id')
            ->leftJoin('delivery_addresses', 'sales_orders.delivery_address_id', '=', 'delivery_addresses.id')
            ->leftJoin('subdistricts', 'delivery_addresses.subdistrict_id', '=', 'subdistricts.id')
            ->leftJoin('districts', 'delivery_addresses.district_id', '=', 'districts.id')
            ->leftJoin('cities', 'delivery_addresses.city_id', '=', 'cities.id')
            ->leftJoin('provinces', 'delivery_addresses.province_id', '=', 'provinces.id')
            ->leftJoin('users', 'sales_orders.ordered_by_id', '=', 'users.id')
            ->select([
                'sales_orders.id',
                'sales_orders.receipt_no',
                'sales_orders.store_id',
                'sales_orders.delivery_status',
                'sales_orders.received_by',
                'sales_orders.image_delivery',
                'sales_orders.image_payment',
                'sales_orders.total_price',
                ...$this->paymentProofPrintColumns(),
                'stores.nickname as store_name',
                'online_shop_providers.name as provider_name',
                'delivery_services.name as delivery_service_name',
                'sales_orders.delivery_date',
                'sales_orders.payment_method',
                'sales_orders.payment_status',
                'banks.name as bank_name',
                'transfer_to_accounts.number as bank_account_number',
                'transfer_to_accounts.name as bank_account_name',
                'delivery_addresses.name as address_name',
                'delivery_addresses.recipient_name as address_recipient_name',
                'delivery_addresses.recipient_telp_no as address_recipient_telp_no',
                'delivery_addresses.address as address_detail',
                'subdistricts.name as address_subdistrict',
                'districts.name as address_district',
                'cities.name as address_city',
                'provinces.name as address_province',
                'users.name as ordered_by_name'
            ]);
    }

    /**
     * Lengkapi row order hasil `orderRowQuery()`: attach items + resolve URL
     * image delivery/payment + status print bukti pembayaran.
     */
    private function enrichOrder(object $order): void
    {
        $items = DB::table('detail_sales_orders')
            ->join('products', 'detail_sales_orders.product_id', '=', 'products.id')
            ->leftJoin('units', 'products.unit_id', '=', 'units.id')
            ->where('detail_sales_orders.sales_order_id', $order->id)
            ->select([
                'detail_sales_orders.id as detail_id',
                'detail_sales_orders.product_id',
                'products.name as product_name',
                'detail_sales_orders.quantity',
                'detail_sales_orders.unit_price',
                'detail_sales_orders.subtotal_price',
                'units.unit as product_unit'
            ])
            ->get();

        $order->items = $items;
        $this->resolveImageDeliveryFields($order);
        $order->image_payment_url = $this->getStorageUrl($order->image_payment);
        $this->appendPaymentProofPrintStatus($order);
    }

    private function getStorageUrl($path)
    {
        return \App\Support\ImageUrlResolver::resolve($path);
    }

    private function paymentProofPrintColumns(): array
    {
        return [
            Schema::hasColumn('sales_orders', 'payment_proof_printed_at')
                ? 'sales_orders.payment_proof_printed_at'
                : DB::raw('NULL as payment_proof_printed_at'),
            Schema::hasColumn('sales_orders', 'payment_proof_print_count')
                ? 'sales_orders.payment_proof_print_count'
                : DB::raw('0 as payment_proof_print_count'),
        ];
    }

    private function appendPaymentProofPrintStatus(object $order): void
    {
        $order->payment_proof_print_count = (int) ($order->payment_proof_print_count ?? 0);
        $order->payment_proof_print_status = $order->payment_proof_printed_at ? 'printed' : 'not_printed';
        $order->payment_proof_print_status_label = $order->payment_proof_printed_at
            ? 'Sudah pernah diprint'
            : 'Belum pernah diprint';
    }

    /**
     * Kandidat penjualan untuk dikaitkan ke invoice pembelian (staff/admin).
     *
     * Dua mode:
     * - q terisi  : pencarian receipt_no LIKE atau id persis (perilaku lama).
     * - q kosong  : daftar order toko online (for=1, tidak punya resi) yang
     *               dibuat dalam `days` hari terakhir (default 5), terbaru
     *               dulu — dipilih dari list tanpa mengetik nomor resi.
     *               `for` dan `days` bisa di-override lewat query param.
     */
    public function linkCandidates(Request $request)
    {
        $user = $request->user();
        $allowed = $user
            && ($user->hasRole('admin') || $user->hasRole('super_admin')
                || $user->hasRole('storage-staff'));
        if (!$allowed) {
            return response()->json([
                'success' => false,
                'message' => 'Hanya admin dan storage-staff yang dapat mencari penjualan.',
            ], 403);
        }

        $q = trim((string) $request->query('q', ''));
        $for = $request->query('for');

        $query = $this->orderRowQuery()
            // orderRowQuery() belum memilih kolom `for` — tambahkan di sini
            // supaya respons memuat tipe order tanpa mengubah query bersama.
            ->addSelect('sales_orders.for')
            ->whereNull('sales_orders.deleted_at')
            // Filter tipe opsional di kedua mode (perilaku lama saat q terisi).
            ->when($for !== null && $for !== '', function ($query) use ($for) {
                $query->where('sales_orders.for', $for);
            });

        if ($q !== '') {
            $query->where(function ($query) use ($q) {
                $query->where('sales_orders.receipt_no', 'like', "%{$q}%")
                    ->orWhere('sales_orders.id', $q);
            });

            $limit = 10;
        } else {
            // Mode daftar: default order toko online (for=1) — tipe yang
            // tidak punya nomor resi sehingga tidak bisa dicari lewat q.
            $days = min(max((int) $request->query('days', 5), 1), 30);
            $query
                ->where('sales_orders.for', 1)
                ->where('sales_orders.created_at', '>=', now()->subDays($days));

            $limit = 50;
        }

        $rows = $query
            ->orderByDesc('sales_orders.id')
            ->limit($limit)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $rows->map(function ($row) {
                return [
                    'id' => $row->id,
                    'for' => (int) $row->for,
                    'receipt_no' => $row->receipt_no,
                    'store_name' => $row->store_name,
                    'delivery_date' => $row->delivery_date,
                    'total_price' => $row->total_price,
                    // Identitas pembeli supaya staf bisa memilih order yang
                    // tepat dari list tanpa nomor resi (order toko online
                    // tidak punya resi).
                    'buyer_name' => $row->address_recipient_name
                        ?: ($row->ordered_by_name ?? null),
                    'buyer_phone' => $row->address_recipient_telp_no,
                    'buyer_address' => collect([
                        $row->address_detail,
                        $row->address_district,
                        $row->address_city,
                    ])->filter()->implode(', '),
                ];
            }),
        ]);
    }

    /**
     * Invoice pembelian yang terkait ke satu sales order (kaitan
     * invoice_purchases.sales_order_id). Untuk kartu "Invoice Pembelian
     * Terkait" di detail penjualan direct — guard sama dengan perubahan
     * kaitan: admin/super_admin + storage-staff.
     */
    public function purchaseInvoices(int $id, Request $request)
    {
        $user = $request->user();
        $allowed = $user
            && ($user->hasRole('admin') || $user->hasRole('super_admin')
                || $user->hasRole('storage-staff'));
        if (!$allowed) {
            return response()->json([
                'success' => false,
                'message' => 'Hanya admin dan storage-staff yang dapat melihat invoice terkait.',
            ], 403);
        }

        $order = \App\Models\SalesOrder::find($id);
        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'Order tidak ditemukan.',
            ], 404);
        }

        $rows = \App\Models\InvoicePurchase::query()
            ->leftJoin('suppliers', 'suppliers.id', '=', 'invoice_purchases.supplier_id')
            ->where('invoice_purchases.sales_order_id', $id)
            ->select(
                'invoice_purchases.id',
                'invoice_purchases.total_price',
                'invoice_purchases.payment_status',
                'invoice_purchases.order_status',
                'invoice_purchases.date',
                'suppliers.name as supplier_name'
            )
            ->orderByDesc('invoice_purchases.id')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $rows,
        ]);
    }
}
