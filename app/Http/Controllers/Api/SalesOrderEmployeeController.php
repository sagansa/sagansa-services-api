<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeliveryAddress;
use App\Models\DetailSalesOrder;
use App\Models\Product;
use App\Models\SalesOrderEmployee;
use App\Models\TransferToAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Penjualan oleh Sales (sales_orders.for = 2).
 *
 * Pemetaan role:
 *  - `sales`: CRUD milik sendiri (ordered_by_id = Auth::id()).
 *             Termasuk `sales + former-employee` (sales eksternal) —
 *             full paritas dengan sales aktif (keputusan 2026-10-16).
 *             Edit/hapus hanya jika payment_status != 2 (sudah valid).
 *  - `admin/super_admin`: lihat SEMUA order, filter per sales, set
 *                     payment_status, hapus order apapun. Tidak bisa create.
 *  - Role lain (staff/supervisor/storage-staff): DITOLAK (403).
 *
 * Kontrak dipakai oleh mobile (mobiles/sagansa) — mengikuti SalesOrderEmployeesResource
 * di apps/admin sebagai acuan behavior.
 */
class SalesOrderEmployeeController extends Controller
{
    private const FOR = '2';

    /**
     * Relasi yang selalu di-eager-load supaya response self-contained.
     *
     * Catatan: hanya boleh memilih kolom FISIK di tabel. Accessor model
     * (mis. `transfer_name`, `delivery_address_name`) TIDAK boleh disebut
     * di sini — itu bukan kolom DB, sehingga query akan gagal
     * "Unknown column". Accessor tetap dihitung saat serialize.
     */
    private const WITH = [
        'store:id,nickname',
        'orderedBy:id,name',
        'transferToAccount:id,name,number,bank_id',
        'transferToAccount.bank:id,name',
        'deliveryAddress:id,name,recipient_name,recipient_telp_no,address',
        'detailSalesOrders:id,sales_order_id,product_id,quantity,unit_price,subtotal_price',
        'detailSalesOrders.product:id,name,unit_id',
        'detailSalesOrders.product.unit:id,unit',
    ];

    /**
     * GET /sales-orders/employee
     *
     * Query opsional:
     *  - sales_id (int) — filter per sales (admin only, diabaikan untuk role sales).
     *  - per_page (int, default 20).
     */
    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'sales_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        if ($validator->fails()) {
            return $this->validationFailed($validator);
        }

        $user = $request->user();
        if (!$this->canSell($user) && !$this->isAdminUser($user)) {
            return $this->forbidden('Anda tidak memiliki akses ke penjualan employee.');
        }
        $perPage = (int) ($request->input('per_page', 20));

        $query = SalesOrderEmployee::with(self::WITH)->where('for', self::FOR);

        if ($this->canSell($user)) {
            // Sales hanya boleh melihat miliknya sendiri.
            $query->where('ordered_by_id', $user->id);
        } else {
            // Admin bebas; sales_id opsional untuk filter.
            if ($request->filled('sales_id')) {
                $query->where('ordered_by_id', $request->input('sales_id'));
            }
        }

        $orders = $query->orderBy('delivery_date', 'desc')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $orders->items(),
            'meta' => [
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'per_page' => $orders->perPage(),
                'total' => $orders->total(),
            ],
        ]);
    }

    /**
     * GET /sales-orders/employee/{id}
     */
    public function show(Request $request, $id): JsonResponse
    {
        $order = SalesOrderEmployee::with(self::WITH)->where('for', self::FOR)->find($id);
        if (!$order) {
            return $this->notFound();
        }
        if (($err = $this->ensureCanAccess($request->user(), $order)) !== null) {
            return $err;
        }
        return response()->json(['success' => true, 'data' => $order]);
    }

    /**
     * POST /sales-orders/employee
     *
     * Hanya role `sales`. total_price dihitung backend dari items.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$this->canSell($user)) {
            return $this->forbidden('Hanya sales yang dapat membuat penjualan employee.');
        }

        $validator = Validator::make($request->all(), [
            'store_id' => ['required', 'exists:stores,id'],
            'delivery_date' => ['required', 'date'],
            'delivery_address_id' => ['required', 'exists:delivery_addresses,id'],
            'transfer_to_account_id' => ['required', 'exists:transfer_to_accounts,id'],
            'image_payment' => ['nullable', 'string'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_price' => ['required', 'integer', 'min:0'],
        ]);
        if ($validator->fails()) {
            return $this->validationFailed($validator);
        }

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

        return DB::transaction(function () use ($request, $user, $items, $totalPrice) {
            $order = SalesOrderEmployee::create([
                'for' => self::FOR,
                'store_id' => $request->store_id,
                'delivery_date' => $request->delivery_date,
                'delivery_address_id' => $request->delivery_address_id,
                'transfer_to_account_id' => $request->transfer_to_account_id,
                'image_payment' => $request->input('image_payment'),
                'notes' => $request->input('notes'),
                'payment_status' => 1, // 1 = Belum Diperiksa
                'delivery_status' => 1,
                'shipping_cost' => 0,
                'ordered_by_id' => $user->id,
                'total_price' => $totalPrice,
            ]);

            foreach ($items as $item) {
                DetailSalesOrder::create(array_merge(
                    ['sales_order_id' => $order->id],
                    $item
                ));
            }

            $order->load(self::WITH);

            return response()->json([
                'success' => true,
                'message' => 'Penjualan employee berhasil dibuat.',
                'data' => $order,
            ], 201);
        });
    }

    /**
     * PUT /sales-orders/employee/{id}
     *
     * Hanya role `sales` pemilik, hanya jika payment_status != 2.
     */
    public function update(Request $request, $id): JsonResponse
    {
        $user = $request->user();
        if (!$this->canSell($user)) {
            return $this->forbidden('Hanya sales yang dapat mengubah penjualan employee.');
        }

        $order = SalesOrderEmployee::with('detailSalesOrders')->where('for', self::FOR)->find($id);
        if (!$order) {
            return $this->notFound();
        }
        if ($order->ordered_by_id !== $user->id) {
            return $this->forbidden('Anda tidak memiliki akses ke penjualan ini.');
        }
        if ((string) $order->payment_status === '2') {
            return $this->forbidden('Penjualan yang sudah valid tidak dapat diubah.');
        }

        $validator = Validator::make($request->all(), [
            'store_id' => ['sometimes', 'required', 'exists:stores,id'],
            'delivery_date' => ['sometimes', 'required', 'date'],
            'delivery_address_id' => ['sometimes', 'required', 'exists:delivery_addresses,id'],
            'transfer_to_account_id' => ['sometimes', 'required', 'exists:transfer_to_accounts,id'],
            'image_payment' => ['nullable', 'string'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['sometimes', 'required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_price' => ['required', 'integer', 'min:0'],
        ]);
        if ($validator->fails()) {
            return $this->validationFailed($validator);
        }

        return DB::transaction(function () use ($request, $order) {
            $update = array_filter([
                'store_id' => $request->input('store_id'),
                'delivery_date' => $request->input('delivery_date'),
                'delivery_address_id' => $request->input('delivery_address_id'),
                'transfer_to_account_id' => $request->input('transfer_to_account_id'),
                'notes' => $request->input('notes'),
            ], fn ($v) => $v !== null);

            // image_payment nullable: kirim null secara eksplisit untuk clear.
            if ($request->exists('image_payment')) {
                $update['image_payment'] = $request->input('image_payment');
            }

            // Recalc total_price bila items ikut diupdate.
            if ($request->has('items')) {
                $totalPrice = 0;
                $newItems = [];
                foreach ($request->items as $item) {
                    $subtotal = (int) $item['quantity'] * (int) $item['unit_price'];
                    $newItems[] = [
                        'product_id' => $item['product_id'],
                        'quantity' => $item['quantity'],
                        'unit_price' => $item['unit_price'],
                        'subtotal_price' => $subtotal,
                    ];
                    $totalPrice += $subtotal;
                }
                $update['total_price'] = $totalPrice;

                // Replace items: hapus lama, simpan baru.
                $order->detailSalesOrders()->delete();
                foreach ($newItems as $item) {
                    DetailSalesOrder::create(array_merge(
                        ['sales_order_id' => $order->id],
                        $item
                    ));
                }
            }

            $order->update($update);
            $order->load(self::WITH);

            return response()->json([
                'success' => true,
                'message' => 'Penjualan employee berhasil diperbarui.',
                'data' => $order,
            ]);
        });
    }

    /**
     * DELETE /sales-orders/employee/{id}
     *
     * Sales: hapus miliknya (jika belum valid). Admin: hapus apapun.
     */
    public function destroy(Request $request, $id): JsonResponse
    {
        $user = $request->user();
        $isAdmin = $this->isAdminUser($user);

        $order = SalesOrderEmployee::where('for', self::FOR)->find($id);
        if (!$order) {
            return $this->notFound();
        }
        if (!$isAdmin && (!$this->canSell($user) || $order->ordered_by_id !== $user->id)) {
            return $this->forbidden('Anda tidak memiliki akses ke penjualan ini.');
        }
        if (!$isAdmin && (string) $order->payment_status === '2') {
            return $this->forbidden('Penjualan yang sudah valid tidak dapat dihapus.');
        }

        $order->delete();

        return response()->json([
            'success' => true,
            'message' => 'Penjualan employee berhasil dihapus.',
        ]);
    }

    /**
     * PATCH /sales-orders/employee/{id}/payment
     *
     * Hanya role admin/super_admin. Set payment_status.
     * 1 = Belum Diperiksa, 2 = Valid, 3 = Tidak Valid, 4 = Menunggu Pembayaran.
     */
    public function updatePaymentStatus(Request $request, $id): JsonResponse
    {
        $user = $request->user();
        if (!$this->isAdminUser($user)) {
            return $this->forbidden('Akses ditolak.');
        }

        $order = SalesOrderEmployee::where('for', self::FOR)->find($id);
        if (!$order) {
            return $this->notFound();
        }

        $validator = Validator::make($request->all(), [
            'payment_status' => ['required', 'integer', 'in:1,2,3,4'],
        ]);
        if ($validator->fails()) {
            return $this->validationFailed($validator);
        }

        $order->update(['payment_status' => $request->input('payment_status')]);
        $order->load(self::WITH);

        return response()->json([
            'success' => true,
            'message' => 'Status pembayaran diperbarui.',
            'data' => $order,
        ]);
    }

    // ---------- helpers ----------

    /**
     * GET /sales-orders/employee/supporting-data
     *
     * Data pendukung untuk form di mobile: daftar transfer_to_account (aktif),
     * delivery_address milik user login, dan produk (id/name/unit).
     * Digabung dalam satu endpoint agar mobile cukup sekali panggil saat buka form.
     */
    public function supportingData(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$this->canSell($user)) {
            return $this->forbidden('Hanya sales yang dapat membuat penjualan employee.');
        }

        $transferToAccounts = TransferToAccount::with('bank')
            ->where('status', 1)
            ->orderBy('name')
            ->get(['id', 'name', 'number', 'bank_id'])
            ->map(fn ($t) => [
                'id' => $t->id,
                'name' => $t->transfer_name,
            ]);

        $deliveryAddresses = DeliveryAddress::where('user_id', $user->id)
            ->orderByDesc('id')
            ->get(['id', 'name', 'recipient_name', 'recipient_telp_no', 'address'])
            ->map(fn ($d) => [
                'id' => $d->id,
                'name' => $d->delivery_address_name,
            ]);

        $products = Product::with('unit')
            ->orderBy('name')
            ->get(['id', 'name', 'unit_id'])
            ->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'unit' => $p->unit?->unit ?? '',
            ]);

        return response()->json([
            'success' => true,
            'data' => [
                'transfer_to_accounts' => $transferToAccounts,
                'delivery_addresses' => $deliveryAddresses,
                'products' => $products,
            ],
        ]);
    }

    /**
     * Berjual = ber-role `sales` — termasuk `sales + former-employee`
     * (sales eksternal yang tetap menjual, keputusan 2026-10-16).
     */
    private function canSell($user): bool
    {
        return $user->hasRole('sales');
    }

    private function isAdminUser($user): bool
    {
        return $user->hasAnyRole(['admin', 'super_admin']);
    }

    /**
     * Akses baca satu order (show): admin bebas; sales hanya miliknya
     * (termasuk sales + former-employee). Role lain ditolak.
     * Mengembalikan response forbidden jika ditolak, null jika OK.
     */
    private function ensureCanAccess($user, SalesOrderEmployee $order): ?JsonResponse
    {
        if ($this->isAdminUser($user)) {
            return null;
        }
        if ($this->canSell($user) && $order->ordered_by_id === $user->id) {
            return null;
        }
        return $this->forbidden('Anda tidak memiliki akses ke penjualan ini.');
    }

    private function validationFailed($validator): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Validasi gagal.',
            'errors' => $validator->errors(),
        ], 422);
    }

    private function notFound(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Penjualan employee tidak ditemukan.',
        ], 404);
    }

    private function forbidden(string $message): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], 403);
    }
}
