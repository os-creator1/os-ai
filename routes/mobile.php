<?php

use App\Http\Controllers\Mobile\MobileAuthController;
use App\Http\Controllers\Mobile\MobileBusinessController;
use App\Http\Controllers\Mobile\MobileCallingController;
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

Route::middleware(['auth:sanctum', EnsureMobileToken::class])->group(function () {
    Route::post('auth/logout', [MobileAuthController::class, 'logout'])->name('auth.logout');
    Route::post('auth/refresh', [MobileAuthController::class, 'refresh'])->middleware('throttle:10,1')->name('auth.refresh');

    Route::get('businesses', [MobileBusinessController::class, 'index'])->middleware('throttle:60,1')->name('businesses.index');

    Route::put('devices/current', [MobileDeviceController::class, 'register'])->middleware('throttle:20,1')->name('devices.register');
    Route::delete('devices/current', [MobileDeviceController::class, 'unregister'])->name('devices.unregister');

    Route::prefix('workspaces/{workspaceUid}/businesses/{businessUid}')->middleware('throttle:120,1')->group(function () {
        Route::get('conversations', [MobileConversationController::class, 'index'])->name('conversations.index');
        Route::get('conversations/{uid}', [MobileConversationController::class, 'show'])->name('conversations.show');
        Route::get('contacts/{uid}', [MobileContactController::class, 'show'])->name('contacts.show');
        Route::get('calls/capability', [MobileCallingController::class, 'capability'])->name('calls.capability');
    });
});
