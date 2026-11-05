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
use App\Http\Controllers\Api\ProductCatalogController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\ShipmentController;
use App\Http\Controllers\Api\StockController;
use App\Http\Controllers\Api\SellerSettlementController;
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
        Route::get('/settings', [SettingsController::class, 'index'])->middleware('seller.forbidden');
        Route::put('/settings', [SettingsController::class, 'update'])->middleware('ability:settings')->middleware('seller.forbidden');
        Route::post('/settings/store-logo', [SettingsController::class, 'uploadStoreLogo'])->middleware('ability:settings,branding')->middleware('seller.forbidden');
        Route::get('/settings/payment', [InvoicePaymentSettingController::class, 'show'])->middleware('seller.forbidden');
        Route::put('/settings/payment', [InvoicePaymentSettingController::class, 'update'])->middleware('ability:settings')->middleware('seller.forbidden');

        // Cadangan & pemulihan database (Pengaturan) — owner/admin, dicek di
        // BackupController. 'restore-upload' didaftarkan sebelum rute ber-{id}
        // dan {id} dibatasi polanya, jadi id sembarang (mis. '../x') 404 di rute.
        Route::get('/backups', [BackupController::class, 'index'])->middleware('seller.forbidden');
        Route::post('/backups', [BackupController::class, 'store'])->middleware('ability:settings,backup')->middleware('seller.forbidden');
        Route::post('/backups/restore-upload', [BackupController::class, 'restoreUpload'])->middleware('ability:settings,restore')->middleware('seller.forbidden');
        Route::get('/backups/{id}/download', [BackupController::class, 'download'])->middleware('ability:settings,backup')->where('id', BackupService::ID_PATTERN)->middleware('seller.forbidden');
        Route::post('/backups/{id}/restore', [BackupController::class, 'restore'])->middleware('ability:settings,restore')->where('id', BackupService::ID_PATTERN)->middleware('seller.forbidden');
        Route::delete('/backups/{id}', [BackupController::class, 'destroy'])->middleware('ability:settings,backup')->where('id', BackupService::ID_PATTERN)->middleware('seller.forbidden');

        Route::get('/activity-logs', [ActivityLogController::class, 'index'])->middleware('seller.forbidden');

        // Manajemen pengguna (001-user-store-settings US1). Foto memakai
        // pola yang sama seperti /products/{product}/image — attach
        // terpisah, bukan bagian dari apiResource, karena multipart.
        Route::apiResource('users', UserController::class)->only(['index', 'show'])->middleware('seller.forbidden');
        Route::apiResource('users', UserController::class)->only(['store', 'update', 'destroy'])->middleware(['ability:users', 'seller.forbidden']);
        Route::post('/users/{user}/photo', [UserController::class, 'uploadPhoto'])->middleware('ability:users,edit')->middleware('seller.forbidden');

        // Peran (Role) dan registry menu — 001-user-store-settings User
        // Story 2. /menu-keys adalah App\Support\MenuKeys registry, bukan
        // bagian dari resource /roles — dipisah supaya frontend bisa
        // memuat daftar menu (untuk checkbox RoleMenuPicker) tanpa harus
        // punya akses baca ke satu peran pun.
        Route::get('/menu-keys', [RoleController::class, 'menuKeys'])->middleware('seller.forbidden');
        Route::apiResource('roles', RoleController::class)->only(['index', 'show'])->middleware('seller.forbidden');
        Route::apiResource('roles', RoleController::class)->only(['store', 'update', 'destroy'])->middleware(['ability:roles', 'seller.forbidden']);

        Route::apiResource('artists', ArtistController::class)->only(['index', 'show']);
        Route::apiResource('artists', ArtistController::class)->only(['store', 'update', 'destroy'])->middleware(['ability:artists', 'seller.forbidden']);
        Route::apiResource('categories', CategoryController::class)->only(['index', 'show']);
        Route::apiResource('categories', CategoryController::class)->only(['store', 'update', 'destroy'])->middleware(['ability:categories', 'seller.forbidden']);
        Route::post('/categories/{category}/image', [CategoryController::class, 'uploadImage'])->middleware('ability:categories,edit')->middleware('seller.forbidden');
        // 020-customer-data-import-export — rute statis DIDAFTARKAN
        // sebelum apiResource, mengikuti konvensi yang sama seperti
        // /preorders/export (meski resource ini hari ini belum
        // mendaftarkan GET /customers/{customer}, urutan ini tetap dijaga
        // sebagai proteksi terhadap penambahan rute {customer} di masa depan).
        Route::get('/customers/export', [CustomerController::class, 'export'])->middleware('ability:customers,export')->middleware('seller.forbidden');
        Route::get('/customers/import/template', [CustomerController::class, 'importTemplate'])->middleware('ability:customers,import')->middleware('seller.forbidden');
        Route::post('/customers/import', [CustomerController::class, 'import'])->middleware('ability:customers,import')->middleware('seller.forbidden');
        Route::apiResource('customers', CustomerController::class)->only(['index', 'show']);
        Route::apiResource('customers', CustomerController::class)->only(['store', 'update', 'destroy'])->middleware('ability:customers');
        Route::get('/customers/{customer}/transactions', [CustomerController::class, 'transactions']);

        Route::apiResource('products', ProductController::class)->only(['index', 'show']);
        Route::apiResource('products', ProductController::class)->only(['store', 'update', 'destroy'])->middleware('ability:products');
        Route::post('/products/{product}/image', [ProductController::class, 'uploadImage'])->middleware('ability:products,edit');
        Route::post('/products/{product}/variants', [ProductController::class, 'storeVariant'])->middleware('ability:products,create');
        Route::put('/products/{product}/variant-sequences', [ProductController::class, 'updateSequences'])->middleware('ability:products,edit');
        Route::put('/variants/{variant}', [ProductController::class, 'updateVariant'])->middleware('ability:products,edit');
        Route::delete('/variants/{variant}', [ProductController::class, 'destroyVariant'])->middleware('ability:products,delete');
        Route::post('/variants/{variant}/image', [ProductController::class, 'uploadVariantImage'])->middleware('ability:products,edit');
        Route::get('/variants/lookup', [ProductController::class, 'lookupVariants']);
        Route::get('/catalog', [ProductCatalogController::class, 'index'])->middleware('ability:products,catalog');
        Route::get('/catalog/export', [ProductCatalogController::class, 'export'])->middleware('ability:products,catalog');

        Route::get('/stock/movements', [StockController::class, 'movements']);
        Route::post('/stock/adjustments', [StockController::class, 'adjust'])->middleware('ability:stock,adjust');
        Route::get('/stock/low', [StockController::class, 'lowStock']);

        // Vendor/bahan baku/BOM — pasca-MVP, ditambahkan 2026-09-01 (lihat
        // catatan bertanggal di CLAUDE.md/README.md/PRD). Harga vendor per
        // bahan dan BOM per varian digantung di bawah /materials dan
        // /variants karena aksinya sesungguhnya adalah attach/detach
        // relasi, bukan CRUD entitas mandiri — sama seperti
        // /products/{product}/variants tidak berdiri sendiri sebagai
        // apiResource.
        Route::apiResource('vendors', VendorController::class)->only(['index', 'show']);
        Route::apiResource('vendors', VendorController::class)->only(['store', 'update', 'destroy'])->middleware(['ability:vendors', 'seller.forbidden']);
        Route::apiResource('materials', MaterialController::class)->only(['index', 'show']);
        Route::apiResource('materials', MaterialController::class)->only(['store', 'update', 'destroy'])->middleware(['ability:materials', 'seller.forbidden']);

        // 006-purchase-order-and-ops (US1) — membalik pencoretan PRD §10.2
        // "purchase management (PO to vendors)" (lihat migrasi
        // create_purchase_orders_and_items_tables untuk rasional lengkap).
        Route::apiResource('purchase-orders', PurchaseOrderController::class)->only(['index', 'show']);
        Route::apiResource('purchase-orders', PurchaseOrderController::class)->only(['store', 'update', 'destroy'])->middleware('ability:purchase_orders');
        Route::patch('/purchase-orders/{purchase_order}/status', [PurchaseOrderController::class, 'updateStatus'])->middleware('ability:purchase_orders,status');
        Route::post('/purchase-orders/{purchase_order}/payments', [PurchaseOrderController::class, 'storePayment'])->middleware('ability:purchase_orders,payment');
        Route::get('/purchase-orders/{purchase_order}/invoice', [PurchaseOrderController::class, 'invoice']);
        Route::post('/materials/{material}/vendor-prices', [MaterialController::class, 'storeVendorPrice'])->middleware('ability:materials,edit')->middleware('seller.forbidden');
        Route::put('/vendor-prices/{vendorPrice}', [MaterialController::class, 'updateVendorPrice'])->middleware('ability:materials,edit')->middleware('seller.forbidden');
        Route::delete('/vendor-prices/{vendorPrice}', [MaterialController::class, 'destroyVendorPrice'])->middleware('ability:materials,edit')->middleware('seller.forbidden');

        // 017-company-onboarding — pipeline sales/ops internal, gated
        // 'companies' menu key (owner/admin only, lihat migrasi
        // add_companies_menu_key_to_default_roles).
        // 019-billing-system (second expansion, T087) — update/destroy
        // ditambahkan (research.md R10/R11); tetap bukan apiResource penuh
        // karena tidak ada 'store' terpisah dari onboarding flow di atas.
        Route::apiResource('business-types', BusinessTypeController::class)->only(['index', 'show'])->middleware('seller.forbidden');
        Route::apiResource('business-types', BusinessTypeController::class)->only(['store', 'update', 'destroy'])->middleware(['ability:companies', 'seller.forbidden']);
        // 019-billing-system — rename dari /packages (research.md R1',
        // R0). LicenseCatalogController, BUKAN LicenseController — nama
        // itu sudah dipakai gerbang aktivasi instalasi 018 di atas.
        Route::apiResource('licenses', LicenseCatalogController::class)->only(['index', 'show'])->middleware('seller.forbidden');
        Route::apiResource('licenses', LicenseCatalogController::class)->only(['store', 'update', 'destroy'])->middleware(['ability:licenses', 'seller.forbidden']);
        Route::get('/companies', [CompanyController::class, 'index'])->middleware('seller.forbidden');
        Route::post('/companies', [CompanyController::class, 'store'])->middleware('ability:companies,create')->middleware('seller.forbidden');
        Route::get('/companies/{company}', [CompanyController::class, 'show'])->middleware('seller.forbidden');
        Route::put('/companies/{company}', [CompanyController::class, 'update'])->middleware('ability:companies,edit')->middleware('seller.forbidden');
        Route::delete('/companies/{company}', [CompanyController::class, 'destroy'])->middleware('ability:companies,delete')->middleware('seller.forbidden');
        Route::post('/companies/{company}/activate', [CompanyController::class, 'activate'])->middleware('ability:companies,activate')->middleware('throttle:10,1')->middleware('seller.forbidden');
        Route::post('/companies/{company}/deactivate', [CompanyController::class, 'deactivate'])->middleware('ability:companies,activate')->middleware('throttle:10,1')->middleware('seller.forbidden');

        // 019-billing-system (contracts/api.md, research.md R2') — Invoice
        // kini dokumen mandiri dengan menu sendiri ('invoices'), bukan lagi
        // anak /companies/{company}. /invoices/summary WAJIB didaftarkan
        // SEBELUM apiResource('invoices', ...)'s {invoice} agar 'summary'
        // tidak ditangkap sebagai route-model-binding {invoice} (pola sama
        // dengan /preorders/export di atas apiResource('preorders', ...)).
        Route::get('/invoices/summary', [InvoiceController::class, 'summary'])->middleware('seller.forbidden');
        // 019-billing-system (T075) — sama seperti /preorders/export di
        // atas apiResource('preorders', ...): rute statis ini WAJIB
        // didaftarkan SEBELUM apiResource('invoices', ...)'s {invoice}
        // supaya 'export'/'import' tidak ditangkap sebagai route-model-
        // binding {invoice}.
        Route::get('/invoices/export', [InvoiceExportImportController::class, 'export'])->middleware('ability:invoices,export')->middleware('seller.forbidden');
        Route::get('/invoices/import/template', [InvoiceExportImportController::class, 'importTemplate'])->middleware('ability:invoices,import')->middleware('seller.forbidden');
        Route::post('/invoices/import', [InvoiceExportImportController::class, 'import'])->middleware('ability:invoices,import')->middleware('seller.forbidden');
        Route::post('/invoices/{invoice}/mark-paid', [InvoiceController::class, 'markPaid'])->middleware('ability:invoices,mark_paid')->middleware('seller.forbidden');
        Route::post('/invoices/{invoice}/cancel', [InvoiceController::class, 'cancel'])->middleware('ability:invoices,cancel')->middleware('seller.forbidden');
        Route::apiResource('invoices', InvoiceController::class)->only(['index', 'show'])->middleware('seller.forbidden');
        Route::apiResource('invoices', InvoiceController::class)->only(['store', 'update', 'destroy'])->middleware(['ability:invoices', 'seller.forbidden']);

        // 034-seller-po-bom — BOM bersumber baris purchase order. Rute statis
        // 'bom/eligible-lines' & 'bom/items' sengaja di atas rute lain yang
        // berparameter. POST /variants/{variant}/bom (bahan + jumlah) tetap
        // ada sebagai jalur LEGACY dan membuat baris legacy.
        Route::get('/variants/{variant}/bom/eligible-lines', [VariantBomController::class, 'eligibleLines']);
        Route::post('/variants/{variant}/bom/items', [VariantBomController::class, 'storeItems'])->middleware('ability:products,bom');
        Route::post('/variants/{variant}/bom/copy', [VariantBomController::class, 'copyFrom'])->middleware('ability:products,bom');
        Route::post('/variants/{variant}/bom/copy-out', [VariantBomController::class, 'copyOut'])->middleware('ability:products,bom');
        Route::post('/variants/{variant}/bom/complete', [VariantBomController::class, 'complete'])->middleware('ability:products,bom');
        Route::post('/variants/{variant}/bom/reopen', [VariantBomController::class, 'reopen'])->middleware('ability:products,bom');
        Route::get('/variants/{variant}/bom', [VariantBomController::class, 'index']);
        // 036 — tombol Simpan dialog BOM: simpan banyak jumlah sekaligus (semua-atau-tidak-sama-sekali).
        Route::put('/variants/{variant}/bom', [VariantBomController::class, 'updateQuantities'])->middleware('ability:products,bom');
        Route::post('/variants/{variant}/bom', [MaterialController::class, 'storeBomLine'])->middleware('ability:products,bom');
        Route::put('/bom/{bomLine}', [VariantBomController::class, 'update'])->middleware('ability:products,bom');
        Route::post('/bom/{bomLine}/replace-source', [VariantBomController::class, 'replaceSource'])->middleware('ability:products,bom');
        Route::delete('/bom/{bomLine}', [VariantBomController::class, 'destroy'])->middleware('ability:products,bom');
        Route::get('/variants/{variant}/cost-breakdown', [MaterialController::class, 'costBreakdown']);

        Route::apiResource('events', EventController::class)->only(['index', 'show']);
        Route::apiResource('events', EventController::class)->only(['store', 'update', 'destroy'])->middleware(['ability:events', 'seller.forbidden']);
        Route::patch('/events/{event}/status', [EventController::class, 'updateStatus'])->middleware('ability:events,status')->middleware('seller.forbidden');

        Route::get('/sessions', [CashierSessionController::class, 'index']);
        Route::get('/sessions/current', [CashierSessionController::class, 'current']);
        Route::post('/sessions', [CashierSessionController::class, 'store'])->middleware('ability:session,create');
        Route::post('/sessions/{session}/close', [CashierSessionController::class, 'close'])->middleware('ability:session,edit');
        Route::get('/sessions/{session}/summary', [CashierSessionController::class, 'summary']);
        Route::get('/sessions/{session}', [CashierSessionController::class, 'show'])->whereNumber('session');
        Route::get('/sessions/{session}/transactions', [CashierSessionController::class, 'transactions']);

        Route::get('/payment-channels', [PaymentChannelController::class, 'index']);
        Route::post('/payment-channels', [PaymentChannelController::class, 'store'])->middleware('ability:settings,edit')->middleware('seller.forbidden');
        Route::post('/payment-channels/{channel}', [PaymentChannelController::class, 'update'])->middleware('ability:settings,edit')->middleware('seller.forbidden');
        Route::delete('/payment-channels/{channel}', [PaymentChannelController::class, 'destroy'])->middleware('ability:settings,edit')->middleware('seller.forbidden');
        Route::post('/payment-proofs', [PaymentProofController::class, 'store']);
        Route::get('/payment-proofs/{proof}/file', [PaymentProofController::class, 'show'])->name('payment-proofs.file');

        Route::get('/orders', [OrderController::class, 'index']);
        Route::post('/orders', [OrderController::class, 'store'])->middleware('ability:pos,create');

        // 006-purchase-order-and-ops (US4) — draft transaksi kasir, tanpa
        // efek stok/pembayaran apa pun sampai draft di-checkout jadi order
        // sungguhan (lihat komentar PosDraftController/Service).
        Route::apiResource('pos-drafts', PosDraftController::class)->only(['index', 'show']);
        // 057 — menyimpan/menghapus draf = aksi `draft` menu POS.
        Route::post('/pos-drafts', [PosDraftController::class, 'store'])->name('pos-drafts.store')->middleware('ability:pos,draft');
        Route::delete('/pos-drafts/{pos_draft}', [PosDraftController::class, 'destroy'])->name('pos-drafts.destroy')->middleware('ability:pos,draft');
        Route::get('/orders/{order}', [OrderController::class, 'show']);
        Route::post('/orders/{order}/void', [OrderController::class, 'void'])->middleware('ability:sales,void');
        // 028-partial-split-payment — pembayaran susulan / koreksi untuk penjualan POS
        // yang dibayar sebagian (hapus = owner/admin, dijaga di controller).
        Route::post('/orders/{order}/payments', [OrderController::class, 'storePayment'])->middleware('ability:sales,payment');
        Route::delete('/orders/{order}/payments/{payment}', [OrderController::class, 'destroyPayment'])->middleware('ability:sales,payment');
        Route::patch('/orders/{order}/payments/{payment}/confirmation', [OrderController::class, 'updatePaymentConfirmation'])->middleware('ability:sales,payment');
        // 054 (US8) — referensi transaksi (catatan + gambar privat). 055 — banyak gambar, dilayani per id;
        // scopeBindings: {referenceImage} harus milik {order} (gambar penjualan lain → 404). Nama parameter
        // menentukan relasi yang dipakai Laravel (Order::referenceImages()), jadi jangan disingkat jadi {image}.
        Route::get('/orders/{order}/reference', [OrderController::class, 'showReference']);
        Route::get('/orders/{order}/reference/images/{referenceImage}', [OrderController::class, 'referenceImage'])->scopeBindings();
        Route::put('/orders/{order}/reference', [OrderController::class, 'saveReference'])->middleware('ability:sales,reference');
        Route::delete('/orders/{order}/reference', [OrderController::class, 'deleteReference'])->middleware('ability:sales,reference');
        Route::patch('/orders/{order}/payments/{payment}/method', [OrderController::class, 'changePaymentMethod'])->middleware('ability:sales,payment_method');
        Route::post('/orders/{order}/payments/{payment}/verify', [OrderController::class, 'verifyPayment'])->middleware('ability:sales,verify');
        Route::post('/orders/verify-payments', [OrderController::class, 'verifyPayments'])->middleware('ability:sales,verify');
        // 054 (US9) — statis, sebelum '/orders/{order}'.
        Route::post('/orders/bulk-receipts', [OrderController::class, 'bulkReceipts'])->middleware('ability:sales,receipts');
        Route::get('/orders/{order}/receipt', [OrderController::class, 'receipt']);

        // 007-preorder-import-export-notify — rute statis ('export',
        // 'import/template', 'import') WAJIB didaftarkan SEBELUM
        // apiResource('preorders', ...)'s 'show' (GET /preorders/{preorder}),
        // supaya 'export'/'import' tidak tertangkap sebagai {preorder} id
        // dan gagal route-model-binding (404).
        Route::get('/preorders/export', [PreorderController::class, 'export'])->middleware('ability:preorders,export');
        Route::get('/preorders/import/template', [PreorderController::class, 'importTemplate'])->middleware('ability:preorders,import')->middleware('seller.forbidden');
        Route::post('/preorders/import', [PreorderController::class, 'import'])->middleware('ability:preorders,import')->middleware('seller.forbidden');

        // 013-preorder-list-filters-receipt (T023) — rute statis 'summary'
        // WAJIB didaftarkan SEBELUM apiResource('preorders', ...)'s 'show'
        // (GET /preorders/{preorder}), dengan alasan sama seperti
        // 'export'/'import' di atas: supaya "summary" tidak tertangkap
        // sebagai {preorder} id dan gagal route-model-binding.
        Route::get('/preorders/summary', [PreorderController::class, 'summary']);

        // 022-preorder-invoice-crud-overhaul (US5) — rute statis 'bulk-*'
        // WAJIB didaftarkan SEBELUM apiResource('preorders', ...)'s 'show',
        // alasan sama seperti 'export'/'import'/'summary' di atas.
        Route::post('/preorders/bulk-invoices', [PreorderController::class, 'bulkInvoices'])->middleware('ability:preorders,export');
        Route::post('/preorders/bulk-email', [PreorderController::class, 'bulkEmailInvoices'])->middleware('ability:preorders,notify');

        // 027-preorder-duplicate-split — rute statis, WAJIB sebelum
        // apiResource('preorders', ...) (alasan sama dengan 'bulk-*' di atas).
        Route::post('/preorders/duplicate', [PreorderController::class, 'duplicate'])->middleware('ability:preorders,duplicate');

        Route::apiResource('preorders', PreorderController::class)->only(['index', 'show']);
        Route::apiResource('preorders', PreorderController::class)->only(['store', 'update', 'destroy'])->middleware('ability:preorders');
        Route::patch('/preorders/{preorder}/status', [PreorderController::class, 'updateStatus'])->middleware('ability:preorders,status');
        Route::patch('/preorders/{preorder}/dispatch-status', [PreorderController::class, 'updateDispatchStatus'])->middleware('ability:preorders,dispatch');
        Route::post('/preorders/{preorder}/payments', [PreorderController::class, 'storePayment'])->middleware('ability:preorders,payment');
        Route::delete('/preorders/{preorder}/payments/{payment}', [PreorderController::class, 'destroyPayment'])->middleware('ability:preorders,payment');
        Route::patch('/preorders/{preorder}/payments/{payment}/confirmation', [PreorderController::class, 'updatePaymentConfirmation'])->middleware('ability:preorders,payment');
        Route::post('/preorders/{preorder}/payments/{payment}/verify', [PreorderController::class, 'verifyPayment'])->middleware('ability:preorders,verify');
        Route::post('/preorders/{preorder}/split', [PreorderController::class, 'split'])->middleware('ability:preorders,split');
        Route::post('/preorders/{preorder}/shipment', [ShipmentController::class, 'store'])->middleware('ability:preorders,edit');
        Route::get('/preorders/{preorder}/invoice', [PreorderController::class, 'invoice']);
        // 052-preorder-detail-prev-next — tetangga di daftar (filter/urutan sama dengan index()).
        Route::get('/preorders/{preorder}/neighbors', [PreorderController::class, 'neighbors']);
        Route::post('/preorders/{preorder}/notifications/resend', [PreorderController::class, 'resendNotification'])->middleware('ability:preorders,notify');
        Route::patch('/shipments/{shipment}', [ShipmentController::class, 'update'])->middleware('ability:preorders,edit');

        // Ekspor/impor master data (PRD 7.15). Dikelompokkan di bawah
        // /exports dan /imports — bukan /artists/export dsb — supaya tidak
        // bertabrakan dengan apiResource /artists/{artist} dan supaya
        // seluruh permukaan berkas berpasangan simetris di satu tempat.
        Route::get('/exports/{entity}', [MasterDataExportController::class, 'show'])
            ->where('entity', 'artists|categories|products|stock|vendors|materials|vendor_prices|bom|roles|users')->middleware('seller.forbidden');
        Route::get('/imports/master-data/template', [MasterDataImportController::class, 'template'])->middleware('seller.forbidden');
        Route::post('/imports/master-data', [MasterDataImportController::class, 'store'])->middleware('seller.forbidden');

        Route::get('/reports/sales', [ReportController::class, 'sales']);
        Route::post('/reports/sales/transactions/export', [ReportController::class, 'exportSalesTransactions'])->middleware('ability:sales,export');
        // 054 — laporan penjualan per produk / per metode bayar; statis, WAJIB sebelum '/reports/{report}/export'.
        Route::get('/reports/sales-by-product', [ReportController::class, 'salesByProduct']);
        Route::get('/reports/sales-by-product/export', [ReportController::class, 'exportSalesByProduct'])->middleware('ability:reports,export');
        Route::get('/reports/sales-by-product/{product}/transactions', [ReportController::class, 'salesByProductTransactions'])->whereNumber('product');
        Route::get('/reports/sales-by-payment-method', [ReportController::class, 'salesByPaymentMethod'])->middleware('seller.forbidden');
        Route::get('/reports/sales-by-payment-method/transactions', [ReportController::class, 'salesByPaymentMethodTransactions'])->middleware('seller.forbidden');
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
        Route::get('/reports/{report}/export', [ReportController::class, 'export'])->middleware('ability:reports,export')
            ->where('report', 'sales|profit|artist-settlements|artist-profit|purchases|stock-by-artist|preorder');

        // 076-settlement-tab-session-branding — settlement seller (menu 'settlements',
        // Owner/Admin). Dipisah dari /reports/artist-settlements (Rekap Seller POS-only,
        // fitur 040) dan tabel artist_settlements. '/preview' harus didaftarkan SEBELUM
        // '/{settlement}' supaya tidak dibaca sebagai id.
        Route::get('/settlements/preview', [SellerSettlementController::class, 'preview']);
        Route::get('/settlements', [SellerSettlementController::class, 'index']);
        Route::post('/settlements', [SellerSettlementController::class, 'store'])->middleware('ability:settlements,create');
        Route::get('/settlements/{settlement}', [SellerSettlementController::class, 'show']);
        Route::delete('/settlements/{settlement}', [SellerSettlementController::class, 'destroy'])->middleware('ability:settlements,delete');
        Route::post('/settlements/{settlement}/refresh', [SellerSettlementController::class, 'refresh'])->middleware('ability:settlements,refresh');
        Route::post('/settlements/{settlement}/finalize', [SellerSettlementController::class, 'finalize'])->middleware('ability:settlements,finalize');
        Route::post('/settlements/{settlement}/reopen', [SellerSettlementController::class, 'reopen'])->middleware('ability:settlements,reopen');
        Route::post('/settlements/{settlement}/adjustments', [SellerSettlementController::class, 'storeAdjustment'])->middleware('ability:settlements,adjust');
        Route::put('/settlements/{settlement}/adjustments/{adjustment}', [SellerSettlementController::class, 'updateAdjustment'])->middleware('ability:settlements,adjust');
        Route::delete('/settlements/{settlement}/adjustments/{adjustment}', [SellerSettlementController::class, 'destroyAdjustment'])->middleware('ability:settlements,adjust');
        // US3: pengakuan seller, pembayaran, berkas privat, dokumen cetak.
        Route::post('/settlements/{settlement}/acknowledgement', [SellerSettlementController::class, 'acknowledge'])->middleware('ability:settlements,acknowledge');
        Route::get('/settlements/{settlement}/acknowledgements/{acknowledgement}/signature', [SellerSettlementController::class, 'signature']);
        Route::post('/settlements/{settlement}/payments', [SellerSettlementController::class, 'storePayment'])->middleware('ability:settlements,payout');
        Route::delete('/settlements/{settlement}/payments/{payment}', [SellerSettlementController::class, 'destroyPayment'])->middleware('ability:settlements,payout');
        Route::get('/settlements/{settlement}/payments/{payment}/proof', [SellerSettlementController::class, 'paymentProof']);
        Route::get('/settlements/{settlement}/payments/{payment}/document', [SellerSettlementController::class, 'paymentDocument']);
        Route::get('/settlements/{settlement}/document', [SellerSettlementController::class, 'document']);
    });
});
