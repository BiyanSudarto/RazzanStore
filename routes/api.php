<?php

use App\Http\Controllers\CatalogController;
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
});
