<?php

use App\Http\Controllers\Api\AuthController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::prefix('auth')->middleware('throttle:auth')->group(function () {
        Route::post('register', [AuthController::class, 'register']);
        Route::post('login', [AuthController::class, 'login']);
        Route::post('forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:forgot-password');
        Route::post('reset-password', [AuthController::class, 'resetPassword']);
    });

    // Signed in and not suspended; usable before the email is verified.
    Route::middleware(['account', 'throttle:api'])->group(function () {
        Route::get('me', [AuthController::class, 'me']);
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::post('auth/email/resend', [AuthController::class, 'resend'])->middleware('throttle:resend-verification');
    });

    // Owner routes: signed in, not suspended, email verified.
    Route::middleware(['owner', 'throttle:api'])->prefix('owner')->group(function () {
        Route::get('ping', fn () => response()->json(['ok' => true]));
    });
});
