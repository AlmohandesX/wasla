<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Locale for API responses: the first supported language in Accept-Language, else the signed-in owner's
 * saved locale, else Turkish. An unsupported language (say "fr") is not a preference, so it falls through.
 */
class SetApiLocale
{
    private const SUPPORTED = ['tr', 'en'];

    public function handle(Request $request, Closure $next): Response
    {
        app()->setLocale($this->fromHeader($request) ?? $this->fromOwner($request) ?? 'tr');

        $response = $next($request);
        $response->headers->set('Vary', 'Accept-Language, Authorization');

        return $response;
    }

    private function fromHeader(Request $request): ?string
    {
        foreach ($request->getLanguages() as $language) {
            $short = strtolower(substr($language, 0, 2));
            if (in_array($short, self::SUPPORTED, true)) {
                return $short;
            }
        }

        return null;
    }

    private function fromOwner(Request $request): ?string
    {
        $locale = $request->user('sanctum')?->locale;

        return in_array($locale, self::SUPPORTED, true) ? $locale : null;
    }
}
