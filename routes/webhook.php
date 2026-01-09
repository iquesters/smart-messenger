<?php

use Iquesters\SmartMessenger\Http\Controllers\Webhook\WhatsAppWHController;
use Illuminate\Support\Facades\Route;

Route::post('/webhook/whatsapp/{channelUid}', [WhatsAppWHController::class, 'handle']);
Route::get('/webhook/whatsapp/{channelUid}', [WhatsAppWHController::class, 'handle']);