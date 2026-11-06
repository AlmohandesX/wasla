<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * CSRF defence for session-less POSTs. The scan routes have no session, hence no CSRF token, so a write must
 * come from our own script: it needs a custom header (which a cross-site form cannot send, and a cross-site
 * fetch can only send after a CORS preflight that we never approve), and any Origin or Sec-Fetch-Site the
 * browser attaches must say same-origin.
 */
class RejectCrossSiteRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->header('X-Requested-With') !== 'XMLHttpRequest') {
            abort(403);
        }

        $site = $request->header('Sec-Fetch-Site');
        if ($site !== null && $site !== 'same-origin') {
            abort(403);
        }

        $origin = $request->header('Origin');
        if ($origin !== null && $origin !== $request->getSchemeAndHttpHost()) {
            abort(403);
        }

        return $next($request);
    }
}
