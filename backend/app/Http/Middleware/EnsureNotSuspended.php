<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureNotSuspended
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->isSuspended()) {
            return response()->json([
                'code' => 'account_suspended',
                'message' => __('wasla.account_suspended'),
            ], 403);
        }

        return $next($request);
    }
}
