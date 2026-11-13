<?php

use Illuminate\\Support\\Facades\\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/health', static fn () => response()->json([
        'status' => 'ok',
        'service' => 'razzanstore-api',
        'version' => 'v1',
    ]))->name('api.v1.health');
});
