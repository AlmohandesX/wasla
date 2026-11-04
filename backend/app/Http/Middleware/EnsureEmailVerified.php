<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureEmailVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->hasVerifiedEmail()) {
            return response()->json([
                'code' => 'email_not_verified',
                'message' => __('wasla.email_not_verified'),
            ], 403);
        }

        return $next($request);
    }
}
