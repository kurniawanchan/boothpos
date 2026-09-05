<?php

use App\Http\Controllers\Api\ActivityLogController;
use App\Http\Controllers\Api\ArtistController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BusinessTypeController;
use App\Http\Controllers\Api\CashierSessionController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\CompanyController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\MasterDataExportController;
use App\Http\Controllers\Api\MasterDataImportController;
use App\Http\Controllers\Api\MaterialController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PackageController;
use App\Http\Controllers\Api\PaymentChannelController;
use App\Http\Controllers\Api\PaymentProofController;
use App\Http\Controllers\Api\PreorderController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\ShipmentController;
use App\Http\Controllers\Api\StockController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\PosDraftController;
use App\Http\Controllers\Api\PurchaseOrderController;
use App\Http\Controllers\Api\VendorController;
use App\Http\Middleware\SetLocaleFromUser;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:5,1');

    // SetLocaleFromUser HANYA di sini, bukan pada POST /auth/login di
    // atas — layar login selalu Bahasa Indonesia (FR-001), locale
    // per-akun baru berlaku setelah $request->user() resolve.
    Route::middleware(['auth:sanctum', SetLocaleFromUser::class])->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::put('/auth/language', [AuthController::class, 'updateLanguage']);
        // 005-ux-enhancements-dashboard (US3) — swa-layanan, sengaja
        // terpisah dari /users/{user} (lihat komentar
        // AuthController::updatePassword/updatePhoto).
        Route::put('/auth/password', [AuthController::class, 'updatePassword']);
        Route::post('/auth/photo', [AuthController::class, 'updatePhoto']);

        Route::get('/settings/features', [SettingsController::class, 'features']);
        Route::get('/settings', [SettingsController::class, 'index']);
        Route::put('/settings', [SettingsController::class, 'update']);
        Route::post('/settings/store-logo', [SettingsController::class, 'uploadStoreLogo']);

        Route::get('/activity-logs', [ActivityLogController::class, 'index']);

        // Manajemen pengguna (001-user-store-settings US1). Foto memakai
        // pola yang sama seperti /products/{product}/image — attach
        // terpisah, bukan bagian dari apiResource, karena multipart.
        Route::apiResource('users', UserController::class);
        Route::post('/users/{user}/photo', [UserController::class, 'uploadPhoto']);

        // Peran (Role) dan registry menu — 001-user-store-settings User
        // Story 2. /menu-keys adalah App\Support\MenuKeys registry, bukan
        // bagian dari resource /roles — dipisah supaya frontend bisa
        // memuat daftar menu (untuk checkbox RoleMenuPicker) tanpa harus
        // punya akses baca ke satu peran pun.
        Route::get('/menu-keys', [RoleController::class, 'menuKeys']);
        Route::apiResource('roles', RoleController::class);

        Route::apiResource('artists', ArtistController::class);
        Route::apiResource('categories', CategoryController::class);
        Route::post('/categories/{category}/image', [CategoryController::class, 'uploadImage']);
        Route::apiResource('customers', CustomerController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::get('/customers/{customer}/transactions', [CustomerController::class, 'transactions']);

        Route::apiResource('products', ProductController::class);
        Route::post('/products/{product}/image', [ProductController::class, 'uploadImage']);
        Route::post('/products/{product}/variants', [ProductController::class, 'storeVariant']);
        Route::put('/variants/{variant}', [ProductController::class, 'updateVariant']);
        Route::get('/variants/lookup', [ProductController::class, 'lookupVariants']);

        Route::get('/stock/movements', [StockController::class, 'movements']);
        Route::post('/stock/adjustments', [StockController::class, 'adjust']);
        Route::get('/stock/low', [StockController::class, 'lowStock']);

        // Vendor/bahan baku/BOM — pasca-MVP, ditambahkan 2026-09-01 (lihat
        // catatan bertanggal di CLAUDE.md/README.md/PRD). Harga vendor per
        // bahan dan BOM per varian digantung di bawah /materials dan
        // /variants karena aksinya sesungguhnya adalah attach/detach
        // relasi, bukan CRUD entitas mandiri — sama seperti
        // /products/{product}/variants tidak berdiri sendiri sebagai
        // apiResource.
        Route::apiResource('vendors', VendorController::class);
        Route::apiResource('materials', MaterialController::class);

        // 006-purchase-order-and-ops (US1) — membalik pencoretan PRD §10.2
        // "purchase management (PO to vendors)" (lihat migrasi
        // create_purchase_orders_and_items_tables untuk rasional lengkap).
        Route::apiResource('purchase-orders', PurchaseOrderController::class);
        Route::patch('/purchase-orders/{purchase_order}/status', [PurchaseOrderController::class, 'updateStatus']);
        Route::post('/purchase-orders/{purchase_order}/payments', [PurchaseOrderController::class, 'storePayment']);
        Route::get('/purchase-orders/{purchase_order}/invoice', [PurchaseOrderController::class, 'invoice']);
        Route::post('/materials/{material}/vendor-prices', [MaterialController::class, 'storeVendorPrice']);
        Route::put('/vendor-prices/{vendorPrice}', [MaterialController::class, 'updateVendorPrice']);
        Route::delete('/vendor-prices/{vendorPrice}', [MaterialController::class, 'destroyVendorPrice']);

        // 017-company-onboarding — pipeline sales/ops internal, gated
        // 'companies' menu key (owner/admin only, lihat migrasi
        // add_companies_menu_key_to_default_roles). Tidak apiResource
        // penuh untuk companies — tidak ada update/destroy di scope
        // fitur ini (spec.md tidak memintanya).
        Route::apiResource('business-types', BusinessTypeController::class);
        Route::apiResource('packages', PackageController::class);
        Route::get('/companies', [CompanyController::class, 'index']);
        Route::post('/companies', [CompanyController::class, 'store']);
        Route::get('/companies/{company}', [CompanyController::class, 'show']);
        Route::post('/companies/{company}/resend-activation', [CompanyController::class, 'resendActivation']);
        Route::post('/companies/{company}/activate', [CompanyController::class, 'activate'])->middleware('throttle:10,1');

        Route::get('/variants/{variant}/bom', [MaterialController::class, 'bomIndex']);
        Route::post('/variants/{variant}/bom', [MaterialController::class, 'storeBomLine']);
        Route::put('/bom/{bomLine}', [MaterialController::class, 'updateBomLine']);
        Route::delete('/bom/{bomLine}', [MaterialController::class, 'destroyBomLine']);
        Route::get('/variants/{variant}/cost-breakdown', [MaterialController::class, 'costBreakdown']);

        Route::apiResource('events', EventController::class);
        Route::patch('/events/{event}/status', [EventController::class, 'updateStatus']);

        Route::get('/sessions/current', [CashierSessionController::class, 'current']);
        Route::post('/sessions', [CashierSessionController::class, 'store']);
        Route::post('/sessions/{session}/close', [CashierSessionController::class, 'close']);
        Route::get('/sessions/{session}/summary', [CashierSessionController::class, 'summary']);

        Route::get('/payment-channels', [PaymentChannelController::class, 'index']);
        Route::post('/payment-channels', [PaymentChannelController::class, 'store']);
        Route::post('/payment-channels/{channel}', [PaymentChannelController::class, 'update']);
        Route::post('/payment-proofs', [PaymentProofController::class, 'store']);
        Route::get('/payment-proofs/{proof}/file', [PaymentProofController::class, 'show'])->name('payment-proofs.file');

        Route::get('/orders', [OrderController::class, 'index']);
        Route::post('/orders', [OrderController::class, 'store']);

        // 006-purchase-order-and-ops (US4) — draft transaksi kasir, tanpa
        // efek stok/pembayaran apa pun sampai draft di-checkout jadi order
        // sungguhan (lihat komentar PosDraftController/Service).
        Route::apiResource('pos-drafts', PosDraftController::class)->only(['index', 'store', 'show', 'destroy']);
        Route::get('/orders/{order}', [OrderController::class, 'show']);
        Route::post('/orders/{order}/void', [OrderController::class, 'void']);
        Route::get('/orders/{order}/receipt', [OrderController::class, 'receipt']);

        // 007-preorder-import-export-notify — rute statis ('export',
        // 'import/template', 'import') WAJIB didaftarkan SEBELUM
        // apiResource('preorders', ...)'s 'show' (GET /preorders/{preorder}),
        // supaya 'export'/'import' tidak tertangkap sebagai {preorder} id
        // dan gagal route-model-binding (404).
        Route::get('/preorders/export', [PreorderController::class, 'export']);
        Route::get('/preorders/import/template', [PreorderController::class, 'importTemplate']);
        Route::post('/preorders/import', [PreorderController::class, 'import']);

        // 013-preorder-list-filters-receipt (T023) — rute statis 'summary'
        // WAJIB didaftarkan SEBELUM apiResource('preorders', ...)'s 'show'
        // (GET /preorders/{preorder}), dengan alasan sama seperti
        // 'export'/'import' di atas: supaya "summary" tidak tertangkap
        // sebagai {preorder} id dan gagal route-model-binding.
        Route::get('/preorders/summary', [PreorderController::class, 'summary']);

        Route::apiResource('preorders', PreorderController::class)->only(['index', 'store', 'show']);
        Route::patch('/preorders/{preorder}/status', [PreorderController::class, 'updateStatus']);
        Route::post('/preorders/{preorder}/payments', [PreorderController::class, 'storePayment']);
        Route::post('/preorders/{preorder}/shipment', [ShipmentController::class, 'store']);
        Route::get('/preorders/{preorder}/invoice', [PreorderController::class, 'invoice']);
        Route::post('/preorders/{preorder}/notifications/resend', [PreorderController::class, 'resendNotification']);
        Route::patch('/shipments/{shipment}', [ShipmentController::class, 'update']);

        // Ekspor/impor master data (PRD 7.15). Dikelompokkan di bawah
        // /exports dan /imports — bukan /artists/export dsb — supaya tidak
        // bertabrakan dengan apiResource /artists/{artist} dan supaya
        // seluruh permukaan berkas berpasangan simetris di satu tempat.
        Route::get('/exports/{entity}', [MasterDataExportController::class, 'show'])
            ->where('entity', 'artists|categories|products|stock|vendors|materials|vendor_prices|bom|roles|users');
        Route::get('/imports/master-data/template', [MasterDataImportController::class, 'template']);
        Route::post('/imports/master-data', [MasterDataImportController::class, 'store']);

        Route::get('/reports/sales', [ReportController::class, 'sales']);
        Route::get('/reports/profit', [ReportController::class, 'profit']);
        Route::get('/reports/purchases', [ReportController::class, 'purchases']);
        Route::get('/reports/stock-by-artist', [ReportController::class, 'stockByArtist']);
        Route::get('/reports/artist-profit', [ReportController::class, 'artistProfit']);
        Route::get('/reports/artist-settlements', [ReportController::class, 'artistSettlements']);
        Route::get('/reports/artist-settlements/{artist}/transactions', [ReportController::class, 'artistSettlementTransactions']);
        // 010-split-payment-preorder-reports (US6) — laporan baru khusus
        // pre-order (status × kelengkapan pembayaran), gated sama seperti
        // laporan lain di atas (canAccessMenu('reports') di dalam method).
        Route::get('/reports/preorders', [ReportController::class, 'preorders']);
        Route::post('/reports/artist-settlements/{settlement}/payment', [ReportController::class, 'recordSettlementPayment']);
        Route::get('/reports/{report}/export', [ReportController::class, 'export'])
            ->where('report', 'sales|profit|artist-settlements|artist-profit|purchases|stock-by-artist|preorder');
    });
});
