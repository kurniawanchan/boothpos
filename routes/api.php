<?php

use App\Http\Controllers\Api\ActivityLogController;
use App\Http\Controllers\Api\ArtistController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BackupController;
use App\Http\Controllers\Api\BusinessTypeController;
use App\Http\Controllers\Api\CashierSessionController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\CompanyController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\InvoiceExportImportController;
use App\Http\Controllers\Api\InvoicePaymentSettingController;
use App\Http\Controllers\Api\LicenseCatalogController;
use App\Http\Controllers\Api\LicenseController;
use App\Http\Controllers\Api\MasterDataExportController;
use App\Http\Controllers\Api\MasterDataImportController;
use App\Http\Controllers\Api\MaterialController;
use App\Http\Controllers\Api\OrderController;
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
use App\Http\Controllers\Api\VariantBomController;
use App\Http\Controllers\Api\PosDraftController;
use App\Http\Controllers\Api\PurchaseOrderController;
use App\Http\Controllers\Api\VendorController;
use App\Http\Middleware\SetLocaleFromUser;
use App\Services\BackupService;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    // 018-license-activation — kedua endpoint ini SENGAJA tanpa
    // auth:sanctum DAN dikecualikan dari EnsureInstallationIsActivated
    // (lihat middleware itu sendiri) — harus tetap bisa dijangkau
    // selagi instalasi belum ter-aktivasi sama sekali (FR-002).
    Route::get('/license/status', [LicenseController::class, 'status']);
    Route::post('/license/activate', [LicenseController::class, 'activate']);

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
        Route::get('/settings/payment', [InvoicePaymentSettingController::class, 'show']);
        Route::put('/settings/payment', [InvoicePaymentSettingController::class, 'update']);

        // Cadangan & pemulihan database (Pengaturan) — owner/admin, dicek di
        // BackupController. 'restore-upload' didaftarkan sebelum rute ber-{id}
        // dan {id} dibatasi polanya, jadi id sembarang (mis. '../x') 404 di rute.
        Route::get('/backups', [BackupController::class, 'index']);
        Route::post('/backups', [BackupController::class, 'store']);
        Route::post('/backups/restore-upload', [BackupController::class, 'restoreUpload']);
        Route::get('/backups/{id}/download', [BackupController::class, 'download'])->where('id', BackupService::ID_PATTERN);
        Route::post('/backups/{id}/restore', [BackupController::class, 'restore'])->where('id', BackupService::ID_PATTERN);
        Route::delete('/backups/{id}', [BackupController::class, 'destroy'])->where('id', BackupService::ID_PATTERN);

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
        // 020-customer-data-import-export — rute statis DIDAFTARKAN
        // sebelum apiResource, mengikuti konvensi yang sama seperti
        // /preorders/export (meski resource ini hari ini belum
        // mendaftarkan GET /customers/{customer}, urutan ini tetap dijaga
        // sebagai proteksi terhadap penambahan rute {customer} di masa depan).
        Route::get('/customers/export', [CustomerController::class, 'export']);
        Route::get('/customers/import/template', [CustomerController::class, 'importTemplate']);
        Route::post('/customers/import', [CustomerController::class, 'import']);
        Route::apiResource('customers', CustomerController::class)->only(['index', 'show', 'store', 'update', 'destroy']);
        Route::get('/customers/{customer}/transactions', [CustomerController::class, 'transactions']);

        Route::apiResource('products', ProductController::class);
        Route::post('/products/{product}/image', [ProductController::class, 'uploadImage']);
        Route::post('/products/{product}/variants', [ProductController::class, 'storeVariant']);
        Route::put('/variants/{variant}', [ProductController::class, 'updateVariant']);
        Route::post('/variants/{variant}/image', [ProductController::class, 'uploadVariantImage']);
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
        // add_companies_menu_key_to_default_roles).
        // 019-billing-system (second expansion, T087) — update/destroy
        // ditambahkan (research.md R10/R11); tetap bukan apiResource penuh
        // karena tidak ada 'store' terpisah dari onboarding flow di atas.
        Route::apiResource('business-types', BusinessTypeController::class);
        // 019-billing-system — rename dari /packages (research.md R1',
        // R0). LicenseCatalogController, BUKAN LicenseController — nama
        // itu sudah dipakai gerbang aktivasi instalasi 018 di atas.
        Route::apiResource('licenses', LicenseCatalogController::class);
        Route::get('/companies', [CompanyController::class, 'index']);
        Route::post('/companies', [CompanyController::class, 'store']);
        Route::get('/companies/{company}', [CompanyController::class, 'show']);
        Route::put('/companies/{company}', [CompanyController::class, 'update']);
        Route::delete('/companies/{company}', [CompanyController::class, 'destroy']);
        Route::post('/companies/{company}/activate', [CompanyController::class, 'activate'])->middleware('throttle:10,1');
        Route::post('/companies/{company}/deactivate', [CompanyController::class, 'deactivate'])->middleware('throttle:10,1');

        // 019-billing-system (contracts/api.md, research.md R2') — Invoice
        // kini dokumen mandiri dengan menu sendiri ('invoices'), bukan lagi
        // anak /companies/{company}. /invoices/summary WAJIB didaftarkan
        // SEBELUM apiResource('invoices', ...)'s {invoice} agar 'summary'
        // tidak ditangkap sebagai route-model-binding {invoice} (pola sama
        // dengan /preorders/export di atas apiResource('preorders', ...)).
        Route::get('/invoices/summary', [InvoiceController::class, 'summary']);
        // 019-billing-system (T075) — sama seperti /preorders/export di
        // atas apiResource('preorders', ...): rute statis ini WAJIB
        // didaftarkan SEBELUM apiResource('invoices', ...)'s {invoice}
        // supaya 'export'/'import' tidak ditangkap sebagai route-model-
        // binding {invoice}.
        Route::get('/invoices/export', [InvoiceExportImportController::class, 'export']);
        Route::get('/invoices/import/template', [InvoiceExportImportController::class, 'importTemplate']);
        Route::post('/invoices/import', [InvoiceExportImportController::class, 'import']);
        Route::post('/invoices/{invoice}/mark-paid', [InvoiceController::class, 'markPaid']);
        Route::post('/invoices/{invoice}/cancel', [InvoiceController::class, 'cancel']);
        Route::apiResource('invoices', InvoiceController::class)->only(['index', 'store', 'show', 'update', 'destroy']);

        // 034-seller-po-bom — BOM bersumber baris purchase order. Rute statis
        // 'bom/eligible-lines' & 'bom/items' sengaja di atas rute lain yang
        // berparameter. POST /variants/{variant}/bom (bahan + jumlah) tetap
        // ada sebagai jalur LEGACY dan membuat baris legacy.
        Route::get('/variants/{variant}/bom/eligible-lines', [VariantBomController::class, 'eligibleLines']);
        Route::post('/variants/{variant}/bom/items', [VariantBomController::class, 'storeItems']);
        Route::post('/variants/{variant}/bom/copy', [VariantBomController::class, 'copyFrom']);
        Route::post('/variants/{variant}/bom/copy-out', [VariantBomController::class, 'copyOut']);
        Route::post('/variants/{variant}/bom/complete', [VariantBomController::class, 'complete']);
        Route::post('/variants/{variant}/bom/reopen', [VariantBomController::class, 'reopen']);
        Route::get('/variants/{variant}/bom', [VariantBomController::class, 'index']);
        // 036 — tombol Simpan dialog BOM: simpan banyak jumlah sekaligus (semua-atau-tidak-sama-sekali).
        Route::put('/variants/{variant}/bom', [VariantBomController::class, 'updateQuantities']);
        Route::post('/variants/{variant}/bom', [MaterialController::class, 'storeBomLine']);
        Route::put('/bom/{bomLine}', [VariantBomController::class, 'update']);
        Route::post('/bom/{bomLine}/replace-source', [VariantBomController::class, 'replaceSource']);
        Route::delete('/bom/{bomLine}', [VariantBomController::class, 'destroy']);
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
        Route::delete('/payment-channels/{channel}', [PaymentChannelController::class, 'destroy']);
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
        // 028-partial-split-payment — pembayaran susulan / koreksi untuk penjualan POS
        // yang dibayar sebagian (hapus = owner/admin, dijaga di controller).
        Route::post('/orders/{order}/payments', [OrderController::class, 'storePayment']);
        Route::delete('/orders/{order}/payments/{payment}', [OrderController::class, 'destroyPayment']);
        Route::patch('/orders/{order}/payments/{payment}/confirmation', [OrderController::class, 'updatePaymentConfirmation']);
        Route::post('/orders/{order}/payments/{payment}/verify', [OrderController::class, 'verifyPayment']);
        Route::post('/orders/verify-payments', [OrderController::class, 'verifyPayments']);
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

        // 022-preorder-invoice-crud-overhaul (US5) — rute statis 'bulk-*'
        // WAJIB didaftarkan SEBELUM apiResource('preorders', ...)'s 'show',
        // alasan sama seperti 'export'/'import'/'summary' di atas.
        Route::post('/preorders/bulk-invoices', [PreorderController::class, 'bulkInvoices']);
        Route::post('/preorders/bulk-email', [PreorderController::class, 'bulkEmailInvoices']);

        // 027-preorder-duplicate-split — rute statis, WAJIB sebelum
        // apiResource('preorders', ...) (alasan sama dengan 'bulk-*' di atas).
        Route::post('/preorders/duplicate', [PreorderController::class, 'duplicate']);

        Route::apiResource('preorders', PreorderController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
        Route::patch('/preorders/{preorder}/status', [PreorderController::class, 'updateStatus']);
        Route::patch('/preorders/{preorder}/dispatch-status', [PreorderController::class, 'updateDispatchStatus']);
        Route::post('/preorders/{preorder}/payments', [PreorderController::class, 'storePayment']);
        Route::delete('/preorders/{preorder}/payments/{payment}', [PreorderController::class, 'destroyPayment']);
        Route::patch('/preorders/{preorder}/payments/{payment}/confirmation', [PreorderController::class, 'updatePaymentConfirmation']);
        Route::post('/preorders/{preorder}/payments/{payment}/verify', [PreorderController::class, 'verifyPayment']);
        Route::post('/preorders/{preorder}/split', [PreorderController::class, 'split']);
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
        Route::post('/reports/sales/transactions/export', [ReportController::class, 'exportSalesTransactions']);
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
