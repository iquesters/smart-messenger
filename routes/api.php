<?php

use Illuminate\Support\Facades\Route;
use Iquesters\SmartMessenger\Http\Controllers\Api\ChatbotTestRunController;
use Iquesters\SmartMessenger\Http\Controllers\Api\ContactController;
use Iquesters\SmartMessenger\Http\Controllers\Api\DiagnosticsController;

// All middleware and prefix are handled in the service provider
Route::get('/contacts', [ContactController::class, 'index']);
Route::post('/contacts', [ContactController::class, 'store']);
Route::put('/contacts/{uid}', [ContactController::class, 'update']);
Route::get('/diagnostics/{integrationId}/message/{messageId}', [DiagnosticsController::class, 'show']);
Route::post('/chatbot-tests/runs/start', [ChatbotTestRunController::class, 'start']);
Route::get('/chatbot-tests/runs/{runUid}', [ChatbotTestRunController::class, 'show']);
Route::post('/chatbot-tests/runs/{runUid}/cancel', [ChatbotTestRunController::class, 'cancel']);