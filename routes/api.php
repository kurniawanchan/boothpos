<?php

use App\Http\Controllers\Api\ArtistController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CashierSessionController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PaymentChannelController;
use App\Http\Controllers\Api\PaymentProofController;
use App\Http\Controllers\Api\PreorderController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\ShipmentController;
use App\Http\Controllers\Api\StockController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:5,1');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/auth/me', [AuthController::class, 'me']);

        Route::get('/settings/features', [SettingsController::class, 'features']);

        Route::apiResource('artists', ArtistController::class);
        Route::apiResource('categories', CategoryController::class);
        Route::apiResource('customers', CustomerController::class)->only(['index', 'store', 'update']);

        Route::apiResource('products', ProductController::class);
        Route::post('/products/{product}/variants', [ProductController::class, 'storeVariant']);
        Route::put('/variants/{variant}', [ProductController::class, 'updateVariant']);
        Route::get('/variants/lookup', [ProductController::class, 'lookupVariants']);

        Route::get('/stock/movements', [StockController::class, 'movements']);
        Route::post('/stock/adjustments', [StockController::class, 'adjust']);
        Route::get('/stock/low', [StockController::class, 'lowStock']);

        Route::apiResource('events', EventController::class);
        Route::patch('/events/{event}/status', [EventController::class, 'updateStatus']);

        Route::get('/sessions/current', [CashierSessionController::class, 'current']);
        Route::post('/sessions', [CashierSessionController::class, 'store']);
        Route::post('/sessions/{session}/close', [CashierSessionController::class, 'close']);
        Route::get('/sessions/{session}/summary', [CashierSessionController::class, 'summary']);

        Route::get('/payment-channels', [PaymentChannelController::class, 'index']);
        Route::post('/payment-channels', [PaymentChannelController::class, 'store']);
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

        Route::get('/reports/sales', [ReportController::class, 'sales']);
        Route::get('/reports/profit', [ReportController::class, 'profit']);
        Route::get('/reports/artist-settlements', [ReportController::class, 'artistSettlements']);
        Route::post('/reports/artist-settlements/{settlement}/payment', [ReportController::class, 'recordSettlementPayment']);
        Route::get('/reports/{report}/export', [ReportController::class, 'export'])
            ->where('report', 'sales|profit|artist-settlements');
    });
});
