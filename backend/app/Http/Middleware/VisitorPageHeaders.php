<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Shared by every public scan route: locale from Accept-Language (English default; Turkish when the browser prefers it), noindex, never cached. */
class VisitorPageHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        self::useVisitorLocale($request);

        return self::apply($next($request));
    }

    /** Turkish unless the browser prefers English. */
    public static function useVisitorLocale(Request $request): void
    {
        app()->setLocale($request->getPreferredLanguage(['en', 'tr']) ?? 'en');
    }

    public static function apply(Response $response): Response
    {
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Vary', 'Accept-Language');

        return $response;
    }
}
