<?php

use Illuminate\Support\Facades\Route;
use Iquesters\SmartMessenger\Http\Controllers\Api\ContactController;

// All middleware and prefix are handled in the service provider
Route::get('/contacts', [ContactController::class, 'index']);
Route::put('/contacts/{uid}', [ContactController::class, 'update']);