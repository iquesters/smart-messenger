<?php

use Illuminate\Support\Facades\Route;
use Iquesters\SmartMessenger\Http\Controllers\Api\ContactController;
use Iquesters\SmartMessenger\Http\Controllers\Api\DiagnosticsController;
use Iquesters\SmartMessenger\Http\Controllers\Api\ChatSessionHandoverController;

// All middleware and prefix are handled in the service provider
Route::get('/contacts', [ContactController::class, 'index']);
Route::post('/contacts', [ContactController::class, 'store']);
Route::put('/contacts/{uid}', [ContactController::class, 'update']);
Route::get('/diagnostics/{integrationId}/message/{messageId}', [DiagnosticsController::class, 'show']);
Route::post('/chat-sessions/handover/return-to-bot', [ChatSessionHandoverController::class, 'returnToBot'])
    ->name('smart-messenger.chat-sessions.handover.return-to-bot');
