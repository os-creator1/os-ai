<?php

use App\Http\Controllers\Gateway\SmtpGatewayController;
use Illuminate\Support\Facades\Route;

// Loaded only when business_email.smtp_gateway.serve is true. No session, no
// CSRF, no user: every request is authenticated by its HMAC signature.
Route::post('send', [SmtpGatewayController::class, 'send']);
Route::post('test', [SmtpGatewayController::class, 'test']);
