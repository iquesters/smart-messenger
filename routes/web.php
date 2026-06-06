<?php

use Illuminate\Support\Facades\Route;
use Iquesters\SmartMessenger\Http\Controllers\MessagingController;
use Iquesters\SmartMessenger\Http\Controllers\MessagingProfileController;
use Iquesters\SmartMessenger\Http\Controllers\ContactPageController;
use Iquesters\SmartMessenger\Http\Controllers\MessagingIntegrationController;
use Iquesters\SmartMessenger\Http\Controllers\MockVideoController;

Route::middleware(['web', 'auth'])->group(function () {
    
    // Messaging Profiles Routes
    Route::controller(MessagingProfileController::class)
        ->prefix('channels')
        ->name('channels.')
        ->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('/create', 'create')->name('create');
            Route::post('/create/step1', 'storeStep1')->name('store-step1');
            Route::post('/', 'store')->name('store');
            Route::get('/{profileUid}', 'show')->name('show');
            Route::get('/{profileUid}/edit', 'edit')->name('edit');
            Route::post('/{profileUid}/edit/step1', 'updateStep1')->name('update-step1');
            Route::put('/{profileUid}', 'update')->name('update');
            Route::delete('/{profileUid}', 'destroy')->name('destroy');
        });
    
    // Messaging/Inbox Routes
    Route::controller(MessagingController::class)
        ->prefix('inbox')
        ->name('messages.')
        ->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('/history', 'loadOlderMessages')->name('history');
            Route::post('/send', 'sendMessage')->name('send');
        });

    Route::controller(MockVideoController::class)
        ->prefix('mock/media')
        ->name('mock.media.')
        ->group(function () {
            Route::post('/normalize-video', 'normalizeVideo')->name('normalize-video');
            Route::get('/jobs/{messageId}', 'jobStatus')->name('jobs.status');
        });

    // Mock Messages Send (for local testing)
    Route::post('/mock/messages/send', [MockVideoController::class, 'sendMessage'])->name('mock.messages.send');

    Route::get('/mock/media/jobs/{jobId}', function($jobId) {
        return response()->json([
            'status' => 'completed',
            'progress_pct' => 100,
        ]);
    });
    
    // Contacts Routes
    Route::controller(ContactPageController::class)
        ->prefix('contacts')
        ->name('contacts.')
        ->group(function () {
            Route::get('/', 'index')->name('index');
        });
    
    // Integrations Routes
    // Route::controller(MessagingIntegrationController::class)
    //     ->prefix('integrations')
    //     ->name('integrations.')
    //     ->group(function () {
    //         Route::get('/', 'index')->name('index');
    //     });
});

// Webhook routes (typically without auth middleware)
require __DIR__.'/webhook.php';
