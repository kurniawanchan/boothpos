<?php

use App\Http\Controllers\Api\ActivityLogController;
use App\Http\Controllers\Api\ArtistController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CashierSessionController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\EventController;
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
        Route::apiResource('customers', CustomerController::class)->only(['index', 'store', 'update']);

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
        Route::post('/materials/{material}/vendor-prices', [MaterialController::class, 'storeVendorPrice']);
        Route::put('/vendor-prices/{vendorPrice}', [MaterialController::class, 'updateVendorPrice']);
        Route::delete('/vendor-prices/{vendorPrice}', [MaterialController::class, 'destroyVendorPrice']);

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
        Route::get('/orders/{order}', [OrderController::class, 'show']);
        Route::post('/orders/{order}/void', [OrderController::class, 'void']);
        Route::get('/orders/{order}/receipt', [OrderController::class, 'receipt']);

        Route::apiResource('preorders', PreorderController::class)->only(['index', 'store', 'show']);
        Route::patch('/preorders/{preorder}/status', [PreorderController::class, 'updateStatus']);
        Route::post('/preorders/{preorder}/payments', [PreorderController::class, 'storePayment']);
        Route::post('/preorders/{preorder}/shipment', [ShipmentController::class, 'store']);
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
        Route::get('/reports/artist-profit', [ReportController::class, 'artistProfit']);
        Route::get('/reports/artist-settlements', [ReportController::class, 'artistSettlements']);
        Route::get('/reports/artist-settlements/{artist}/transactions', [ReportController::class, 'artistSettlementTransactions']);
        Route::post('/reports/artist-settlements/{settlement}/payment', [ReportController::class, 'recordSettlementPayment']);
        Route::get('/reports/{report}/export', [ReportController::class, 'export'])
            ->where('report', 'sales|profit|artist-settlements|artist-profit');
    });
});
