<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

class CatalogPageController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('admin/Catalog', [
            'adminRole' => auth('admin')->user()?->role?->code,
        ]);
    }
}
