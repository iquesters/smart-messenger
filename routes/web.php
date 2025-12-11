<?php

use Illuminate\Support\Facades\Route;
use Iquesters\SmartMessenger\Http\Controllers\MessagingController;
use Iquesters\SmartMessenger\Http\Controllers\MessagingProfileController;

Route::middleware('web')->group(function () {
    Route::middleware(['auth'])->group(function () {
        Route::controller(MessagingProfileController::class)->prefix('profiles')->name('profiles.')
        ->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('/create', 'create')->name('create');
            Route::post('/', 'store')->name('store');
            Route::get('/{profileUid}/edit', 'edit')->name('edit');
            Route::put('/{profileUid}', 'update')->name('update');
            Route::delete('/{profileUid}', 'destroy')->name('destroy');
            
        });
        Route::controller(MessagingController::class)->prefix('messaging')->name('messages.')
        ->group(function () {
            Route::get('/', 'index')->name('index');
        });
    });
});