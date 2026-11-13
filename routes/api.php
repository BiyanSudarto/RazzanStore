<?php

use App\Http\Controllers\CatalogController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\MidtransWebhookController;
use App\Http\Controllers\OrderController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/health', static fn () => response()->json([
        'status' => 'ok',
        'service' => 'razzanstore-api',
        'version' => 'v1',
    ]))->name('api.v1.health');

    Route::prefix('catalog')->name('api.v1.catalog.')->group(function (): void {
        Route::get('/games', [CatalogController::class, 'games'])->name('games.index');
        Route::get('/games/{slug}', [CatalogController::class, 'game'])->name('games.show');
    });

    Route::post('/checkout', [CheckoutController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('api.v1.checkout');

    Route::get('/orders/{trackingToken}', [OrderController::class, 'show'])
        ->where('trackingToken', '[A-Za-z0-9_-]{40,50}')
        ->middleware('throttle:60,1')
        ->name('api.v1.orders.show');

    Route::post('/orders/{trackingToken}/payment-attempts', [OrderController::class, 'retry'])
        ->where('trackingToken', '[A-Za-z0-9_-]{40,50}')
        ->middleware('throttle:10,1')
        ->name('api.v1.orders.payment-attempts.store');

    Route::post('/webhooks/midtrans', MidtransWebhookController::class)
        ->middleware('throttle:120,1')
        ->name('api.v1.webhooks.midtrans');
});
