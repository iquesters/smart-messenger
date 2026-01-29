<?php

use Iquesters\SmartMessenger\Http\Controllers\Webhook\WhatsAppWHController;
use Illuminate\Support\Facades\Route;
use Iquesters\SmartMessenger\Http\Controllers\TestChatbotController;
use Iquesters\SmartMessenger\Http\Controllers\Webhook\TelegramWHController;

Route::post('/webhook/whatsapp/{channelUid}', [WhatsAppWHController::class, 'handle']);
Route::get('/webhook/whatsapp/{channelUid}', [WhatsAppWHController::class, 'handle']);
// Route::post('/webhook/telegram/{channelUid}', [TelegramWHController::class, 'handle']);
Route::post('api/test/chatbot', [TestChatbotController::class, 'handle']);