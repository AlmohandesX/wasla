<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** The mobile API is for owners only: panel accounts (admin, super_admin) use the web panel at /admin. */
class EnsureAppAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->isAdmin()) {
            return response()->json([
                'code' => 'admin_account',
                'message' => __('wasla.admin_account'),
            ], 403);
        }

        return $next($request);
    }
}
