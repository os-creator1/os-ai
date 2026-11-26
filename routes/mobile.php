<?php

use App\Http\Controllers\Mobile\MobileAuthController;
use App\Http\Controllers\Mobile\MobileBusinessController;
use App\Http\Controllers\Mobile\MobileCallingController;
use App\Http\Controllers\Mobile\MobileRealtimeController;
use App\Http\Controllers\Mobile\MobileContactController;
use App\Http\Controllers\Mobile\MobileConversationController;
use App\Http\Controllers\Mobile\MobileDeviceController;
use App\Http\Middleware\EnsureMobileToken;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| MotionGrove Mobile API — /api/mobile/v1  (docs/mobile/ARCHITECTURE.md)
|--------------------------------------------------------------------------
| Login is public and throttled. Everything else needs a Sanctum token carrying the `mobile` ability.
| Business routes mirror the web shape: {workspaceUid}/businesses/{businessUid}/...
*/

Route::post('auth/login', [MobileAuthController::class, 'login'])->middleware('throttle:20,1')->name('auth.login');
Route::post('auth/two-factor', [MobileAuthController::class, 'verifyTwoFactor'])->middleware('throttle:20,1')->name('auth.two-factor');
Route::post('auth/two-factor/resend', [MobileAuthController::class, 'resendTwoFactor'])->middleware('throttle:5,1')->name('auth.two-factor.resend');

Route::middleware(['auth:sanctum', EnsureMobileToken::class])->group(function () {
    Route::post('auth/logout', [MobileAuthController::class, 'logout'])->name('auth.logout');
    Route::post('auth/refresh', [MobileAuthController::class, 'refresh'])->middleware('throttle:10,1')->name('auth.refresh');

    Route::get('businesses', [MobileBusinessController::class, 'index'])->middleware('throttle:60,1')->name('businesses.index');

    Route::get('realtime/config', [MobileRealtimeController::class, 'config'])->middleware('throttle:60,1')->name('realtime.config');
    Route::post('broadcasting/auth', [MobileRealtimeController::class, 'auth'])->middleware('throttle:120,1')->name('realtime.auth');

    Route::put('devices/current', [MobileDeviceController::class, 'register'])->middleware('throttle:20,1')->name('devices.register');
    Route::delete('devices/current', [MobileDeviceController::class, 'unregister'])->name('devices.unregister');

    Route::prefix('workspaces/{workspaceUid}/businesses/{businessUid}')->middleware('throttle:120,1')->group(function () {
        Route::get('conversations', [MobileConversationController::class, 'index'])->name('conversations.index');
        Route::get('conversations/{uid}', [MobileConversationController::class, 'show'])->name('conversations.show');
        Route::post('conversations/{uid}/read', [MobileConversationController::class, 'read'])->name('conversations.read');
        Route::post('conversations/{uid}/reply', [MobileConversationController::class, 'reply'])->middleware('throttle:30,1')->name('conversations.reply');
        Route::post('conversations/{uid}/retry', [MobileConversationController::class, 'retry'])->middleware('throttle:30,1')->name('conversations.retry');
        Route::get('contacts/{uid}', [MobileContactController::class, 'show'])->name('contacts.show');
        Route::get('calls/capability', [MobileCallingController::class, 'capability'])->name('calls.capability');
    });
});
