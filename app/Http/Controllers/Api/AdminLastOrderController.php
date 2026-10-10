<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LastOrderFollowUp;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Daftar "kapan terakhir user order" — khusus admin/super_admin.
 * Hanya order direct (sales_orders.for = '1') yang tidak di-soft-delete;
 * per user: tanggal terakhir order (MAX(created_at)), email, semua no telp
 * dari alamat pengiriman (fallback users.phone_number), total nominal,
 * follow-up terakhir, plus detail per user (summary produk + riwayat
 * follow-up).
 */
class AdminLastOrderController extends Controller
{
    public function index(Request $request)
    {
        if ($deny = $this->denyNonAdmin($request)) {
            return $deny;
        }

        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'search' => ['nullable', 'string', 'max:100'],
            'sort' => ['nullable', 'in:last_order_desc,last_order_asc'],
            'has_phone' => ['nullable', 'boolean'],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 20);
        $sort = $validated['sort'] ?? 'last_order_desc';
        $search = trim((string) ($validated['search'] ?? ''));

        // 1) Search (opsional): user id yang cocok nama/email (auth DB,
        //    via Eloquent — tanpa join lintas-DB) + user yang punya no telp
        //    alamat pengiriman yang cocok.
        $userIds = null;
        if ($search !== '') {
            $userIds = User::query()
                ->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                })
                ->pluck('id')
                ->merge(
                    DB::table('delivery_addresses')
                        ->whereNull('deleted_at')
                        ->whereNotNull('user_id')
                        ->where('recipient_telp_no', 'like', "%{$search}%")
                        ->pluck('user_id')
                )
                ->unique()
                ->values();
        }

        // 1b) Filter punya no telp (opsional): user dengan no telp di alamat
        //     pengiriman ATAU fallback users.phone_number. Digabung search
        //     lewat irisan.
        if (filter_var($validated['has_phone'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            // "Punya no telp" = ada no telp yang mengandung digit (nilai
            // sampah seperti "-" tidak dihitung — konsisten dengan chip WA
            // di mobile yang juga menganggapnya bukan no telp valid).
            $withPhone = DB::table('delivery_addresses')
                ->whereNull('deleted_at')
                ->whereNotNull('user_id')
                ->whereNotNull('recipient_telp_no')
                ->where('recipient_telp_no', '!=', '')
                ->where('recipient_telp_no', 'REGEXP', '[0-9]')
                ->distinct()
                ->pluck('user_id')
                ->merge(
                    User::query()
                        ->whereNotNull('phone_number')
                        ->where('phone_number', '!=', '')
                        ->where('phone_number', 'REGEXP', '[0-9]')
                        ->pluck('id')
                )
                ->unique()
                ->values();
            $userIds = $userIds === null
                ? $withPhone
                : $userIds->intersect($withPhone)->values();
        }

        // 2) Agregat order direct per user.
        $query = DB::table('sales_orders')
            ->where('for', '1')
            ->whereNull('deleted_at')
            ->groupBy('ordered_by_id')
            ->selectRaw('ordered_by_id, COUNT(*) AS total_orders, '
                . 'SUM(total_price) AS total_nominal, MAX(created_at) AS last_order_at');
        if ($userIds !== null) {
            $query->whereIn('ordered_by_id', $userIds);
        }

        // Secondary order ordered_by_id menjaga urutan deterministik saat
        // last_order_at sama (penting untuk test).
        $direction = $sort === 'last_order_asc' ? 'ASC' : 'DESC';
        $paginator = $query
            ->orderByRaw("last_order_at {$direction}, ordered_by_id {$direction}")
            ->paginate($perPage);
        $rows = collect($paginator->items());

        // 3) Hydrasi nama/email + fallback phone dari auth DB.
        $users = $this->hydrateUsers($rows->pluck('ordered_by_id'));

        // 4) Semua no telp distinct dari alamat pengiriman user di halaman
        //    ini — satu query grouped (hindari N+1).
        $phoneRows = $this->phonesByUser($rows->pluck('ordered_by_id'));

        // 5) Follow-up terakhir per user di halaman ini (MAX(id) per user,
        //    2 query grouped — hindari N+1).
        [$lastFuByUser, $fuAdminNames] = $this->lastFollowUpsByUser($rows->pluck('ordered_by_id'));

        $data = $rows->map(function ($row) use ($users, $phoneRows, $lastFuByUser, $fuAdminNames) {
            $u = $users->get($row->ordered_by_id);
            $fu = $lastFuByUser->get($row->ordered_by_id);
            return [
                'user_id' => (int) $row->ordered_by_id,
                'name' => $u->name ?? '-',
                'email' => $u->email ?? null,
                'last_order_at' => $row->last_order_at,
                'total_orders' => (int) $row->total_orders,
                'total_nominal' => (int) $row->total_nominal,
                'phones' => $this->phonesFor($row->ordered_by_id, $u, $phoneRows),
                'last_follow_up_at' => $fu?->created_at?->format('Y-m-d H:i:s'),
                'last_follow_up_by_name' => $fu
                    ? ($fuAdminNames->get($fu->followed_up_by)->name ?? '-')
                    : null,
            ];
        })->values();

        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /**
     * Detail satu user: identitas + no telp, statistik order direct,
     * summary produk all-time (product/quantity/price/total), riwayat
     * follow-up (10 terbaru). 404 bila user tidak punya order direct.
     */
    public function show($userId)
    {
        $request = request();
        if ($deny = $this->denyNonAdmin($request)) {
            return $deny;
        }

        $stats = DB::table('sales_orders')
            ->where('for', '1')
            ->whereNull('deleted_at')
            ->where('ordered_by_id', $userId)
            ->selectRaw('COUNT(*) AS total_orders, SUM(total_price) AS total_nominal, MAX(created_at) AS last_order_at')
            ->first();
        if (!$stats || (int) $stats->total_orders === 0) {
            return response()->json([
                'success' => false,
                'message' => 'User tidak memiliki order direct.',
            ], 404);
        }

        $user = User::find($userId);
        $phoneRows = $this->phonesByUser(collect([(int) $userId]));

        // Summary produk: qty & total dijumlah; harga satuan diambil dari
        // order TERAKHIR yang memuat produk tsb (harga bisa berubah antar
        // order — rata-rata justru menyesatkan).
        $products = DB::table('detail_sales_orders as dso')
            ->join('sales_orders as so', 'so.id', '=', 'dso.sales_order_id')
            ->leftJoin('products as p', 'p.id', '=', 'dso.product_id')
            ->where('so.for', '1')
            ->whereNull('so.deleted_at')
            ->where('so.ordered_by_id', $userId)
            ->groupBy('dso.product_id', 'p.name')
            ->selectRaw('dso.product_id, p.name AS product_name, '
                . 'SUM(dso.quantity) AS quantity, SUM(dso.subtotal_price) AS total')
            ->orderByDesc('total')
            ->get();

        $latestPrices = DB::table('detail_sales_orders as dso')
            ->join('sales_orders as so', 'so.id', '=', 'dso.sales_order_id')
            ->where('so.for', '1')
            ->whereNull('so.deleted_at')
            ->where('so.ordered_by_id', $userId)
            ->groupBy('dso.product_id')
            ->selectRaw('dso.product_id, SUBSTRING_INDEX(GROUP_CONCAT(dso.unit_price '
                . 'ORDER BY so.created_at DESC, dso.id DESC), ",", 1) AS latest_unit_price')
            ->get();
        $priceByProduct = [];
        foreach ($latestPrices as $lp) {
            $priceByProduct[(string) $lp->product_id] = (int) $lp->latest_unit_price;
        }

        $followUps = LastOrderFollowUp::where('user_id', $userId)
            ->orderByDesc('id')
            ->limit(10)
            ->get();
        $fuAdminNames = User::whereIn('id', $followUps->pluck('followed_up_by')->unique())
            ->get(['id', 'name'])
            ->keyBy('id');

        return response()->json([
            'success' => true,
            'data' => [
                'user_id' => (int) $userId,
                'name' => $user->name ?? '-',
                'email' => $user->email ?? null,
                'last_order_at' => $stats->last_order_at,
                'total_orders' => (int) $stats->total_orders,
                'total_nominal' => (int) $stats->total_nominal,
                'phones' => $this->phonesFor((int) $userId, $user, $phoneRows),
                'products' => $products->map(function ($p) use ($priceByProduct) {
                    return [
                        'product_id' => $p->product_id !== null ? (int) $p->product_id : null,
                        'name' => $p->product_name ?? 'Tanpa Produk',
                        'quantity' => (int) $p->quantity,
                        'unit_price' => $priceByProduct[(string) $p->product_id] ?? 0,
                        'total' => (int) $p->total,
                    ];
                })->values(),
                'follow_ups' => $followUps->map(function ($f) use ($fuAdminNames) {
                    return [
                        'id' => $f->id,
                        'by_name' => $fuAdminNames->get($f->followed_up_by)->name ?? '-',
                        'created_at' => $f->created_at?->format('Y-m-d H:i:s'),
                    ];
                })->values(),
            ],
        ]);
    }

    /**
     * Tandai user "sudah dihubungi" — mencatat admin yang login + waktu.
     * Riwayat dipertahankan (satu row per tindakan).
     */
    public function storeFollowUp(Request $request, $userId)
    {
        if ($deny = $this->denyNonAdmin($request)) {
            return $deny;
        }

        $followUp = LastOrderFollowUp::create([
            'user_id' => (int) $userId,
            'followed_up_by' => $request->user()->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'User ditandai sudah dihubungi.',
            'data' => [
                'id' => $followUp->id,
                'by_name' => $request->user()->name,
                'created_at' => $followUp->created_at->format('Y-m-d H:i:s'),
            ],
        ], 201);
    }

    private function denyNonAdmin(Request $request): ?JsonResponse
    {
        $user = $request->user();
        if (!$user || !$user->hasAnyRole(['admin', 'super_admin'])) {
            return response()->json([
                'success' => false,
                'message' => 'Hanya admin yang dapat mengakses daftar ini.'
            ], 403);
        }
        return null;
    }

    /** Hydrasi nama/email/phone_number user dari auth DB, keyBy id. */
    private function hydrateUsers($userIds): Collection
    {
        if ($userIds->isEmpty()) {
            return collect();
        }
        return User::whereIn('id', $userIds)
            ->get(['id', 'name', 'email', 'phone_number'])
            ->keyBy('id');
    }

    /** Semua no telp distinct dari alamat pengiriman, keyBy user_id. */
    private function phonesByUser($userIds): Collection
    {
        if ($userIds->isEmpty()) {
            return collect();
        }
        return DB::table('delivery_addresses')
            ->whereNull('deleted_at')
            ->whereIn('user_id', $userIds)
            ->whereNotNull('recipient_telp_no')
            ->where('recipient_telp_no', '!=', '')
            ->where('recipient_telp_no', 'REGEXP', '[0-9]')
            ->groupBy('user_id')
            ->selectRaw("user_id, GROUP_CONCAT(DISTINCT recipient_telp_no SEPARATOR '|') AS phones")
            ->get()
            ->keyBy('user_id');
    }

    /**
     * Daftar no telp siap kirim untuk satu user: dari alamat pengiriman,
     * fallback users.phone_number bila tidak ada.
     *
     * @return array<int, string>
     */
    private function phonesFor(int $userId, ?User $user, Collection $phoneRows): array
    {
        $phones = [];
        if ($phoneRow = $phoneRows->get($userId)) {
            $phones = array_values(array_filter(explode('|', $phoneRow->phones)));
        }
        if ($phones === [] && $user && !empty($user->phone_number)) {
            $phones = [$user->phone_number];
        }
        return $phones;
    }

    /**
     * Follow-up terakhir per user (MAX(id)), plus nama petugas-nya.
     * Mengembalikan [followUpByUser (keyBy user_id), adminNames (keyBy id)].
     */
    private function lastFollowUpsByUser($userIds): array
    {
        if ($userIds->isEmpty()) {
            return [collect(), collect()];
        }

        $latestIds = DB::table('last_order_follow_ups')
            ->whereIn('user_id', $userIds)
            ->groupBy('user_id')
            ->selectRaw('user_id, MAX(id) AS max_id')
            ->pluck('max_id');

        if ($latestIds->isEmpty()) {
            return [collect(), collect()];
        }

        $followUps = LastOrderFollowUp::whereIn('id', $latestIds)->get()->keyBy('user_id');
        $adminNames = $this->hydrateUsers($followUps->pluck('followed_up_by')->unique()->values())
            ->map(fn ($u) => $u);

        return [$followUps, $adminNames];
    }
}
