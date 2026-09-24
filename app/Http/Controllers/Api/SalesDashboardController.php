<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SalesDashboardController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        if (!$user || !$user->hasAnyRole(['admin', 'super_admin'])) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        $validated = $request->validate([
            'periode' => ['nullable', 'in:today,yesterday,month,year'],
            'view'    => ['nullable', 'in:summary,trend,products,channels,categories'],
            'page'    => ['nullable', 'integer', 'min:1'],
            'per_page'=> ['nullable', 'integer', 'min:1', 'max:200'],
            'sort'    => ['nullable', 'in:qty,revenue'],
            'compare_year' => ['nullable', 'integer', 'digits:4', 'between:2000,' . date('Y')],
            'metric'  => ['nullable', 'in:omzet,order,qty'],
        ]);

        $periode = $validated['periode'] ?? 'today';
        $view    = $validated['view']    ?? 'summary';
        $page    = max(1, (int) ($validated['page'] ?? 1));
        $perPage = min(max(1, (int) ($validated['per_page'] ?? 50)), 200);
        $sort    = $validated['sort']    ?? 'qty';
        $metric  = $validated['metric']  ?? 'omzet';

        $compareYearRaw = $validated['compare_year'] ?? null;
        $compareYear = null;
        if ($compareYearRaw !== null) {
            $currentYear = (int) Carbon::now('Asia/Jakarta')->format('Y');
            $y = (int) $compareYearRaw;
            if ($y >= 2000 && $y <= $currentYear && $y !== $currentYear) {
                $compareYear = $y;
            }
        }

        $range = $this->resolveRange($periode);

        return match($view) {
            'summary'  => response()->json([
                'success' => true,
                'data'    => array_merge(
                    ['view' => 'summary', 'periode' => $periode],
                    $this->summaryView($range),
                ),
            ]),
            'trend'    => response()->json([
                'success' => true,
                'data'    => array_merge(
                    ['view' => 'trend', 'periode' => $periode, 'metric' => $metric],
                    $this->trendView($range, $periode, $compareYear, $metric),
                ),
            ]),
            'products' => response()->json([
                'success' => true,
                'data'    => array_merge(
                    ['view' => 'products', 'periode' => $periode, 'sort' => $sort],
                    $this->productsView($range, $page, $perPage, $sort, $periode, $compareYear),
                ),
            ]),
            'channels' => response()->json([
                'success' => true,
                'data'    => array_merge(
                    ['view' => 'channels', 'periode' => $periode],
                    $this->channelsView($range),
                ),
            ]),
            'categories' => response()->json([
                'success' => true,
                'data'    => array_merge(
                    ['view' => 'categories', 'periode' => $periode],
                    $this->categoriesView($range, $periode, $compareYear),
                ),
            ]),
        };
    }

    private function resolveRange(string $periode): array
    {
        $now = Carbon::now('Asia/Jakarta');
        return match($periode) {
            'today'     => [
                'from'  => $now->copy()->startOfDay()->toDateTimeString(),
                'to'    => $now->copy()->endOfDay()->toDateTimeString(),
                'label' => $now->format('d M Y'),
            ],
            'yesterday' => [
                'from'  => $now->copy()->subDay()->startOfDay()->toDateTimeString(),
                'to'    => $now->copy()->subDay()->endOfDay()->toDateTimeString(),
                'label' => $now->copy()->subDay()->format('d M Y'),
            ],
            'month'     => [
                'from'  => $now->copy()->startOfMonth()->startOfDay()->toDateTimeString(),
                'to'    => $now->copy()->endOfDay()->toDateTimeString(),
                'label' => $now->format('M Y') . ' (s/d hari ini)',
            ],
            'year'      => [
                'from'  => $now->copy()->startOfYear()->startOfDay()->toDateTimeString(),
                'to'    => $now->copy()->endOfDay()->toDateTimeString(),
                'label' => $now->format('Y') . ' (s/d hari ini)',
            ],
        };
    }

    /**
     * Base query: delivered sales orders (delivery_status=3, not soft-deleted)
     * within the given date range. Use as starting point for all view queries.
     */
    private function deliveredSalesOrders(array $range): \Illuminate\Database\Query\Builder
    {
        return DB::table('sales_orders as so')
            ->whereNull('so.deleted_at')
            ->where('so.delivery_status', 3) // 3 = terkirim
            ->whereBetween('so.created_at', [$range['from'], $range['to']]);
    }

    private function summaryView(array $range): array
    {
        $omzet   = $this->deliveredSalesOrders($range)->sum('so.total_price');
        $orders  = $this->deliveredSalesOrders($range)->count('so.id');
        $qty     = $this->deliveredSalesOrders($range)
            ->join('detail_sales_orders as dso', 'dso.sales_order_id', '=', 'so.id')
            ->sum('dso.quantity');

        // Pembanding periode natural (today↔kemarin, month↔bulan lalu paralel, dst).
        [$prevRange, $prevLabel] = $this->resolvePrevRangeNatural($range['from'], $range['to']);
        $prevOmzet  = $this->deliveredSalesOrders($prevRange)->sum('so.total_price');
        $prevOrders = $this->deliveredSalesOrders($prevRange)->count('so.id');
        $prevQty    = $this->deliveredSalesOrders($prevRange)
            ->join('detail_sales_orders as dso', 'dso.sales_order_id', '=', 'so.id')
            ->sum('dso.quantity');

        return [
            'periode_label'   => $range['label'],
            'omzet'           => (int) $omzet,
            'order_count'     => (int) $orders,
            'total_qty'       => (int) $qty,
            'omzet_prev'      => (int) $prevOmzet,
            'order_count_prev'=> (int) $prevOrders,
            'total_qty_prev'  => (int) $prevQty,
            'prev_label'      => $prevLabel,
        ];
    }

    /**
     * Hitung range & label pembanding KPI berdasarkan durasi range asli.
     * Deteksi via selisih hari & posisi `from` (bukan string periode):
     * - 1 hari & from == hari ini → kemarin (full day)
     * - 1 hari & from == kemarin → H-2 (full day)
     * - bulan berjalan (from awal bulan s/d hari ini) → bulan lalu tgl 1..N paralel
     * - tahun berjalan (from awal tahun s/d hari ini) → tahun lalu 1 Jan..tgl/bulan sama
     *
     * Apple-to-apple untuk month/year: prev hanya s/d hari/bulan yang sama
     * (mis. 18 Jul tahun ini vs 18 Jul tahun lalu) — bukan full month/year lalu.
     */
    private function resolvePrevRangeNatural(string $fromStr, string $toStr): array
    {
        $from = Carbon::parse($fromStr, 'Asia/Jakarta');
        $to   = Carbon::parse($toStr, 'Asia/Jakarta');
        $now  = Carbon::now('Asia/Jakarta');

        $dayDiff = $from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay());

        // Kasus 1 & 2: range 1 hari → kemarin / H-2.
        if ($dayDiff === 0) {
            $isToday = $from->isSameDay($now);
            $prevDate = $isToday ? $now->copy()->subDay() : $from->copy()->subDay();
            return [
                [
                    'from'  => $prevDate->copy()->startOfDay()->toDateTimeString(),
                    'to'    => $prevDate->copy()->endOfDay()->toDateTimeString(),
                    'label' => $prevDate->format('d M Y'),
                ],
                $isToday ? 'Kemarin' : $prevDate->format('d M Y'),
            ];
        }

        // Kasus 4: tahun berjalan → tahun lalu paralel (1 Jan s/d tgl/bulan sama).
        // Dicek SEBELUM kasus bulan: 1 Jan juga awal bulan, sehingga tanpa
        // pembeda rentang, range "tahun berjalan" selalu tertangkap cabang
        // bulan dan pembanding KPI salah menunjuk bulan lalu. Pembeda: range
        // tahun lintas bulan (1 Jan s/d hari ini tidak mungkin satu bulan).
        $isYearRange = $from->isSameDay($from->copy()->startOfYear()->startOfDay())
            && $to->isSameDay($now)
            && !$from->isSameMonth($to);
        if ($isYearRange) {
            $prevYear = $to->copy()->subYear();
            return [
                [
                    'from'  => $prevYear->copy()->startOfYear()->startOfDay()->toDateTimeString(),
                    'to'    => $prevYear->copy()->endOfDay()->toDateTimeString(),
                    'label' => $prevYear->format('Y'),
                ],
                $prevYear->format('Y') . ' (s/d ' . $prevYear->format('d M') . ')',
            ];
        }

        // Kasus 3: bulan berjalan → bulan lalu paralel (tgl 1..N bulan lalu).
        $isMonthRange = $from->isSameDay($from->copy()->startOfMonth()->startOfDay())
            && $to->isSameDay($now);
        if ($isMonthRange) {
            $toDay = (int) $to->format('d');
            $prevMonth = $to->copy()->subMonth();
            $lastDayPrev = (int) $prevMonth->copy()->endOfMonth()->format('d');
            $effectiveDay = min($toDay, $lastDayPrev); // clamp utk Februari dll.
            return [
                [
                    'from'  => $prevMonth->copy()->startOfMonth()->startOfDay()->toDateTimeString(),
                    'to'    => $prevMonth->copy()->day($effectiveDay)->endOfDay()->toDateTimeString(),
                    'label' => $prevMonth->format('M Y'),
                ],
                $prevMonth->format('M Y') . ' (s/d tgl ' . $effectiveDay . ')',
            ];
        }

        // Fallback: shift mundur sesuai durasi (jarang terpakai, jaga-jaga).
        $prevFrom = $from->copy()->subSeconds($from->diffInSeconds($to) + 1)->startOfDay();
        $prevTo = $from->copy()->subSecond()->endOfDay();
        return [
            [
                'from'  => $prevFrom->toDateTimeString(),
                'to'    => $prevTo->toDateTimeString(),
                'label' => $prevFrom->format('d M Y') . '–' . $prevTo->format('d M Y'),
            ],
            $prevFrom->format('d M Y') . '–' . $prevTo->format('d M Y'),
        ];
    }

    private function trendView(array $range, string $periode, ?int $compareYear, string $metric = 'omzet'): array
    {
        [$selectExpr, $interval, $allBuckets] = $this->trendConfig($periode);
        $valueExpr = $this->metricExpr($metric);
        $needsJoin = $metric === 'qty'; // qty butuh join ke detail_sales_orders

        $currentQuery = $this->deliveredSalesOrders($range);
        if ($needsJoin) {
            $currentQuery->join('detail_sales_orders as dso', 'dso.sales_order_id', '=', 'so.id');
        }
        $rows = $currentQuery
            ->selectRaw($selectExpr . " as bucket, {$valueExpr} as value")
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->get()
            ->keyBy('bucket');

        // Compare year: query paralel dengan range & buckets yang di-shift tahun.
        $prevRows = collect();
        $prevBuckets = [];
        if ($compareYear !== null) {
            [$prevRange, $prevBuckets] = $this->resolvePrevRangeAndBuckets($range, $periode, $compareYear);
            $prevQuery = $this->deliveredSalesOrders($prevRange);
            if ($needsJoin) {
                $prevQuery->join('detail_sales_orders as dso', 'dso.sales_order_id', '=', 'so.id');
            }
            $prevRows = $prevQuery
                ->selectRaw($selectExpr . " as bucket, {$valueExpr} as value")
                ->groupBy('bucket')
                ->orderBy('bucket')
                ->get()
                ->keyBy('bucket');
        }

        $hasCompare = $compareYear !== null;
        $points = collect($allBuckets)->map(function ($bucket, $i) use ($rows, $prevRows, $prevBuckets, $hasCompare) {
            $point = [
                'label' => $bucket,
                'value' => (int) ($rows[$bucket]->value ?? 0),
            ];
            if ($hasCompare) {
                // Zip by ordinal index karena label tahun berbeda.
                $prevBucket = $prevBuckets[$i] ?? null;
                $point['value_prev'] = $prevBucket !== null ? (int) ($prevRows[$prevBucket]->value ?? 0) : 0;
            }
            return $point;
        })->values();

        return [
            'interval'     => $interval,
            'metric'       => $metric,
            'compare_year' => $compareYear,
            'points'       => $points,
        ];
    }

    /**
     * Ekspresi SQL agregat untuk metrik trend. Konsisten dengan summaryView:
     * omzet = SUM(so.total_price), order = COUNT(so.id), qty = SUM(dso.quantity).
     */
    private function metricExpr(string $metric): string
    {
        return match ($metric) {
            'order' => 'COUNT(so.id)',
            'qty'   => 'SUM(dso.quantity)',
            default => 'SUM(so.total_price)', // 'omzet'
        };
    }

    private function trendConfig(string $periode): array
    {
        $now = Carbon::now('Asia/Jakarta');
        return match($periode) {
            'today', 'yesterday' => [
                "DATE_FORMAT(so.created_at, '%H:00')",
                'hour',
                array_map(fn($h) => sprintf('%02d:00', $h), range(0, 23)),
            ],
            'month' => [
                "DATE(so.created_at)",
                'day',
                collect(range(1, $now->day))->map(fn($d) =>
                    $now->format('Y-m-') . str_pad($d, 2, '0', STR_PAD_LEFT)
                )->all(),
            ],
            'year' => [
                "DATE_FORMAT(so.created_at, '%Y-%m')",
                'month',
                array_map(fn($m) =>
                    $now->format('Y-') . str_pad($m, 2, '0', STR_PAD_LEFT), range(1, 12)
                ),
            ],
        };
    }

    /**
     * Untuk compare_year: shift range + bucket ke tahun compareYear.
     * Return [prevRange, prevBuckets].
     *
     * Carbon::setYear() OVERFLOW (bukan throw) untuk 29 Feb di tahun non-kabisat
     * → clamp manual ke tanggal valid via endOfMonth().
     */
    private function resolvePrevRangeAndBuckets(array $range, string $periode, int $compareYear): array
    {
        $fromOriginal = Carbon::parse($range['from'], 'Asia/Jakarta');
        $toOriginal = Carbon::parse($range['to'], 'Asia/Jakarta');

        $lastDayOfPrevMonthForTo = (int) Carbon::create($compareYear, $toOriginal->month)->endOfMonth()->format('d');
        $lastDayOfPrevMonthForFrom = (int) Carbon::create($compareYear, $fromOriginal->month)->endOfMonth()->format('d');

        $fromDay = min((int) $fromOriginal->format('d'), $lastDayOfPrevMonthForFrom);
        $toDay = min((int) $toOriginal->format('d'), $lastDayOfPrevMonthForTo);

        $fromStr = sprintf('%04d-%02d-%02d %s',
            $compareYear, $fromOriginal->month, $fromDay, $fromOriginal->format('H:i:s'));
        $toStr = sprintf('%04d-%02d-%02d %s',
            $compareYear, $toOriginal->month, $toDay, $toOriginal->format('H:i:s'));

        $prevRange = ['from' => $fromStr, 'to' => $toStr, 'label' => "Compare {$compareYear}"];

        $prevBuckets = [];
        switch ($periode) {
            case 'today':
            case 'yesterday':
                $prevBuckets = array_map(fn($h) => sprintf('%02d:00', $h), range(0, 23));
                break;
            case 'month':
                $prevBuckets = collect(range(1, $toDay))
                    ->map(fn($d) => sprintf('%04d-%02d-%02d', $compareYear, $toOriginal->month, $d))
                    ->all();
                break;
            case 'year':
                $prevBuckets = array_map(
                    fn($m) => sprintf('%04d-%02d', $compareYear, $m),
                    range(1, 12)
                );
                break;
        }

        return [$prevRange, $prevBuckets];
    }

    private function productsView(array $range, int $page, int $perPage, string $sort, string $periode = 'today', ?int $compareYear = null): array
    {
        $baseQuery = $this->deliveredSalesOrders($range)
            ->join('detail_sales_orders as dso', 'dso.sales_order_id', '=', 'so.id')
            ->whereNotNull('dso.product_id')
            ->select(
                'dso.product_id',
                DB::raw('SUM(dso.quantity) as qty'),
                DB::raw('SUM(dso.subtotal_price) as revenue')
            )
            ->groupBy('dso.product_id')
            ->orderBy($sort === 'revenue' ? 'revenue' : 'qty', 'desc')
            ->orderBy('dso.product_id', 'asc');

        $total = $this->deliveredSalesOrders($range)
            ->join('detail_sales_orders as dso', 'dso.sales_order_id', '=', 'so.id')
            ->whereNotNull('dso.product_id')
            ->distinct()
            ->count('dso.product_id');

        $lastPage = max(1, (int) ceil($total / $perPage));
        $offset = ($page - 1) * $perPage;
        $rows = (clone $baseQuery)->skip($offset)->take($perPage)->get();

        // Tentukan rentang pembanding (YoY untuk year / natural untuk periode lainnya).
        if ($compareYear !== null && $periode === 'year') {
            [$prevRange] = $this->resolvePrevRangeAndBuckets($range, $periode, $compareYear);
            $prevLabel = (string) $compareYear;
        } else {
            [$prevRange, $prevLabel] = $this->resolvePrevRangeNatural($range['from'], $range['to']);
        }

        $items = $rows;
        if ($rows->isNotEmpty()) {
            $products = DB::table('products')
                ->leftJoin('units', 'products.unit_id', '=', 'units.id')
                ->leftJoin('online_categories', 'products.online_category_id', '=', 'online_categories.id')
                ->whereIn('products.id', $rows->pluck('product_id'))
                ->select('products.id', 'products.name', 'units.unit', 'online_categories.name as category_name')
                ->get()
                ->keyBy('id');

            $prevRows = $this->deliveredSalesOrders($prevRange)
                ->join('detail_sales_orders as dso', 'dso.sales_order_id', '=', 'so.id')
                ->whereIn('dso.product_id', $rows->pluck('product_id'))
                ->select(
                    'dso.product_id',
                    DB::raw('SUM(dso.quantity) as qty'),
                    DB::raw('SUM(dso.subtotal_price) as revenue')
                )
                ->groupBy('dso.product_id')
                ->get()
                ->keyBy('product_id');

            $items = $rows->map(function ($r) use ($products, $prevRows) {
                $p = $products->get($r->product_id);
                $prev = $prevRows->get($r->product_id);
                return [
                    'product_id'    => (int) $r->product_id,
                    'product_name'  => $p?->name,
                    'category_name' => $p?->category_name ?? 'Umum',
                    'unit'          => $p?->unit,
                    'qty'           => (int) $r->qty,
                    'revenue'       => (int) $r->revenue,
                    'qty_prev'      => (int) ($prev?->qty ?? 0),
                    'revenue_prev'  => (int) ($prev?->revenue ?? 0),
                ];
            })->values();
        }

        // Ringkasan omzet per kategori produk
        $categoryRows = $this->deliveredSalesOrders($range)
            ->join('detail_sales_orders as dso', 'dso.sales_order_id', '=', 'so.id')
            ->join('products as p', 'p.id', '=', 'dso.product_id')
            ->leftJoin('online_categories as oc', 'oc.id', '=', 'p.online_category_id')
            ->select(
                DB::raw('COALESCE(oc.name, "Lainnya") as category_name'),
                DB::raw('SUM(dso.quantity) as qty'),
                DB::raw('SUM(dso.subtotal_price) as revenue')
            )
            ->groupBy('category_name')
            ->orderBy('revenue', 'desc')
            ->get();

        $totalCategoryRevenue = (int) $categoryRows->sum('revenue');
        $categorySummary = $categoryRows->map(function ($c) use ($totalCategoryRevenue) {
            return [
                'category_name' => (string) $c->category_name,
                'qty'           => (int) $c->qty,
                'revenue'       => (int) $c->revenue,
                'percentage'    => $totalCategoryRevenue > 0 ? round(($c->revenue / $totalCategoryRevenue) * 100, 1) : 0.0,
            ];
        })->values();

        return [
            'items'            => $items,
            'category_summary' => $categorySummary,
            'meta'  => [
                'current_page' => $page,
                'last_page'    => $lastPage,
                'per_page'     => $perPage,
                'total'        => $total,
                'prev_label'   => $prevLabel,
            ],
        ];
    }

    private function channelsView(array $range): array
    {
        // Omzet + order_count per channel — NO detail join (avoid fan-out).
        $omzetRows = $this->deliveredSalesOrders($range)
            ->select('so.for', DB::raw('COUNT(*) as order_count'), DB::raw('SUM(so.total_price) as omzet'))
            ->groupBy('so.for')
            ->get()
            ->keyBy('for');

        // Qty per channel — detail join is safe here.
        $qtyRows = $this->deliveredSalesOrders($range)
            ->join('detail_sales_orders as dso', 'dso.sales_order_id', '=', 'so.id')
            ->select('so.for', DB::raw('SUM(dso.quantity) as qty'))
            ->groupBy('so.for')
            ->get()
            ->keyBy('for');

        $labels = ['1' => 'Direct', '2' => 'Employee', '3' => 'Online'];
        $totalOmzet = (int) $omzetRows->sum(fn($r) => (int) $r->omzet);

        $allFors = $omzetRows->keys()->merge($qtyRows->keys())->unique();
        $items = $allFors->map(function ($for) use ($omzetRows, $qtyRows, $labels, $totalOmzet) {
            $omzet = (int) ($omzetRows[$for]->omzet ?? 0);
            // Kolom `sales_orders.for` (tinyint) bisa berupa int di production
            // atau string di test factory. Normalisasi ke string supaya label
            // lookup & kontrak JSON konsisten lintas environment.
            $forKey = (string) $for;
            return [
                'channel'       => $forKey,
                'channel_label' => $labels[$forKey] ?? "Unknown ({$forKey})",
                'omzet'         => $omzet,
                'order_count'   => (int) ($omzetRows[$for]->order_count ?? 0),
                'qty'           => (int) ($qtyRows[$for]->qty ?? 0),
                'percentage'    => $totalOmzet > 0 ? round(($omzet / $totalOmzet) * 100, 1) : 0.0,
            ];
        })->values();

        return [
            'total_omzet' => $totalOmzet,
            'items'       => $items,
        ];
    }

    private function categoriesView(array $range, string $periode = 'today', ?int $compareYear = null): array
    {
        // Tentukan rentang pembanding (YoY untuk year / natural untuk periode lainnya).
        if ($compareYear !== null && $periode === 'year') {
            [$prevRange] = $this->resolvePrevRangeAndBuckets($range, $periode, $compareYear);
            $prevLabel = (string) $compareYear;
        } else {
            [$prevRange, $prevLabel] = $this->resolvePrevRangeNatural($range['from'], $range['to']);
        }

        // Query data periode saat ini per online_category
        $currentRows = $this->deliveredSalesOrders($range)
            ->join('detail_sales_orders as dso', 'dso.sales_order_id', '=', 'so.id')
            ->join('products as p', 'p.id', '=', 'dso.product_id')
            ->leftJoin('online_categories as oc', 'oc.id', '=', 'p.online_category_id')
            ->select(
                DB::raw('COALESCE(oc.id, 0) as category_id'),
                DB::raw('COALESCE(oc.name, "Tanpa Kategori") as category_name'),
                DB::raw('COUNT(DISTINCT so.id) as order_count'),
                DB::raw('SUM(dso.quantity) as qty'),
                DB::raw('SUM(dso.subtotal_price) as omzet')
            )
            ->groupBy('category_id', 'category_name')
            ->orderBy('omzet', 'desc')
            ->get();

        $totalOmzet = (int) $currentRows->sum('omzet');
        $totalQty = (int) $currentRows->sum('qty');

        // Query data periode pembanding
        $prevRows = $this->deliveredSalesOrders($prevRange)
            ->join('detail_sales_orders as dso', 'dso.sales_order_id', '=', 'so.id')
            ->join('products as p', 'p.id', '=', 'dso.product_id')
            ->leftJoin('online_categories as oc', 'oc.id', '=', 'p.online_category_id')
            ->select(
                DB::raw('COALESCE(oc.id, 0) as category_id'),
                DB::raw('SUM(dso.quantity) as qty'),
                DB::raw('SUM(dso.subtotal_price) as omzet')
            )
            ->groupBy('category_id')
            ->get()
            ->keyBy('category_id');

        // Top 3 produk per kategori untuk drilldown ringkas
        $topProductsPerCategory = $this->deliveredSalesOrders($range)
            ->join('detail_sales_orders as dso', 'dso.sales_order_id', '=', 'so.id')
            ->join('products as p', 'p.id', '=', 'dso.product_id')
            ->select(
                DB::raw('COALESCE(p.online_category_id, 0) as category_id'),
                'p.name as product_name',
                DB::raw('SUM(dso.quantity) as qty'),
                DB::raw('SUM(dso.subtotal_price) as omzet')
            )
            ->groupBy('category_id', 'p.name')
            ->orderBy('omzet', 'desc')
            ->get()
            ->groupBy('category_id');

        $items = $currentRows->map(function ($row) use ($prevRows, $totalOmzet, $topProductsPerCategory) {
            $catId = (int) $row->category_id;
            $prev = $prevRows->get($catId);
            $topProds = ($topProductsPerCategory->get($catId) ?? collect())
                ->take(3)
                ->map(fn($tp) => [
                    'name'  => (string) $tp->product_name,
                    'qty'   => (int) $tp->qty,
                    'omzet' => (int) $tp->omzet,
                ])
                ->values();

            $omzet = (int) $row->omzet;
            return [
                'category_id'   => $catId,
                'category_name' => (string) $row->category_name,
                'omzet'         => $omzet,
                'qty'           => (int) $row->qty,
                'order_count'   => (int) $row->order_count,
                'percentage'    => $totalOmzet > 0 ? round(($omzet / $totalOmzet) * 100, 1) : 0.0,
                'omzet_prev'    => (int) ($prev?->omzet ?? 0),
                'qty_prev'      => (int) ($prev?->qty ?? 0),
                'top_products'  => $topProds,
            ];
        })->values();

        return [
            'total_omzet' => $totalOmzet,
            'total_qty'   => $totalQty,
            'prev_label'  => $prevLabel,
            'items'       => $items,
        ];
    }
}
