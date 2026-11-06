<?php

use App\Http\Controllers\AdminDownloadController;
use App\Http\Controllers\FakeGatewayController;
use App\Http\Controllers\PaymentCallbackController;
use App\Http\Controllers\ResetPasswordPageController;
use App\Http\Controllers\VerifyEmailController;
use App\Http\Controllers\Visitor\ScanController;
use App\Http\Controllers\Visitor\VisitorChatController;
use App\Http\Middleware\RejectCrossSiteRequest;
use App\Http\Middleware\VisitorPageHeaders;
use Filament\Http\Middleware\AuthenticateSession;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

// The domain printed on every sticker: a short translated page, nothing about the framework.
Route::get('/', function (Request $request) {
    VisitorPageHeaders::useVisitorLocale($request);

    return response()->view('home')->header('Vary', 'Accept-Language');
})->middleware('throttle:api');

Route::get('/email/verify/{id}/{hash}', VerifyEmailController::class)
    ->middleware(['signed', 'throttle:auth'])
    ->name('verification.verify');

// Where the password reset email leads. Keeps the web group: the form needs a session for its CSRF token.
// Locale, noindex and no-store come from VisitorPageHeaders; the controller adds Referrer-Policy: no-referrer so the
// token in the URL never reaches another site.
Route::middleware([VisitorPageHeaders::class, 'throttle:auth'])->group(function () {
    Route::get('/reset-password', [ResetPasswordPageController::class, 'show'])->name('password.reset');
    Route::post('/reset-password', [ResetPasswordPageController::class, 'update'])->name('password.update');
});

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

// Admin-only downloads (wave packages, sales CSV). Same session as the panel; the controller refuses anyone who cannot open it.
// Links are one-minute signed URLs the panel hands out, so another site cannot make an admin's browser run one,
// and AuthenticateSession drops a session that a password change has invalidated.
Route::prefix('admin/downloads')->middleware(['throttle:api', AuthenticateSession::class, 'signed'])->group(function () {
    Route::get('waves/{wave}/package', [AdminDownloadController::class, 'package'])->whereNumber('wave')->name('admin.waves.package');
    Route::get('payments/export', [AdminDownloadController::class, 'sales'])->name('admin.payments.export');
});

// Payment provider callback: the only thing that can extend a subscription. From the provider's server or, for iyzico,
// the owner's browser, so no session, cookies or CSRF token; the driver's verification and a status re-check are the authentication.
Route::withoutMiddleware([
    EncryptCookies::class,
    AddQueuedCookiesToResponse::class,
    StartSession::class,
    ShareErrorsFromSession::class,
    PreventRequestForgery::class,
])->group(function () {
    Route::post('/payments/callback/{provider}', [PaymentCallbackController::class, 'callback'])
        ->middleware('throttle:payment-callback')->name('payments.callback');
    // Reads nothing from the request and writes nothing.
    Route::get('/payments/return', [PaymentCallbackController::class, 'return'])
        ->middleware('throttle:api')->name('payments.return');
});

// Local development checkout for FakeGateway; the route does not exist under any other gateway.
if (config('wasla.payment_gateway') === 'fake' && ! app()->isProduction()) {
    Route::get('/fake-gateway/checkout/{reference}', [FakeGatewayController::class, 'show'])
        ->middleware('throttle:api')->name('fake-gateway.show');
    Route::post('/fake-gateway/checkout/{reference}/{outcome}', [FakeGatewayController::class, 'complete'])
        ->whereIn('outcome', ['pay', 'fail'])->middleware('throttle:api')->name('fake-gateway.complete');
}
