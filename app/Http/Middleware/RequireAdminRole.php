<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class RequireAdminRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $admin = $request->user('admin');

        if (! $admin) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }

            return redirect()->guest(route('admin.login'));
        }

        if (! $admin->is_active) {
            Auth::guard('admin')->logout();
            abort(403, 'Akun administrator tidak aktif.');
        }

        if ($roles !== [] && ! $admin->role()->whereIn('code', $roles)->exists()) {
            abort(403, 'Peran administrator tidak memiliki izin untuk tindakan ini.');
        }

        return $next($request);
    }
}
