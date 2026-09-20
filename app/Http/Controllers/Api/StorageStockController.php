<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RemainingStorage;
use App\Models\DetailStockCard;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;
use App\Support\BusinessDate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class StorageStockController extends Controller
{
    /**
     * Get list of products for creating storage stock (Stock Opname).
     */
    public function products(Request $request)
    {
        // Mendapatkan semua produk dengan request = 1, urut abjad
        $products = Product::with('unit')
            ->where('request', '1')
            ->orderBy('name', 'asc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $products
        ]);
    }

    /**
     * Get list of storage stocks (remaining_storage reports from stock_cards).
     */
    public function index(Request $request)
    {
        $query = RemainingStorage::with(['store', 'user', 'detailStockCards.product.unit'])
            ->where('for', 'remaining_storage');

        // Staff only sees their own records
        if ($request->user()->hasRole('staff')) {
            $query->where('user_id', $request->user()->id);
        }

        $perPage = $request->integer('per_page', 20);
        $requests = $query->orderBy('date', 'desc')->orderBy('id', 'desc')
            ->paginate($perPage);

        // Flag can_be_edited per laporan tanpa N+1: cari laporan terakhir
        // untuk store-store di halaman ini dalam 2 query grouped.
        $latestIds = $this->latestReportIdsForStores(
            collect($requests->items())->pluck('store_id')->unique()->values()
        );
        $userCanEdit = $request->user()->hasRole('storage-staff')
            || $request->user()->hasRole('admin');
        foreach ($requests->items() as $report) {
            $report->can_be_edited = $userCanEdit
                && (int) $report->status !== 2
                && (int) $latestIds->get((int) $report->store_id, 0) === (int) $report->id;
        }

        return response()->json([
            'success' => true,
            'data' => $requests->items(),
            'pagination' => [
                'current_page' => $requests->currentPage(),
                'last_page' => $requests->lastPage(),
                'per_page' => $requests->perPage(),
                'total' => $requests->total(),
            ],
        ]);
    }

    /**
     * Get detail of a specific storage stock (remaining_storage report).
     */
    public function show($id, Request $request)
    {
        $storageStock = RemainingStorage::with([
            'store',
            'user',
            'detailStockCards.product.unit',
            'updater',
        ])
            ->where('for', 'remaining_storage')
            ->find($id);

        if (!$storageStock) {
            return response()->json([
                'success' => false,
                'message' => 'Storage Stock tidak ditemukan.'
            ], 404);
        }

        // Flag UX untuk tombol edit di mobile; guard sebenarnya tetap
        // di update().
        $userCanEdit = $request->user()->hasRole('storage-staff')
            || $request->user()->hasRole('admin');
        $storageStock->can_be_edited = $userCanEdit
            && (int) $storageStock->status !== 2
            && $this->isLatestForStore($storageStock);

        return response()->json([
            'success' => true,
            'data' => $storageStock
        ]);
    }

    /**
     * Store new storage stock (writes to stock_cards + detail_stock_cards).
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'store_id' => 'required|exists:stores,id',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal.',
                'errors' => $validator->errors()
            ], 422);
        }

        // Validasi window waktu: 22:00 - 11:00 (sesuai referensi admin)
        $hour = (int) Carbon::now()->format('H');
        if ($hour >= 11 && $hour < 22) {
            return response()->json([
                'success' => false,
                'message' => 'Laporan hanya bisa dibuat antara jam 22.00 hingga 11.00 keesokan harinya.'
            ], 422);
        }

        // Tanggal laporan mengikuti business date: laporan antara 22:00 tgl D-1
        // s.d. 11:00 tgl D dihitung sebagai tgl D-1 (mis. 00:12 tgl 18 = tgl 17).
        $today = BusinessDate::todayString();

        // Validasi Duplikasi: Hanya 1 kali sehari untuk store yang sama
        $existingReport = RemainingStorage::where('for', 'remaining_storage')
            ->where('store_id', $request->store_id)
            ->where('date', $today)
            ->exists();

        if ($existingReport) {
            return response()->json([
                'success' => false,
                'message' => 'Laporan stok untuk gudang ini sudah dilakukan hari ini. Anda tidak dapat melakukan laporan ganda untuk menghindari salah hitung.'
            ], 422);
        }

        DB::beginTransaction();
        try {
            $storageStock = RemainingStorage::create([
                'for' => 'remaining_storage',
                'store_id' => $request->store_id,
                'date' => $today,
                'user_id' => $request->user()->id,
                'status' => 1, // Default status: Draft/Pending/Submitted
            ]);

            foreach ($request->items as $item) {
                DetailStockCard::create([
                    'stock_card_id' => $storageStock->id,
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                ]);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Laporan Stok Sisa berhasil disimpan.',
                'data' => $storageStock->load('detailStockCards')
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Gagal menyimpan laporan stok sisa: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update kuantitas laporan stok sisa (remaining_storage) — koreksi
     * oleh storage-staff/admin. Hanya mengganti detail items; store_id,
     * date, dan user_id pelapor awal tidak disentuh; editor dicatat di
     * updated_by.
     */
    public function update(Request $request, $id)
    {
        if (!$request->user()->hasRole('storage-staff') && !$request->user()->hasRole('admin')) {
            return response()->json([
                'success' => false,
                'message' => 'Hanya storage-staff dan admin yang dapat mengedit laporan stok.'
            ], 403);
        }

        $storageStock = RemainingStorage::where('for', 'remaining_storage')->find($id);

        if (!$storageStock) {
            return response()->json([
                'success' => false,
                'message' => 'Storage Stock tidak ditemukan.'
            ], 404);
        }

        if ((int) $storageStock->status === 2) {
            return response()->json([
                'success' => false,
                'message' => 'Laporan yang sudah valid tidak dapat diubah.'
            ], 403);
        }

        if (!$this->isLatestForStore($storageStock)) {
            return response()->json([
                'success' => false,
                'message' => 'Hanya laporan terakhir per gudang yang dapat diedit.'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal.',
                'errors' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();
        try {
            $storageStock->update(['updated_by' => $request->user()->id]);

            // Replace detail rows: delete existing + insert baru
            // (pola StoreConsumptionController::update).
            $storageStock->detailStockCards()->delete();
            foreach ($request->items as $item) {
                DetailStockCard::create([
                    'stock_card_id' => $storageStock->id,
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                ]);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Laporan Stok Sisa berhasil diperbarui.',
                'data' => $storageStock->load([
                    'store',
                    'user',
                    'detailStockCards.product.unit',
                    'updater',
                ])
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Gagal memperbarui laporan stok sisa: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Check how many stores have reported today and if current user's store has reported.
     */
    public function todayStatus(Request $request)
    {
        try {
            // Samakan dengan business date agar pengecekan "sudah lapor hari ini"
            // konsisten dengan tanggal yang disimpan saat store() (jam < 11 = hari sebelumnya).
            $today = BusinessDate::todayString();

            // Sebagian database production lama belum memiliki kolom
            // `is_active`; dalam schema tersebut semua store dianggap aktif.
            $storesQuery = \App\Models\Store::query();
            if (Schema::hasColumn('stores', 'is_active')) {
                $storesQuery->where('is_active', true);
            }
            $totalStores = $storesQuery->count();
            $reportedStores = RemainingStorage::where('for', 'remaining_storage')
                ->where('date', $today)
                ->distinct('store_id')
                ->count('store_id');

            $userStoreReported = false;
            $user = $request->user();
            if ($user && ($user->hasRole('storage-staff') || $user->hasRole('admin'))) {
                $presence = \App\Models\Presence::where('created_by_id', $user->id)
                    ->whereDate('check_in', $today)
                    ->first();
                if ($presence) {
                    $userStoreReported = RemainingStorage::where('for', 'remaining_storage')
                        ->where('date', $today)
                        ->where('store_id', $presence->store_id)
                        ->exists();
                }
            }

            return response()->json([
                'success' => true,
                'total_stores' => $totalStores,
                'reported_stores' => $reportedStores,
                'user_store_reported' => $userStoreReported,
            ]);
        } catch (\Throwable $e) {
            Log::error('Storage stock today status failed', [
                'user_id' => optional($request->user())->id,
                'message' => $e->getMessage(),
            ]);

            // Status ini hanya data pendukung dashboard; jangan membuat seluruh
            // halaman gagal bila salah satu query monitoring bermasalah.
            return response()->json([
                'success' => false,
                'total_stores' => 0,
                'reported_stores' => 0,
                'user_store_reported' => false,
            ]);
        }
    }

    /**
     * Get stock monitoring aggregated from latest storage stocks of all stores.
     */
    public function stockMonitoring(Request $request)
    {
        $now = Carbon::now('Asia/Jakarta');
        $today = $now->toDateString();
        $hour = $now->hour;

        // Before 22:00 today, only show reports up to yesterday.
        // From 22:00 today onwards, show reports up to today.
        $maxAllowedDate = $today;
        if ($hour < 22) {
            $maxAllowedDate = $now->copy()->subDay()->toDateString();
        }

        // 1. Optimized subquery for latest remaining storage stock per product up to $maxAllowedDate
        $latestStockSubquery = DB::table('detail_stock_cards as dsc')
            ->join('stock_cards as sc', 'dsc.stock_card_id', '=', 'sc.id')
            ->join(DB::raw('(
                SELECT dsc2.product_id, MAX(sc2.date) as max_date 
                FROM detail_stock_cards dsc2 
                JOIN stock_cards sc2 ON dsc2.stock_card_id = sc2.id 
                WHERE sc2.for = \'remaining_storage\' 
                  AND sc2.date <= \'' . $maxAllowedDate . '\' 
                GROUP BY dsc2.product_id
            ) as latest'), function($join) {
                $join->on('dsc.product_id', '=', 'latest.product_id')
                     ->on('sc.date', '=', 'latest.max_date');
            })
            ->where('sc.for', 'remaining_storage')
            ->where('sc.date', '<=', $maxAllowedDate)
            ->select('dsc.product_id', DB::raw('SUM(dsc.quantity) as current_quantity'), DB::raw('MAX(sc.date) as latest_date'))
            ->groupBy('dsc.product_id');

        $latestReports = $latestStockSubquery->get();
        $productQuantities = $latestReports->pluck('current_quantity', 'product_id');
        $lastStockDate = $latestReports->max('latest_date');

        // 3. Load stock monitorings
        $stockMonitorings = DB::table('stock_monitorings')
            ->leftJoin('units', 'stock_monitorings.unit_id', '=', 'units.id')
            ->select('stock_monitorings.*', 'units.unit as unit_nickname')
            ->get();

        // 4. Calculate total stock per monitoring group
        foreach ($stockMonitorings as $sm) {
            $details = DB::table('stock_monitoring_details')
                ->join('products', 'stock_monitoring_details.product_id', '=', 'products.id')
                ->where('stock_monitoring_details.stock_monitoring_id', $sm->id)
                ->select('stock_monitoring_details.*', 'products.name as product_name')
                ->get();

            $calculatedTotal = 0;
            foreach ($details as $detail) {
                $qty = isset($productQuantities[$detail->product_id]) ? $productQuantities[$detail->product_id] : 0;
                $calculatedTotal += $qty * $detail->coefficient;
            }

            $sm->calculated_total_stock = $calculatedTotal;
            $sm->details = $details;
            $sm->last_stock_date = $lastStockDate;
        }

        return response()->json([
            'success' => true,
            'data' => $stockMonitorings
        ]);
    }

    /**
     * Apakah laporan ini masih yang terakhir (date desc, id desc) untuk
     * store yang sama di antara laporan remaining_storage. Tie-break id
     * diperlukan karena admin bisa membuat laporan backdated via panel.
     */
    private function isLatestForStore(RemainingStorage $report): bool
    {
        return !RemainingStorage::where('for', 'remaining_storage')
            ->where('store_id', $report->store_id)
            ->where(function ($q) use ($report) {
                $q->where('date', '>', $report->date)
                    ->orWhere(function ($q2) use ($report) {
                        $q2->where('date', $report->date)
                            ->where('id', '>', $report->id);
                    });
            })
            ->exists();
    }

    /**
     * Map store_id => id laporan remaining_storage terakhir, hanya untuk
     * store pada $storeIds. Dua query grouped (hindari N+1 di index).
     */
    private function latestReportIdsForStores($storeIds)
    {
        if ($storeIds->isEmpty()) {
            return collect();
        }

        $maxDates = DB::table('stock_cards')
            ->where('for', 'remaining_storage')
            ->whereIn('store_id', $storeIds)
            ->groupBy('store_id')
            ->select('store_id', DB::raw('MAX(date) as max_date'))
            ->get();

        if ($maxDates->isEmpty()) {
            return collect();
        }

        $rows = DB::table('stock_cards')
            ->where('for', 'remaining_storage')
            ->whereIn('store_id', $maxDates->pluck('store_id'))
            ->whereIn('date', $maxDates->pluck('max_date'))
            ->groupBy('store_id', 'date')
            ->select('store_id', 'date', DB::raw('MAX(id) as max_id'))
            ->get();

        // Ambil id pada tanggal maksimum tiap store.
        $latestByStore = collect();
        foreach ($maxDates as $d) {
            $match = $rows->first(function ($r) use ($d) {
                return (int) $r->store_id === (int) $d->store_id
                    && (string) $r->date === (string) $d->max_date;
            });
            if ($match) {
                $latestByStore->put((int) $d->store_id, (int) $match->max_id);
            }
        }
        return $latestByStore;
    }
}
