<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CarController;
use App\Http\Controllers\Api\ClaimController;
use App\Http\Controllers\Api\ContactController;
use App\Http\Controllers\Api\StatusController;
use App\Http\Controllers\Api\TransferController;
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
    Route::middleware(['owner', 'throttle:api'])->group(function () {
        // Always reachable, including for a car whose subscription expired.
        Route::get('cars', [CarController::class, 'index']);
        Route::get('cars/{car}', [CarController::class, 'show']);
        Route::post('cars/{car}/unlink', [TransferController::class, 'unlink'])->middleware('throttle:writes');

        Route::post('tags/claim', ClaimController::class)->middleware('throttle:claim');
        Route::post('cars', [CarController::class, 'store'])->middleware('throttle:writes');

        // Hard stop: a car with an expired tag gets 402 subscription_expired on all of these.
        Route::middleware('car.subscription')->group(function () {
            Route::patch('cars/{car}', [CarController::class, 'update'])->middleware('throttle:writes');
            Route::delete('cars/{car}', [CarController::class, 'destroy'])->middleware('throttle:writes');

            Route::put('cars/{car}/stealth', [StatusController::class, 'stealth'])->middleware('throttle:writes');
            Route::put('cars/{car}/chats-paused', [StatusController::class, 'chatsPaused'])->middleware('throttle:writes');
            Route::put('cars/{car}/status', [StatusController::class, 'set'])->middleware('throttle:writes');
            Route::delete('cars/{car}/status', [StatusController::class, 'clear'])->middleware('throttle:writes');

            Route::get('cars/{car}/contacts', [ContactController::class, 'index']);
            Route::post('cars/{car}/contacts', [ContactController::class, 'store'])->middleware('throttle:writes');
            Route::patch('contacts/{contact}', [ContactController::class, 'update'])->middleware('throttle:writes');
            Route::delete('contacts/{contact}', [ContactController::class, 'destroy'])->middleware('throttle:writes');
        });
    });
});
