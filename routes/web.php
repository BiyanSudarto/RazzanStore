<?php

use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\GameController;
use App\Http\Controllers\Admin\GameFieldController;
use App\Http\Controllers\Admin\ProductController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::get('/admin/login', function () {
    if (Auth::guard('admin')->check()) {
        return redirect()->route('admin.dashboard');
    }

    return app(AuthController::class)->create();
})->name('admin.login');

Route::post('/admin/login', [AuthController::class, 'store'])
    ->middleware('throttle:5,1')
    ->name('admin.login.store');

Route::middleware('admin.role:super_admin,admin')->group(function (): void {
    Route::get('/admin', DashboardController::class)->name('admin.dashboard');
    Route::get('/admin/catalog', CatalogPageController::class)->name('admin.catalog');
    Route::post('/admin/logout', [AuthController::class, 'destroy'])->name('admin.logout');

    Route::prefix('admin/api')->name('admin.api.')->group(function (): void {
        Route::apiResource('games', GameController::class);
        Route::post('games/{game}/fields', [GameFieldController::class, 'store'])->name('games.fields.store');
        Route::patch('games/{game}/fields/{field}', [GameFieldController::class, 'update'])->name('games.fields.update');
        Route::delete('games/{game}/fields/{field}', [GameFieldController::class, 'destroy'])->name('games.fields.destroy');

        Route::get('products', [ProductController::class, 'index'])->name('products.index');
        Route::get('products/{product}', [ProductController::class, 'show'])->name('products.show');
        Route::patch('products/{product}', [ProductController::class, 'update'])->name('products.update');
        Route::post('products/{product}/deactivate', [ProductController::class, 'deactivate'])->name('products.deactivate');
    });
});

Route::middleware('admin.role:super_admin')->prefix('admin/api')->name('admin.api.')->group(function (): void {
    Route::post('products', [ProductController::class, 'store'])->name('products.store');
    Route::patch('products/{product}/price', [ProductController::class, 'changePrice'])->name('products.price');
});
