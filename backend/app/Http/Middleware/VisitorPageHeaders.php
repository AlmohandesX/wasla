<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Shared by every public scan route: locale from Accept-Language (Turkish default), noindex, never cached. */
class VisitorPageHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        app()->setLocale($request->getPreferredLanguage(['tr', 'en']) ?? 'tr');

        $response = $next($request);

        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Vary', 'Accept-Language');

        return $response;
    }
}
