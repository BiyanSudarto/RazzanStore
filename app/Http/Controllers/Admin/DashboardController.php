<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\Game;
use App\Models\Product;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('admin/Dashboard', [
            'metrics' => [
                'games' => Game::count(),
                'activeGames' => Game::where('is_active', true)->count(),
                'products' => Product::count(),
                'activeProducts' => Product::where('is_active', true)->count(),
                'admins' => Admin::count(),
            ],
        ]);
    }
}
