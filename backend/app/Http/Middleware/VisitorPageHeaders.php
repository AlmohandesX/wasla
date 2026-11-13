<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shared by every public page: the visitor's locale, noindex, never cached.
 *
 * On the scan and chat pages (/t/...) the language is the visitor's own choice, never the browser's or the car
 * owner's: Turkish unless the page was opened with ?lang=en (the TR | EN switch). The scan page sets no cookie,
 * so the choice travels in the links between those pages and, for the chat page's background requests, in the
 * X-Wasla-Lang header. Other public pages (home, password reset) follow the browser: English unless it prefers
 * Turkish.
 */
class VisitorPageHeaders
{
    public const LOCALES = ['tr', 'en'];

    public const DEFAULT_LOCALE = 'tr';

    public const HEADER = 'X-Wasla-Lang';

    public function handle(Request $request, Closure $next): Response
    {
        self::useVisitorLocale($request);

        return self::apply($next($request));
    }

    public static function useVisitorLocale(Request $request): void
    {
        app()->setLocale($request->is('t/*') ? self::scanLocale($request) : ($request->getPreferredLanguage(['en', 'tr']) ?? 'en'));
    }

    /** The scan and chat pages: the TR | EN choice, else Turkish. */
    public static function scanLocale(Request $request): string
    {
        foreach ([$request->query('lang'), $request->header(self::HEADER)] as $choice) {
            if (is_string($choice) && in_array(strtolower($choice), self::LOCALES, true)) {
                return strtolower($choice);
            }
        }

        return self::DEFAULT_LOCALE;
    }

    /** Appends ?lang= to a public link when the visitor is not on the default language, so the choice carries over. */
    public static function link(string $path): string
    {
        $locale = app()->getLocale();

        return $locale === self::DEFAULT_LOCALE ? $path : $path.(str_contains($path, '?') ? '&' : '?').'lang='.$locale;
    }

    public static function apply(Response $response): Response
    {
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Vary', 'Accept-Language, '.self::HEADER);

        return $response;
    }
}
