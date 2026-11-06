<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    private const STRICT_CSP = "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; "
        ."font-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'";

    // Filament (Livewire and Alpine) needs inline and eval script support, so this applies to /admin only.
    private const ADMIN_CSP = "default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; "
        ."style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self' data:; connect-src 'self'; "
        ."object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'";

    public function handle(Request $request, Closure $next): Response
    {
        return self::apply($request, $next($request));
    }

    /** Also used for error pages rendered outside the web group (no route matched, or a throttle refused first). */
    public static function apply(Request $request, Response $response): Response
    {
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        // A page that carries a secret in its URL (the reset link) sets a stricter policy itself.
        if (! $response->headers->has('Referrer-Policy')) {
            $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        }
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $response->headers->set(
            'Content-Security-Policy',
            $request->is('admin', 'admin/*') ? self::ADMIN_CSP : self::STRICT_CSP,
        );

        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
