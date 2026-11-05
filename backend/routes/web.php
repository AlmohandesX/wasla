<?php

use App\Http\Controllers\VerifyEmailController;
use App\Http\Controllers\Visitor\ScanController;
use App\Http\Middleware\RejectCrossSiteRequest;
use App\Http\Middleware\VisitorPageHeaders;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/email/verify/{id}/{hash}', VerifyEmailController::class)
    ->middleware(['signed', 'throttle:auth'])
    ->name('verification.verify');

// Public scan page. Deliberately session-less: neutral and unclaimed pages must set no cookie at all, and the
// web group sends a session and an XSRF cookie. The reveal POST is protected by RejectCrossSiteRequest instead of
// a CSRF token.
Route::prefix('t/{token}')
    ->withoutMiddleware([
        EncryptCookies::class,
        AddQueuedCookiesToResponse::class,
        StartSession::class,
        ShareErrorsFromSession::class,
        PreventRequestForgery::class,
    ])
    ->middleware(VisitorPageHeaders::class)
    ->group(function () {
        Route::get('/', [ScanController::class, 'show'])->middleware('throttle:scan')->name('scan.show');
        Route::post('contacts/{contact}/reveal', [ScanController::class, 'reveal'])
            ->middleware(['throttle:contact-reveal', RejectCrossSiteRequest::class])
            ->name('scan.reveal');
    });
