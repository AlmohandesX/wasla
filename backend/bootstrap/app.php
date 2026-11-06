<?php

use App\Exceptions\RenewalPending;
use App\Exceptions\TagException;
use App\Http\Middleware\EnsureCarSubscriptionActive;
use App\Http\Middleware\EnsureEmailVerified;
use App\Http\Middleware\EnsureNotSuspended;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetApiLocale;
use App\Http\Middleware\VisitorPageHeaders;
use App\Support\RedactNationalId;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            SecurityHeaders::class,
        ]);

        // The visitor cookie is a random 256-bit bearer token that the server only stores hashed; encrypting it
        // would add nothing and tie visitor sessions to APP_KEY rotation.
        $middleware->encryptCookies(except: ['wasla_visitor']);

        $middleware->api(prepend: [SetApiLocale::class]);

        $middleware->alias([
            'not.suspended' => EnsureNotSuspended::class,
            'verified' => EnsureEmailVerified::class,
            'car.subscription' => EnsureCarSubscriptionActive::class,
        ]);

        // "account": signed in and not suspended; usable before the email is verified (me, logout, resend).
        // "owner": additionally requires a verified email. Every owner route uses it.
        $middleware->group('account', ['auth:sanctum', 'not.suspended']);
        $middleware->group('owner', ['auth:sanctum', 'not.suspended', 'verified']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Domain errors (tag, ownership, expiry, moderation) all render as {code, message}.
        $exceptions->render(fn (TagException $e) => response()->json([
            'code' => $e->errorCode,
            'message' => __('wasla.error_'.$e->errorCode),
            // Lets the app pick up the checkout that is already open instead of starting another.
            ...($e instanceof RenewalPending
                ? ['payment_id' => $e->paymentId, 'checkout_expires_at' => $e->opensUntil->toIso8601String(), 'checkout_expires_in' => $e->secondsLeft()]
                : []),
        ], $e->status));

        // A throttled API request gets a stable code and a translated message; Retry-After stays.
        $exceptions->render(function (TooManyRequestsHttpException $e, Request $request) {
            if (! ($request->is('api/*') || $request->expectsJson())) {
                return null;
            }

            return response()->json([
                'code' => 'too_many_requests',
                'message' => __('wasla.too_many_requests'),
            ], 429, $e->getHeaders());
        });

        // HTML error pages (errors/*) speak the visitor's language. An unmatched route or a throttle refusal never
        // reaches the web middleware, so the locale and the headers are set here as well.
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! ($request->is('api/*') || $request->expectsJson())) {
                VisitorPageHeaders::useVisitorLocale($request);
            }

            return null;
        });
        $exceptions->respond(function (Response $response, Throwable $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return $response;
            }

            return VisitorPageHeaders::apply(SecurityHeaders::apply($request, $response));
        });

        // The national ID is never flashed to a session, and BillingDetails masks it in dumps and traces.
        $exceptions->dontFlash(['billing_national_id', 'billing_phone']);
        $exceptions->context(fn (Throwable $e, array $context) => RedactNationalId::from($context));

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
