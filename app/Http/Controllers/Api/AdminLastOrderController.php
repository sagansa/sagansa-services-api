<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Daftar "kapan terakhir user order" — khusus admin/super_admin.
 * Hanya order direct (sales_orders.for = '1') yang tidak di-soft-delete;
 * per user: tanggal terakhir order (MAX(created_at)), email, semua no telp
 * dari alamat pengiriman (fallback users.phone_number), total nominal.
 */
class AdminLastOrderController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        if (!$user || !$user->hasAnyRole(['admin', 'super_admin'])) {
            return response()->json([
                'success' => false,
                'message' => 'Hanya admin yang dapat mengakses daftar ini.'
            ], 403);
        }

        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'search' => ['nullable', 'string', 'max:100'],
            'sort' => ['nullable', 'in:last_order_desc,last_order_asc'],
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
        $users = User::whereIn('id', $rows->pluck('ordered_by_id'))
            ->get(['id', 'name', 'email', 'phone_number'])
            ->keyBy('id');

        // 4) Semua no telp distinct dari alamat pengiriman user di halaman
        //    ini — satu query grouped (hindari N+1).
        $phoneRows = DB::table('delivery_addresses')
            ->whereNull('deleted_at')
            ->whereIn('user_id', $rows->pluck('ordered_by_id'))
            ->whereNotNull('recipient_telp_no')
            ->where('recipient_telp_no', '!=', '')
            ->groupBy('user_id')
            ->selectRaw("user_id, GROUP_CONCAT(DISTINCT recipient_telp_no SEPARATOR '|') AS phones")
            ->get()
            ->keyBy('user_id');

        $data = $rows->map(function ($row) use ($users, $phoneRows) {
            $u = $users->get($row->ordered_by_id);
            $phones = [];
            if ($phoneRow = $phoneRows->get($row->ordered_by_id)) {
                $phones = array_values(array_filter(explode('|', $phoneRow->phones)));
            }
            if ($phones === [] && $u && !empty($u->phone_number)) {
                $phones = [$u->phone_number];
            }
            return [
                'user_id' => (int) $row->ordered_by_id,
                'name' => $u->name ?? '-',
                'email' => $u->email ?? null,
                'last_order_at' => $row->last_order_at,
                'total_orders' => (int) $row->total_orders,
                'total_nominal' => (int) $row->total_nominal,
                'phones' => $phones,
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
}
