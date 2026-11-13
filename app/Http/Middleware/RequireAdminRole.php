<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireAdminRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $admin = $request->user('admin');

        if (! $admin || ! $admin->is_active) {
            abort(403, 'Akses administrator tidak diizinkan.');
        }

        if ($roles !== [] && ! $admin->role()->whereIn('code', $roles)->exists()) {
            abort(403, 'Peran administrator tidak memiliki izin untuk tindakan ini.');
        }

        return $next($request);
    }
}
