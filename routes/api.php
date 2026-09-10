<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\LeaveController;
use App\Http\Controllers\Api\PresenceController;
use App\Http\Controllers\Api\LocationController;
use App\Http\Controllers\Api\AdminPresenceController;
use App\Http\Controllers\Api\AdminDashboardController;
use App\Http\Controllers\Api\AdminLeaveController;
use App\Http\Controllers\Api\AdminReportController;
use App\Http\Controllers\Api\AdminTrackLocationController;
use App\Http\Controllers\Api\MediaController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\AssetCategoryController;
use App\Http\Controllers\Api\AssetController;
use App\Http\Controllers\Api\AssetCheckController;
use App\Http\Controllers\Api\AssetIssueController;
use App\Http\Controllers\Api\ProductAssetConfigController;
use App\Http\Controllers\Api\RecruitmentController;
use Illuminate\Support\Facades\Route;

// Media endpoint: serve storage files through Laravel so CORS headers are applied.
// Path boleh multi-segment, contoh: /media/images/Online/Payment/xxxx.jpg
Route::get('/media/{path}', [MediaController::class, 'show'])
    ->where('path', '.*')
    ->name('media.show');

Route::get('/app-version', function (\Illuminate\Http\Request $request) {
    $appName = $request->query('app_name', 'presence');
    
    $latestVersion = \Illuminate\Support\Facades\DB::table('app_versions')
        ->where('app_name', $appName)
        ->where('is_active', true)
        ->orderBy('build_number', 'desc')
        ->first();
        
    if (!$latestVersion) {
        return response()->json([
            'latest_version' => '1.0.0',
            'version_code' => 1,
            'force_update' => false,
            'download_url' => '',
            'release_notes' => 'No active version found for this app.'
        ]);
    }
    
    // APK disajikan dari image service (img.sagansa.id/storage/...).
    $downloadUrl = \App\Support\ImageUrlResolver::resolve($latestVersion->apk_file) ?? '';
        
    return response()->json([
        'latest_version' => $latestVersion->version_code,
        'version_code' => (int) $latestVersion->build_number,
        'force_update' => (bool) $latestVersion->is_force_update,
        'download_url' => $downloadUrl,
        'release_notes' => $latestVersion->release_notes ?? 'Pembaruan aplikasi untuk performa yang lebih baik dan perbaikan bug.'
    ]);
});

Route::post('/login', [AuthController::class, 'login']);
Route::get('/login', function () {
    return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
})->name('login');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);

    // Profile (data pribadi + rekening) — baca/tulis DB recruitment.
    Route::get('/profile', [RecruitmentController::class, 'getDetails']);
    Route::post('/profile', [RecruitmentController::class, 'updateDetails']);

    // Admin: kelola profil pelamar (list, detail, kunci/buka).
    Route::prefix('admin/profile')->group(function () {
        Route::get('/', [RecruitmentController::class, 'index']);
        Route::get('/user/{userId}', [RecruitmentController::class, 'showByUser']);
        Route::get('/{id}', [RecruitmentController::class, 'show']);
        Route::post('/{id}/status', [RecruitmentController::class, 'setStatus']);
    });
    Route::get('/user-presence', [PresenceController::class, 'getUserPresence']);
    Route::get('/presences/today', [PresenceController::class, 'getAllTodayPresences']);
    Route::get('/presences/monthly', [PresenceController::class, 'monthly']);
    Route::post('/check-in', [PresenceController::class, 'checkIn']);
    Route::post('/check-out', [PresenceController::class, 'checkOut']);
    Route::get('/stores', [PresenceController::class, 'getStores']);
    Route::get('/shift-stores', [PresenceController::class, 'getShiftStores']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/users', function (\Illuminate\Http\Request $request) {
        $query = \App\Models\User::orderBy('name');
        $role = $request->query('role');
        if ($role) {
            $query->role($role);
        }
        return response()->json([
            'success' => true,
            'data' => $query->get(['id', 'name'])
        ]);
    });

    // Employee location tracking (mobile ingestion)
    Route::post('/location', [LocationController::class, 'store']);
        Route::post('/device-tokens', [LocationController::class, 'registerToken']);
        Route::delete('/device-tokens', [LocationController::class, 'deregisterToken']);

        // Notification center (bell icon + daftar notifikasi).
        Route::prefix('notifications')->group(function () {
            Route::get('/', [NotificationController::class, 'index']);
            Route::get('/unread-count', [NotificationController::class, 'unreadCount']);
            Route::post('/read-all', [NotificationController::class, 'markAllRead']);
            Route::post('/{id}/read', [NotificationController::class, 'markRead']);
            Route::delete('/clear', [NotificationController::class, 'clearAll']);
            Route::delete('/{id}', [NotificationController::class, 'destroy']);
        });

    // Sales Order Delivery Routes
    Route::get('/sales-orders/search', [\App\Http\Controllers\Api\SalesOrderController::class, 'search']);
    // Kandidat penjualan utk dikaitkan ke invoice pembelian (admin/storage-staff).
    Route::get('/sales-orders/link-candidates', [\App\Http\Controllers\Api\SalesOrderController::class, 'linkCandidates']);
    Route::post('/sales-orders/ready-to-ship', [\App\Http\Controllers\Api\SalesOrderController::class, 'markReadyToShip']);
    Route::post('/sales-orders/delivery-update', [\App\Http\Controllers\Api\SalesOrderController::class, 'updateDelivery']);
    Route::post('/sales-orders/payment-proofs/printed', [\App\Http\Controllers\Api\SalesOrderController::class, 'markPaymentProofsPrinted']);
    // Ganti item order (admin only; role guard di controller).
    Route::post('/sales-orders/{id}/items', [\App\Http\Controllers\Api\SalesOrderController::class, 'updateItems']);
    // Tetapkan toko &/atau status bayar order direct (admin only; role guard di controller).
    Route::post('/sales-orders/{id}/assign', [\App\Http\Controllers\Api\SalesOrderController::class, 'assign']);
    // Invoice pembelian terkait satu order (kartu di detail penjualan direct).
    Route::get('/sales-orders/{id}/purchase-invoices', [\App\Http\Controllers\Api\SalesOrderController::class, 'purchaseInvoices']);

    // Sales Order Online - Create (admin)
    Route::get('/sales-orders/online-shop-providers', [\App\Http\Controllers\Api\SalesOrderController::class, 'onlineShopProviders']);
    Route::get('/sales-orders/delivery-services', [\App\Http\Controllers\Api\SalesOrderController::class, 'deliveryServices']);
    Route::get('/sales-orders/online-products', [\App\Http\Controllers\Api\SalesOrderController::class, 'onlineProducts']);
    Route::post('/sales-orders/online', [\App\Http\Controllers\Api\SalesOrderController::class, 'storeOnline']);
    // Detail satu order online (deep-link notifikasi) — wajib setelah route
    // POST di atas supaya tidak bentrok.
    Route::get('/sales-orders/online/{id}', [\App\Http\Controllers\Api\SalesOrderController::class, 'showOnline']);

    // Sales Order Employee (for=2) — penjualan oleh sales.
    // Role guard di controller: sales = CRUD milik sendiri, admin = list semua +
    // set payment_status + hapus (tidak bisa create).
    Route::prefix('sales-orders/employee')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\SalesOrderEmployeeController::class, 'index']);
        // supporting-data harus didaftarkan sebelum /{id} supaya tidak
        // ditangkap sebagai path param.
        Route::get('/supporting-data', [\App\Http\Controllers\Api\SalesOrderEmployeeController::class, 'supportingData']);
        Route::post('/', [\App\Http\Controllers\Api\SalesOrderEmployeeController::class, 'store']);
        Route::get('/{id}', [\App\Http\Controllers\Api\SalesOrderEmployeeController::class, 'show']);
        Route::put('/{id}', [\App\Http\Controllers\Api\SalesOrderEmployeeController::class, 'update']);
        Route::delete('/{id}', [\App\Http\Controllers\Api\SalesOrderEmployeeController::class, 'destroy']);
        Route::patch('/{id}/payment', [\App\Http\Controllers\Api\SalesOrderEmployeeController::class, 'updatePaymentStatus']);
    });

    Route::prefix('leaves')->group(function () {
        Route::get('/', [LeaveController::class, 'index']);
        Route::post('/', [LeaveController::class, 'store']);
        Route::get('/{id}', [LeaveController::class, 'show']);
        Route::put('/{id}', [LeaveController::class, 'update']);
        Route::delete('/{id}', [LeaveController::class, 'destroy']);
    });

    Route::prefix('salaries')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\SalaryController::class, 'index']);
        Route::get('/employees', [\App\Http\Controllers\Api\SalaryController::class, 'employees']);
        Route::get('/{id}', [\App\Http\Controllers\Api\SalaryController::class, 'show']);
        // --- admin write operations ---
        Route::middleware('admin')->group(function () {
            Route::post('/generate', [\App\Http\Controllers\Api\SalaryAdminController::class, 'generate']);
            Route::post('/approve', [\App\Http\Controllers\Api\SalaryAdminController::class, 'bulkApprove']);
            Route::post('/{id}/approve', [\App\Http\Controllers\Api\SalaryAdminController::class, 'approve']);
            Route::post('/{id}/pay', [\App\Http\Controllers\Api\SalaryAdminController::class, 'pay']);
            Route::get('/{id}/payment-info', [\App\Http\Controllers\Api\SalaryAdminController::class, 'paymentInfo']);
        });
    });

    Route::prefix('daily-salaries')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\DailySalaryController::class, 'index']);
        Route::post('/', [\App\Http\Controllers\Api\DailySalaryController::class, 'store']);
        Route::get('/employees', [\App\Http\Controllers\Api\DailySalaryController::class, 'employees']);
        Route::get('/for-payment', [\App\Http\Controllers\Api\DailySalaryController::class, 'forPayment']);
        Route::post('/bulk-update-status', [\App\Http\Controllers\Api\DailySalaryController::class, 'bulkUpdateStatus']);
        Route::get('/{id}', [\App\Http\Controllers\Api\DailySalaryController::class, 'show']);
        Route::put('/{id}', [\App\Http\Controllers\Api\DailySalaryController::class, 'update']);
        Route::delete('/{id}', [\App\Http\Controllers\Api\DailySalaryController::class, 'destroy']);
    });

    Route::prefix('procurement')->group(function () {
        Route::get('/products', [\App\Http\Controllers\Api\ProcurementController::class, 'products']);
        Route::get('/requests', [\App\Http\Controllers\Api\ProcurementController::class, 'index']);
        Route::post('/requests', [\App\Http\Controllers\Api\ProcurementController::class, 'store']);
        Route::get('/requests/{id}', [\App\Http\Controllers\Api\ProcurementController::class, 'show']);
        Route::post('/requests/items/{id}/approve', [\App\Http\Controllers\Api\ProcurementController::class, 'approveItem']);
        Route::post('/requests/items/{id}/reject', [\App\Http\Controllers\Api\ProcurementController::class, 'rejectItem']);
        Route::post('/requests/items/{id}/cancel', [\App\Http\Controllers\Api\ProcurementController::class, 'cancelItem']);
        Route::post('/requests/{id}/create-invoice', [\App\Http\Controllers\Api\ProcurementController::class, 'createInvoice']);
        Route::get('/detail-requests', [\App\Http\Controllers\Api\ProcurementController::class, 'detailRequests']);
        Route::post('/invoices', [\App\Http\Controllers\Api\ProcurementController::class, 'storeInvoice']);
        Route::get('/invoices', [\App\Http\Controllers\Api\ProcurementController::class, 'invoices']);
        // Kandidat invoice utk dikaitkan dari sisi penjualan (admin/storage-staff).
        // WAJIB sebelum route /invoices/{id} agar tidak tertangkap wildcard.
        Route::get('/invoices/link-candidates', [\App\Http\Controllers\Api\ProcurementController::class, 'invoiceLinkCandidates']);
        Route::get('/invoices/{id}', [\App\Http\Controllers\Api\ProcurementController::class, 'showInvoice']);
        Route::get('/invoices/{id}/qris', [\App\Http\Controllers\Api\ProcurementController::class, 'invoiceQris']);
        Route::put('/invoices/{id}', [\App\Http\Controllers\Api\ProcurementController::class, 'updateInvoice']);
        // Ganti image invoice SAJA (admin/staff, semua payment_status).
        Route::post('/invoices/{id}/image', [\App\Http\Controllers\Api\ProcurementController::class, 'updateInvoiceImage']);
        Route::post('/invoices/{id}/receive', [\App\Http\Controllers\Api\ProcurementController::class, 'receiveInvoice']);
        Route::delete('/invoices/{id}', [\App\Http\Controllers\Api\ProcurementController::class, 'destroyInvoice']);
        Route::delete('/requests/{id}', [\App\Http\Controllers\Api\ProcurementController::class, 'destroyRequest']);
        Route::get('/payment-receipts', [\App\Http\Controllers\Api\ProcurementController::class, 'paymentReceipts']);
        Route::get('/payment-receipts/{id}', [\App\Http\Controllers\Api\ProcurementController::class, 'showPaymentReceipt']);
        Route::get('/payment-receipts/{id}/qris', [\App\Http\Controllers\Api\ProcurementController::class, 'paymentReceiptQris']);
        Route::post('/payment-receipts', [\App\Http\Controllers\Api\ProcurementController::class, 'storePaymentReceipt']);
        Route::post('/payment-receipts/{id}', [\App\Http\Controllers\Api\ProcurementController::class, 'updatePaymentReceipt']);
        Route::delete('/payment-receipts/{id}', [\App\Http\Controllers\Api\ProcurementController::class, 'destroyPaymentReceipt']);
        Route::post('/fuel-service-payment-receipts', [\App\Http\Controllers\Api\ProcurementController::class, 'storeFuelServicePaymentReceipt']);
        Route::post('/fuel-service-payment-receipts/{id}', [\App\Http\Controllers\Api\ProcurementController::class, 'updateFuelServicePaymentReceipt']);
        Route::post('/daily-salary-payment-receipts/{id}', [\App\Http\Controllers\Api\ProcurementController::class, 'updateDailySalaryPaymentReceipt']);
    });

    Route::prefix('storage-stocks')->group(function () {
        Route::get('/monitoring', [\App\Http\Controllers\Api\StorageStockController::class, 'stockMonitoring']);
        Route::get('/products', [\App\Http\Controllers\Api\StorageStockController::class, 'products']);
        Route::get('/today-status', [\App\Http\Controllers\Api\StorageStockController::class, 'todayStatus']);
        Route::get('/', [\App\Http\Controllers\Api\StorageStockController::class, 'index']);
        Route::post('/', [\App\Http\Controllers\Api\StorageStockController::class, 'store']);
        Route::get('/{id}', [\App\Http\Controllers\Api\StorageStockController::class, 'show']);
    });

    // Store Consumption (konsumsi bahan toko) — stock_cards for=store_consumption.
    Route::prefix('store-consumptions')->group(function () {
        Route::get('/products', [\App\Http\Controllers\Api\StoreConsumptionController::class, 'products']);
        Route::get('/', [\App\Http\Controllers\Api\StoreConsumptionController::class, 'index']);
        Route::post('/', [\App\Http\Controllers\Api\StoreConsumptionController::class, 'store']);
        Route::get('/{id}', [\App\Http\Controllers\Api\StoreConsumptionController::class, 'show']);
        Route::put('/{id}', [\App\Http\Controllers\Api\StoreConsumptionController::class, 'update']);
        // Admin only — di-guard di dalam controller.
        Route::patch('/{id}/status', [\App\Http\Controllers\Api\StoreConsumptionController::class, 'updateStatus']);
    });

    // Employee Consumption (sisa stok karyawan) — stock_cards for=employee_consumption.
    Route::prefix('employee-consumptions')->group(function () {
        Route::get('/products', [\App\Http\Controllers\Api\EmployeeConsumptionController::class, 'products']);
        Route::get('/', [\App\Http\Controllers\Api\EmployeeConsumptionController::class, 'index']);
        Route::post('/', [\App\Http\Controllers\Api\EmployeeConsumptionController::class, 'store']);
        Route::get('/{id}', [\App\Http\Controllers\Api\EmployeeConsumptionController::class, 'show']);
        Route::put('/{id}', [\App\Http\Controllers\Api\EmployeeConsumptionController::class, 'update']);
        // Admin only — di-guard di dalam controller.
        Route::patch('/{id}/status', [\App\Http\Controllers\Api\EmployeeConsumptionController::class, 'updateStatus']);
    });

    Route::prefix('transfer-stocks')->group(function () {
        Route::get('/products', [\App\Http\Controllers\Api\TransferStockController::class, 'products']);
        Route::get('/', [\App\Http\Controllers\Api\TransferStockController::class, 'index']);
        Route::post('/', [\App\Http\Controllers\Api\TransferStockController::class, 'store']);
        Route::get('/{id}', [\App\Http\Controllers\Api\TransferStockController::class, 'show']);
    });

    // Inventory Anomaly Comparison (admin & super_admin only — guarded in controller)
    Route::get('/inventory-anomalies/compare', [\App\Http\Controllers\Api\InventoryAnomalyController::class, 'compare']);

    // Sales Dashboard (admin & super_admin only — guarded in controller)
    Route::get('/sales-dashboard', [\App\Http\Controllers\Api\SalesDashboardController::class, 'index']);

    // Production & Recipes (admin & super_admin only — guarded in controller).
    // Mobile fokus ke operasional produksi (create/list/apply), master resep
    // dikelola via apps/admin Filament — endpoint recipe di sini read-only.
    Route::prefix('recipes')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\RecipeController::class, 'index']);
        // by-product didefinisikan SEBELUM {id} agar tidak tertangkap wildcard.
        Route::get('/by-product/{productId}', [\App\Http\Controllers\Api\RecipeController::class, 'byProduct']);
        Route::get('/{id}', [\App\Http\Controllers\Api\RecipeController::class, 'show']);
    });

    Route::prefix('productions')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\ProductionController::class, 'index']);
        Route::post('/', [\App\Http\Controllers\Api\ProductionController::class, 'store']);
        Route::get('/{id}', [\App\Http\Controllers\Api\ProductionController::class, 'show']);
        Route::put('/{id}', [\App\Http\Controllers\Api\ProductionController::class, 'update']);
        Route::post('/{id}/items', [\App\Http\Controllers\Api\ProductionController::class, 'addItem']);
        Route::put('/{id}/items/{itemId}', [\App\Http\Controllers\Api\ProductionController::class, 'updateItem']);
        Route::delete('/{id}/items/{itemId}', [\App\Http\Controllers\Api\ProductionController::class, 'deleteItem']);
        Route::post('/{id}/apply', [\App\Http\Controllers\Api\ProductionController::class, 'apply']);
        Route::post('/{id}/revert', [\App\Http\Controllers\Api\ProductionController::class, 'revert']);
    });

    Route::get('/utilities', [\App\Http\Controllers\Api\UtilityController::class, 'index']);
    // Lookup untuk form utility (toko, satuan, provider).
    Route::get('/utilities/lookups', [\App\Http\Controllers\Api\UtilityController::class, 'lookups']);
    // CRUD utility — khusus admin/super_admin (dicek di controller).
    Route::post('/utilities', [\App\Http\Controllers\Api\UtilityController::class, 'store']);
    Route::put('/utilities/{id}', [\App\Http\Controllers\Api\UtilityController::class, 'update']);
    Route::patch('/utilities/{id}/status', [\App\Http\Controllers\Api\UtilityController::class, 'updateStatus']);
    Route::prefix('utility-usages')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\UtilityUsageController::class, 'index']);
        Route::post('/', [\App\Http\Controllers\Api\UtilityUsageController::class, 'store']);
        Route::get('/{utilityUsage}', [\App\Http\Controllers\Api\UtilityUsageController::class, 'show']);
        Route::post('/{utilityUsage}', [\App\Http\Controllers\Api\UtilityUsageController::class, 'update']);
        Route::delete('/{utilityUsage}', [\App\Http\Controllers\Api\UtilityUsageController::class, 'destroy']);
    });

    Route::prefix('utility-bills')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\UtilityBillController::class, 'index']);
        Route::post('/', [\App\Http\Controllers\Api\UtilityBillController::class, 'store']);
        Route::get('/{id}', [\App\Http\Controllers\Api\UtilityBillController::class, 'show']);
        Route::put('/{id}', [\App\Http\Controllers\Api\UtilityBillController::class, 'update']);
        Route::delete('/{id}', [\App\Http\Controllers\Api\UtilityBillController::class, 'destroy']);
    });

    Route::prefix('readiness')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\ReadinessController::class, 'index']);
        // Alias /history → index (mobile memanggil /readiness/history).
        Route::get('/history', [\App\Http\Controllers\Api\ReadinessController::class, 'index']);
        // List kesiapan seluruh user (admin/super_admin) — ?date=YYYY-MM-DD.
        Route::get('/admin', [\App\Http\Controllers\Api\ReadinessController::class, 'adminIndex']);
        Route::get('/status', [\App\Http\Controllers\Api\ReadinessController::class, 'checkStatus']);
        Route::post('/', [\App\Http\Controllers\Api\ReadinessController::class, 'store']);
        // Ubah status kesiapan diri (admin/super_admin).
        Route::patch('{id}/status', [\App\Http\Controllers\Api\ReadinessController::class, 'updateStatus']);
    });

    Route::prefix('hygiene')->group(function () {
        Route::get('/rooms', [\App\Http\Controllers\Api\HygieneController::class, 'rooms']);
        Route::get('/today-status', [\App\Http\Controllers\Api\HygieneController::class, 'todayStatus']);
        Route::get('/', [\App\Http\Controllers\Api\HygieneController::class, 'index']);
        Route::get('/{id}', [\App\Http\Controllers\Api\HygieneController::class, 'show']);
        Route::post('/', [\App\Http\Controllers\Api\HygieneController::class, 'store']);
        Route::patch('/{id}', [\App\Http\Controllers\Api\HygieneController::class, 'updateStatus']);
        Route::patch('/of-rooms/{id}', [\App\Http\Controllers\Api\HygieneController::class, 'updateRoom']);
    });

    Route::prefix('closing-stores')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\ClosingStoreController::class, 'index']);
        Route::get('/active-draft', [\App\Http\Controllers\Api\ClosingStoreController::class, 'activeDraft']);
        Route::get('/unpaid-transactions', [\App\Http\Controllers\Api\ClosingStoreController::class, 'unpaidTransactions']);
        Route::post('/save', [\App\Http\Controllers\Api\ClosingStoreController::class, 'save']);
        Route::get('/fuel-services', [\App\Http\Controllers\Api\ClosingStoreController::class, 'indexFuelServices']);
        Route::get('/fuel-services/users', [\App\Http\Controllers\Api\ClosingStoreController::class, 'fuelServiceUsers']);
        Route::get('/fuel-services-for-payment', [\App\Http\Controllers\Api\ClosingStoreController::class, 'fuelServicesForPayment']);
        Route::post('/fuel-services', [\App\Http\Controllers\Api\ClosingStoreController::class, 'createFuelService']);
        Route::get('/vehicles', [\App\Http\Controllers\Api\ClosingStoreController::class, 'vehicles']);
        Route::get('/suppliers', [\App\Http\Controllers\Api\ClosingStoreController::class, 'suppliers']);
        Route::get('/{id}', [\App\Http\Controllers\Api\ClosingStoreController::class, 'show']);
    });

    Route::prefix('suppliers')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\SupplierController::class, 'index']);
        Route::post('/', [\App\Http\Controllers\Api\SupplierController::class, 'store']);
        Route::post('/validate-qris', [\App\Http\Controllers\Api\SupplierController::class, 'validateQrisPayload']);
        Route::get('/{id}', [\App\Http\Controllers\Api\SupplierController::class, 'show']);
        Route::post('/{id}', [\App\Http\Controllers\Api\SupplierController::class, 'update']);
        Route::post('/{id}/validate-qris', [\App\Http\Controllers\Api\SupplierController::class, 'validateQris']);
        Route::post('/{id}/qris-dynamic', [\App\Http\Controllers\Api\SupplierController::class, 'qrisDynamic']);
        Route::delete('/{id}', [\App\Http\Controllers\Api\SupplierController::class, 'destroy']);
    });

    Route::get('/provinces', [\App\Http\Controllers\Api\SupplierController::class, 'provinces']);
    Route::get('/cities', [\App\Http\Controllers\Api\SupplierController::class, 'cities']);
    Route::get('/districts', [\App\Http\Controllers\Api\SupplierController::class, 'districts']);
    Route::get('/subdistricts', [\App\Http\Controllers\Api\SupplierController::class, 'subdistricts']);
    Route::get('/postal-codes', [\App\Http\Controllers\Api\SupplierController::class, 'postalCodes']);
    Route::get('/banks', [\App\Http\Controllers\Api\SupplierController::class, 'banks']);

    // Calon konsumen (DeliveryAddress) — CRUD milik user login (sales).
    // Semua query di-scope `user_id = Auth::id()` di dalam controller.
    Route::prefix('delivery-addresses')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\DeliveryAddressController::class, 'index']);
        Route::post('/', [\App\Http\Controllers\Api\DeliveryAddressController::class, 'store']);
        Route::get('/{id}', [\App\Http\Controllers\Api\DeliveryAddressController::class, 'show']);
        Route::put('/{id}', [\App\Http\Controllers\Api\DeliveryAddressController::class, 'update']);
        Route::delete('/{id}', [\App\Http\Controllers\Api\DeliveryAddressController::class, 'destroy']);
    });

    // Asset Management (kategorisasi produk + pemeriksaan berkala + issue).
    Route::prefix('asset-categories')->group(function () {
        Route::get('/', [AssetCategoryController::class, 'index']);
        Route::post('/', [AssetCategoryController::class, 'store']);
        Route::post('/{id}', [AssetCategoryController::class, 'update']);
        Route::delete('/{id}', [AssetCategoryController::class, 'destroy']);
    });

    // Produk ber-flag aset: listing & marking (admin).
    Route::prefix('asset-products')->group(function () {
        Route::get('/', [ProductAssetConfigController::class, 'index']);
        Route::post('/{id}', [ProductAssetConfigController::class, 'update']);
    });

    Route::prefix('assets')->group(function () {
        Route::get('/dashboard', [AssetController::class, 'dashboardSummary']);
        Route::get('/current-store', [AssetController::class, 'currentStore']);
        Route::get('/', [AssetController::class, 'index']);
        Route::post('/from-product', [AssetController::class, 'createFromProduct']);
        Route::post('/', [AssetController::class, 'store']);
        Route::get('/{id}', [AssetController::class, 'show']);
        Route::post('/{id}', [AssetController::class, 'update']);
        Route::delete('/{id}', [AssetController::class, 'destroy']);
    });

    Route::prefix('asset-checks')->group(function () {
        Route::get('/today-status/{assetId}', [AssetCheckController::class, 'checkTodayStatus']);
        Route::get('/', [AssetCheckController::class, 'index']);
        Route::post('/', [AssetCheckController::class, 'store']);
        Route::get('/{id}', [AssetCheckController::class, 'show']);
    });

    Route::prefix('asset-issues')->group(function () {
        Route::get('/', [AssetIssueController::class, 'index']);
        Route::post('/{id}/close', [AssetIssueController::class, 'close']);
    });

    Route::prefix('admin')->group(function () {

        // Dashboard routes
        Route::get('dashboard-stats', [AdminDashboardController::class, 'stats']);
        Route::get('presence-trends', [AdminDashboardController::class, 'trends']);
        Route::get('absent-employees', [AdminDashboardController::class, 'absentEmployees']);
        Route::get('not-checked-out', [AdminDashboardController::class, 'notCheckedOut']);
        Route::get('recent-activities', [AdminReportController::class, 'recentActivities']);

        // Reports routes
        Route::get('reports/types', [AdminReportController::class, 'types']);
        Route::get('reports/monthly', [AdminReportController::class, 'monthly']);
        Route::get('late-employees', [AdminReportController::class, 'lateEmployees']);
        Route::get('attendance-summary', [AdminReportController::class, 'attendanceSummary']);
        Route::post('reports/export', [AdminReportController::class, 'export']);

        // Presence management routes
        Route::apiResource('presences', AdminPresenceController::class);
        Route::post('presences/check-duplicate', [AdminPresenceController::class, 'checkDuplicate']);

        // Export routes
        Route::post('presences/export', [AdminPresenceController::class, 'export']);
        Route::get('presences/export/{jobId}/status', [AdminReportController::class, 'getExportStatus']);
        Route::get('presences/export/history', [AdminReportController::class, 'getExportHistory']);
        Route::delete('presences/export/{jobId}', [AdminPresenceController::class, 'cancelExport']);

        // Leave management routes — bungkus dengan middleware 'admin' agar hanya
        // role admin yang bisa melihat semua pengajuan + approve/reject/export.
        // Sebelumnya hanya auth:sanctum, sehingga non-admin bisa mengaksesnya.
        Route::middleware('admin')->group(function () {
            Route::get('leaves', [AdminLeaveController::class, 'index']);
            Route::get('leaves/{id}', [AdminLeaveController::class, 'show']);
            Route::post('leaves/{id}/approve', [AdminLeaveController::class, 'approve']);
            Route::post('leaves/{id}/reject', [AdminLeaveController::class, 'reject']);
            Route::post('leaves/export', [AdminLeaveController::class, 'export']);
        });

        // Employee location tracking (admin)
        // Bungkus rute sensitif dengan middleware 'admin' untuk memastikan
        // hanya user ber-peran admin yang bisa memicu pelacakan lokasi.
        Route::middleware('admin')->group(function () {
            Route::post('track-location/{user}', [AdminTrackLocationController::class, 'trigger']);
            Route::get('track-location/{location_request}', [AdminTrackLocationController::class, 'showRequest']);
            Route::get('employee-locations', [AdminTrackLocationController::class, 'latestLocations']);
            Route::get('employee-locations/{user}', [AdminTrackLocationController::class, 'history']);
        });
     });
});
