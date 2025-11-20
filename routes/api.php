<?php

use App\Http\Controllers\API\TelegramAccountController;
use Illuminate\Support\Facades\Route;

// Simple microservice API - only essential endpoints
Route::prefix('v1')->middleware('api.key')->group(function () {

    Route::post('login/start', [TelegramAccountController::class, 'startLogin']);
    Route::post('login/complete-code', [TelegramAccountController::class, 'completeCode']);
    Route::post('login/complete-2fa', [TelegramAccountController::class, 'complete2FA']);

    Route::post('session/stop', [TelegramAccountController::class, 'stop']);
    Route::post('session/restart', [TelegramAccountController::class, 'restart']);
    Route::post('session/status', [TelegramAccountController::class, 'status']);

    Route::post('send-message', [TelegramAccountController::class, 'sendMessage']);
    Route::post('send-voice', [TelegramAccountController::class, 'sendVoice']);
    Route::post('send-file', [TelegramAccountController::class, 'sendFile']);
});
