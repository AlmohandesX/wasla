<?php

use App\Http\Controllers\VerifyEmailController;
use App\Http\Controllers\Visitor\ScanController;
use App\Http\Controllers\Visitor\VisitorChatController;
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

// Visitor chat. Unlike the scan page it writes state, so it keeps the web group: a session for a real CSRF token
// on every POST, and the wasla_visitor cookie that identifies the thread.
Route::prefix('t/{token}/chat')
    ->middleware(VisitorPageHeaders::class)
    ->group(function () {
        Route::get('/', [VisitorChatController::class, 'show'])->middleware('throttle:chat-read')->name('chat.show');
        Route::get('messages', [VisitorChatController::class, 'messages'])->middleware('throttle:chat-read')->name('chat.messages');
        Route::post('messages', [VisitorChatController::class, 'store'])->middleware('throttle:chat-write')->name('chat.store');
        Route::post('messages/{message}/report', [VisitorChatController::class, 'report'])
            ->whereNumber('message')->middleware('throttle:chat-write')->name('chat.report');
    });
