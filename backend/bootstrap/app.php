<?php

use App\Http\Middleware\EnsureEmailVerified;
use App\Http\Middleware\EnsureNotSuspended;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

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

        $middleware->alias([
            'not.suspended' => EnsureNotSuspended::class,
            'verified' => EnsureEmailVerified::class,
        ]);

        // "account": signed in and not suspended; usable before the email is verified (me, logout, resend).
        // "owner": additionally requires a verified email. Every owner route uses it.
        $middleware->group('account', ['auth:sanctum', 'not.suspended']);
        $middleware->group('owner', ['auth:sanctum', 'not.suspended', 'verified']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
