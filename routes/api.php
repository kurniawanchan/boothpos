<?php

use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ActivityLogController;
use App\Http\Controllers\Api\OutletController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BackupController;
use App\Http\Controllers\Api\BusinessTypeController;
use App\Http\Controllers\Api\CashierSessionController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\CompanyController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\DiningTableController;
use App\Http\Controllers\Api\FloorController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\InvoiceExportImportController;
use App\Http\Controllers\Api\InvoicePaymentSettingController;
use App\Http\Controllers\Api\LicenseCatalogController;
use App\Http\Controllers\Api\LicenseController;
use App\Http\Controllers\Api\MasterDataExportController;
use App\Http\Controllers\Api\MasterDataImportController;
use App\Http\Controllers\Api\KitchenController;
use App\Http\Controllers\Api\MaterialController;
use App\Http\Controllers\Api\ModifierGroupController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PaymentChannelController;
use App\Http\Controllers\Api\PaymentProofController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\StationController;
use App\Http\Controllers\Api\StockController;
use App\Http\Controllers\Api\TabController;
use App\Http\Controllers\Api\TabLineController;
use App\Http\Controllers\Api\TabPaymentController;
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

    // 065 / US6 — alamat server pilihan admin untuk tablet baru: tanpa token (tablet belum punya), hanya alamat terpilih.
    Route::get('/server-address', [\App\Http\Controllers\Api\ServerAddressController::class, 'forTablets'])->middleware('throttle:30,1');

    // 073 — penyiapan tablet TANPA login owner/admin (spec Q1: pendaftaran terbuka). Pengecualian Konstitusi IV: belum
    // ada pengguna, jadi outlet dari path (divalidasi aktif) dan hanya nama yang keluar. Pembatasnya throttle.
    Route::get('/pos-setup/outlets', [\App\Http\Controllers\Api\PosSetupController::class, 'outlets'])->middleware('throttle:30,1');
    Route::get('/pos-setup/outlets/{outlet}/terminals', [\App\Http\Controllers\Api\PosSetupController::class, 'terminals'])->whereNumber('outlet')->middleware('throttle:30,1');
    Route::post('/pos-setup/outlets/{outlet}/terminals', [\App\Http\Controllers\Api\PosSetupController::class, 'registerTerminal'])->whereNumber('outlet')->middleware('throttle:10,1');

    // 073 — roster dan login PIN TANPA token (spec Q1/Q3). Sebelumnya butuh token owner/admin dari penyiapan. Pertahanan utama
    // tetap kunci 5-salah per pengguna di PinAuthService; throttle hanya meredam banjir. Roster tidak memuat owner/admin.
    Route::get('/staff/roster', [\App\Http\Controllers\Api\PinAuthController::class, 'roster'])->middleware('throttle:30,1');
    Route::post('/auth/pin-sessions', [\App\Http\Controllers\Api\PinAuthController::class, 'login'])->middleware('throttle:30,1');

    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:5,1');

    // SetLocaleFromUser HANYA di sini, bukan pada POST /auth/login di
    // atas — layar login selalu Bahasa Indonesia (FR-001), locale
    // per-akun baru berlaku setelah $request->user() resolve.
    Route::middleware(['auth:sanctum', \App\Http\Middleware\EnsureUserIsActive::class, SetLocaleFromUser::class, \App\Http\Middleware\ResolveOutletContext::class])->group(function () {
        // 045 Stage 2 — outlet yang boleh dilihat pengguna dan pilihan X-Outlet-Id (contracts/api-scope.md).
        Route::get('/me/scope', [\App\Http\Controllers\Api\MeScopeController::class, 'show']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::put('/auth/language', [AuthController::class, 'updateLanguage']);
        // 005-ux-enhancements-dashboard (US3) — swa-layanan, sengaja
        // terpisah dari /users/{user} (lihat komentar
        // AuthController::updatePassword/updatePhoto).
        Route::put('/auth/password', [AuthController::class, 'updatePassword']);
        Route::post('/auth/photo', [AuthController::class, 'updatePhoto']);

        // 048 / api-gap B1 — ganti PIN, perpanjang token, reset PIN. (073: roster dan login PIN pindah ke luar grup ini.)
        Route::post('/auth/pin-changes', [\App\Http\Controllers\Api\PinAuthController::class, 'change'])->middleware('throttle:10,1');
        Route::post('/auth/token-refresh', [\App\Http\Controllers\Api\PinAuthController::class, 'refresh']);
        Route::post('/users/{user}/pin-reset', [\App\Http\Controllers\Api\PinAuthController::class, 'reset']);
        // 074 — owner/admin mengatur PIN pengguna dari layar Pengguna (ketik atau "Generate"); PIN tidak pernah ada di respons.
        Route::put('/users/{user}/pin', [\App\Http\Controllers\Api\PinAuthController::class, 'setUserPin'])->middleware('throttle:30,1');
        Route::post('/pin-suggestions', [\App\Http\Controllers\Api\PinAuthController::class, 'suggest'])->middleware('throttle:30,1');

        // 048 / B14 — terminal POS (owner/admin; outlet diturunkan server).
        Route::get('/devices', [\App\Http\Controllers\Api\DeviceController::class, 'index']);
        Route::post('/devices/pairings', [\App\Http\Controllers\Api\DeviceController::class, 'pair']);
        Route::patch('/devices/{device}', [\App\Http\Controllers\Api\DeviceController::class, 'rename']);
        Route::delete('/devices/{device}', [\App\Http\Controllers\Api\DeviceController::class, 'destroy']);
        // 048 / T064 — heartbeat terminal, sengaja di luar pembatasan owner/admin (dikirim sesi kasir juga).
        Route::post('/devices/heartbeat', [\App\Http\Controllers\Api\DeviceController::class, 'heartbeat']);
        // 054 / US3 — alamat server yang dipilih; di dalam auth:sanctum seperti roster (tablet memakai token dari penyiapan).
        Route::get('/devices/server-address', [\App\Http\Controllers\Api\DeviceController::class, 'serverAddress']);

        Route::get('/settings/features', [SettingsController::class, 'features']);
        Route::get('/settings', [SettingsController::class, 'index']);
        Route::put('/settings', [SettingsController::class, 'update']);
        Route::post('/settings/store-logo', [SettingsController::class, 'uploadStoreLogo']);
        Route::get('/settings/payment', [InvoicePaymentSettingController::class, 'show']);
        Route::put('/settings/payment', [InvoicePaymentSettingController::class, 'update']);
        // 054 / US3 — alamat server lokal/online (owner/admin; SettingPolicy di controller dan request).
        Route::get('/settings/server-address', [\App\Http\Controllers\Api\ServerAddressController::class, 'show']);
        Route::put('/settings/server-address', [\App\Http\Controllers\Api\ServerAddressController::class, 'update']);

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
        // 045 Stage 2 — penugasan outlet pengguna (Owner/Admin tidak ditugaskan; lihat UserController::outlets).
        Route::put('/users/{user}/outlets', [UserController::class, 'outlets']);
        Route::post('/users/{user}/photo', [UserController::class, 'uploadPhoto']);

        // Peran (Role) dan registry menu — 001-user-store-settings User
        // Story 2. /menu-keys adalah App\Support\MenuKeys registry, bukan
        // bagian dari resource /roles — dipisah supaya frontend bisa
        // memuat daftar menu (untuk checkbox RoleMenuPicker) tanpa harus
        // punya akses baca ke satu peran pun.
        Route::get('/menu-keys', [RoleController::class, 'menuKeys']);
        Route::get('/permission-catalog', [RoleController::class, 'permissionCatalog']);

        // 043-products-page-redesign — daftar alasan (86, diskontinu, penolakan). Daftar terbuka untuk
        // pengguna login; menulis hanya menu `settings` (dicek di FormRequest).
        Route::get('/reason-codes', [\App\Http\Controllers\Api\ReasonCodeController::class, 'index']);
        Route::post('/reason-codes', [\App\Http\Controllers\Api\ReasonCodeController::class, 'store']);
        Route::put('/reason-codes/{reasonCode}', [\App\Http\Controllers\Api\ReasonCodeController::class, 'update']);
        Route::apiResource('roles', RoleController::class);

        Route::apiResource('outlets', OutletController::class);
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

        // 043 — harus SEBELUM apiResource agar 'summary' tidak ditangkap sebagai {product}.
        Route::get('/products/summary', [ProductController::class, 'summary']);
        // 043 — 86 (habis), diskontinu/aktifkan lagi, dan poll ketersediaan untuk terminal.
        Route::get('/products/{product}/recipes', [\App\Http\Controllers\Api\RecipeController::class, 'index']);
        Route::post('/products/{product}/recipes', [\App\Http\Controllers\Api\RecipeController::class, 'store']);
        Route::post('/products/{product}/recipes/recalculate', [\App\Http\Controllers\Api\RecipeController::class, 'recalculate']);
        Route::get('/recipes/{recipe}', [\App\Http\Controllers\Api\RecipeController::class, 'show']);
        Route::put('/products/{product}/modifier-links', [\App\Http\Controllers\Api\ModifierLinkController::class, 'update']);
        Route::put('/products/{product}/variants', [\App\Http\Controllers\Api\VariantBatchController::class, 'update']);
        Route::get('/products/{product}/price-history', [ProductController::class, 'priceHistory']);
        Route::post('/products/{product}/sold-out', [\App\Http\Controllers\Api\ProductAvailabilityController::class, 'store']);
        Route::delete('/products/{product}/sold-out', [\App\Http\Controllers\Api\ProductAvailabilityController::class, 'destroy']);
        Route::post('/products/{product}/discontinue', [\App\Http\Controllers\Api\ProductLifecycleController::class, 'discontinue']);
        Route::post('/products/{product}/reactivate', [\App\Http\Controllers\Api\ProductLifecycleController::class, 'reactivate']);
        Route::post('/price-changes/preview', [\App\Http\Controllers\Api\PriceChangeController::class, 'preview']);
        Route::post('/price-changes', [\App\Http\Controllers\Api\PriceChangeController::class, 'store']);
        Route::get('/menu/changes', [\App\Http\Controllers\Api\MenuChangeController::class, 'index']);
        Route::post('/menu/changes/{change}/cancel', [\App\Http\Controllers\Api\MenuChangeController::class, 'cancel']);
        Route::get('/menu/releases', [\App\Http\Controllers\Api\MenuReleaseController::class, 'index']);
        Route::get('/menu/releases/{release}', [\App\Http\Controllers\Api\MenuReleaseController::class, 'show']);
        Route::post('/menu/releases', [\App\Http\Controllers\Api\MenuReleaseController::class, 'store']);
        Route::post('/menu/releases/{release}/cancel', [\App\Http\Controllers\Api\MenuReleaseController::class, 'cancel']);
        Route::get('/approvals', [\App\Http\Controllers\Api\ApprovalController::class, 'index']);
        Route::post('/approvals/{approval}/approve', [\App\Http\Controllers\Api\ApprovalController::class, 'approve']);
        Route::post('/approvals/{approval}/reject', [\App\Http\Controllers\Api\ApprovalController::class, 'reject']);
        Route::get('/menu/availability', [\App\Http\Controllers\Api\MenuAvailabilityController::class, 'show']);
        Route::apiResource('products', ProductController::class);
        // 055 / T004 — kategori kasir menurut mode sistem; gerbang sama dengan GET /products?pos=1.
        Route::get('/pos/categories', [\App\Http\Controllers\Api\PosCategoryController::class, 'index']);
        Route::post('/products/{product}/image', [ProductController::class, 'uploadImage']);
        Route::post('/products/{product}/variants', [ProductController::class, 'storeVariant']);
        Route::put('/variants/{variant}', [ProductController::class, 'updateVariant']);
        // 045 Stage 3 — harga varian per outlet (lewat PriceService).
        Route::get('/variants/{variant}/outlet-prices', [\App\Http\Controllers\Api\VariantOutletPriceController::class, 'index']);
        Route::put('/variants/{variant}/outlet-prices/{outlet}', [\App\Http\Controllers\Api\VariantOutletPriceController::class, 'update']);
        Route::post('/variants/{variant}/image', [ProductController::class, 'uploadVariantImage']);
        Route::get('/variants/lookup', [ProductController::class, 'lookupVariants']);

        Route::get('/stock/movements', [StockController::class, 'movements']);
        Route::post('/stock/adjustments', [StockController::class, 'adjust']);
        Route::get('/stock/low', [StockController::class, 'lowStock']);
        Route::get('/stock/balances', [StockController::class, 'balances']);
        // 045 Stage 6 — transfer stok antar outlet. Rute tetap (discrepancies/in-transit) didaftarkan SEBELUM {id}.
        Route::get('/stock/transfers/discrepancies', [\App\Http\Controllers\Api\StockTransferController::class, 'discrepancies']);
        Route::get('/stock/in-transit', [\App\Http\Controllers\Api\StockTransferController::class, 'inTransit']);
        Route::get('/stock/transfers', [\App\Http\Controllers\Api\StockTransferController::class, 'index']);
        Route::post('/stock/transfers', [\App\Http\Controllers\Api\StockTransferController::class, 'store']);
        Route::get('/stock/transfers/{id}', [\App\Http\Controllers\Api\StockTransferController::class, 'show'])->whereNumber('id');
        Route::post('/stock/transfers/{id}/receive', [\App\Http\Controllers\Api\StockTransferController::class, 'receive'])->whereNumber('id');
        Route::post('/stock/transfers/{id}/cancel', [\App\Http\Controllers\Api\StockTransferController::class, 'cancel'])->whereNumber('id');

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
        Route::post('/materials/{material}/adjustments', [MaterialController::class, 'adjustStock']);
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


        // 065 — daftar shift (owner/admin: semua; lainnya: milik sendiri). Sebelum rute `{session}`.
        Route::get('/sessions', [CashierSessionController::class, 'index']);
        Route::get('/sessions/current', [CashierSessionController::class, 'current']);
        Route::post('/sessions', [CashierSessionController::class, 'store']);
        // 048 — `idempotent`: pengiriman ulang setelah respons hilang mengembalikan hasil tutup yang tersimpan, bukan 409.
        Route::post('/sessions/{session}/close', [CashierSessionController::class, 'close'])->middleware('idempotent');
        Route::get('/sessions/{session}/summary', [CashierSessionController::class, 'summary']);
        Route::get('/sessions/{session}/x-report', [CashierSessionController::class, 'xReport']);
        // 065 — daftar kas masuk/keluar lintas shift (aturan lihat sama dengan daftar shift).
        Route::get('/cash-movements', [\App\Http\Controllers\Api\CashMovementController::class, 'list']);
        Route::get('/sessions/{session}/cash-movements', [\App\Http\Controllers\Api\CashMovementController::class, 'index']);
        Route::post('/sessions/{session}/cash-movements', [\App\Http\Controllers\Api\CashMovementController::class, 'store'])->middleware('idempotent');

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
        // 048 / S4 — cetak ulang struk dan refund penuh (token persetujuan; lihat OverrideGate).
        Route::post('/orders/{order}/reprints', [\App\Http\Controllers\Api\ReprintController::class, 'store']);
        Route::post('/orders/{order}/refund', [\App\Http\Controllers\Api\RefundController::class, 'store']);

        // 028-restaurant-dine-in-ordering — meja dan lantai. Setiap rute
        // memeriksa menu-key-nya sendiri di sisi server (`dining_setup` /
        // `floor`); layar hanya mencerminkan keputusan itu.
        Route::apiResource('dining-tables', DiningTableController::class);
        Route::get('/floor', [FloorController::class, 'index']);

        // Stasiun persiapan + dapur/bar. Daftar stasiun terbuka juga untuk `kitchen` (pengalih layar).
        Route::apiResource('stations', StationController::class);
        Route::get('/stations/{station}/tickets', [KitchenController::class, 'feed']);
        Route::get('/tickets/{ticket}', [KitchenController::class, 'show']);
        Route::patch('/ticket-items/{ticketItem}/status', [KitchenController::class, 'updateStatus']);

        // Grup modifier — lampiran ke produk/kategori memakai endpoint terpisah (penggantian penuh).
        Route::apiResource('modifier-groups', ModifierGroupController::class)->parameters(['modifier-groups' => 'modifierGroup']);
        Route::put('/modifier-groups/{modifierGroup}/attachments', [ModifierGroupController::class, 'attachments']);

        // Tab (pesanan terbuka). `{order}` memakai route-model binding Order —
        // penjualan ritel diperlakukan 404 oleh TabService/TabController.
        Route::get('/tabs', [TabController::class, 'index']);
        Route::post('/tabs', [TabController::class, 'store']);
        Route::get('/tabs/{order}', [TabController::class, 'show']);
        Route::delete('/tabs/{order}', [TabController::class, 'destroy']);
        Route::post('/tabs/{order}/handover', [TabController::class, 'handover']);
        Route::post('/tabs/{order}/send', [TabController::class, 'send'])->middleware('idempotent');
        Route::post('/tabs/{order}/prebill', [TabController::class, 'prebill']);
        Route::post('/tabs/{order}/void', [TabController::class, 'void']);
        // 048 / S3 — batal seluruh tab (409 has_payments bila sudah dibayar), persetujuan di titik aksi, dan
        // verifikasi/penandaan QRIS tab.
        Route::post('/tabs/{order}/cancel', [TabController::class, 'cancel']);
        Route::post('/tabs/{order}/payments/{payment}/verify', [TabPaymentController::class, 'verify']);
        Route::post('/tabs/{order}/payments/{payment}/fail', [TabPaymentController::class, 'fail']);
        Route::post('/overrides', [\App\Http\Controllers\Api\OverrideController::class, 'store']);
        Route::get('/overrides/approvers', [\App\Http\Controllers\Api\OverrideController::class, 'approvers']);
        Route::post('/tabs/{order}/move', [TabController::class, 'move']);
        Route::post('/tabs/{order}/merge', [TabController::class, 'merge']);
        Route::post('/tabs/{order}/split', [TabPaymentController::class, 'split']);
        // 048 / S4 — diskon order (DELETE = hapus diskon order) dan baris.
        Route::post('/tabs/{order}/discounts', [\App\Http\Controllers\Api\TabDiscountController::class, 'store']);
        Route::delete('/tabs/{order}/discounts', [\App\Http\Controllers\Api\TabDiscountController::class, 'destroy']);
        Route::post('/tabs/{order}/items', [TabLineController::class, 'store'])->middleware('idempotent');
        Route::patch('/tabs/{order}/items/{item}', [TabLineController::class, 'update'])->whereNumber('item')->middleware('idempotent');
        Route::delete('/tabs/{order}/items/{item}', [TabLineController::class, 'destroy'])->whereNumber('item');
        Route::post('/tabs/{order}/payments', [TabPaymentController::class, 'store']);

        // Ekspor/impor master data (PRD 7.15). Dikelompokkan di bawah
        // /exports dan /imports — bukan /artists/export dsb — supaya tidak
        // bertabrakan dengan apiResource /artists/{artist} dan supaya
        // seluruh permukaan berkas berpasangan simetris di satu tempat.
        Route::get('/exports/{entity}', [MasterDataExportController::class, 'show'])
            ->where('entity', 'outlets|categories|products|stock|vendors|materials|vendor_prices|bom|outlet_items|outlet_prices|roles|users');
        Route::get('/imports/master-data/template', [MasterDataImportController::class, 'template']);
        Route::post('/imports/master-data', [MasterDataImportController::class, 'store']);

        // 042-dashboard-revamp — Dashboard (izin menu `dashboard` dicek di controller).
        Route::get('/dashboard/summary', [DashboardController::class, 'summary']);
        Route::get('/dashboard/attention', [DashboardController::class, 'attention']);
        // 065 — shift terbuka semua pengguna (panel dashboard).
        Route::get('/dashboard/shifts', [DashboardController::class, 'shifts']);
        Route::get('/dashboard/orders', [DashboardController::class, 'orders']);
        Route::get('/dashboard/orders/export', [DashboardController::class, 'ordersExport']);
        Route::get('/dashboard/export', [DashboardController::class, 'export']);
        Route::get('/reports/sales', [ReportController::class, 'sales']);
        Route::post('/reports/sales/transactions/export', [ReportController::class, 'exportSalesTransactions']);
        Route::get('/reports/profit', [ReportController::class, 'profit']);
        Route::get('/reports/purchases', [ReportController::class, 'purchases']);
        Route::get('/reports/stock-by-outlet', [ReportController::class, 'stockByOutlet']);
        Route::get('/reports/outlet-profit', [ReportController::class, 'outletProfit']);
        Route::get('/reports/outlet-settlements', [ReportController::class, 'outletSettlements']);
        Route::get('/reports/outlet-settlements/{outlet}/transactions', [ReportController::class, 'outletSettlementTransactions']);
        Route::get('/reports/{report}/export', [ReportController::class, 'export'])
            ->where('report', 'sales|profit|outlet-settlements|outlet-profit|purchases|stock-by-outlet');
    });
});
