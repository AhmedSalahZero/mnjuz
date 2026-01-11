<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['auth:user'])->group(function () {
    Route::get('/developer-tools/webhooks', [Modules\Webhook\Controllers\MainController::class, 'index']);
    Route::post('/developer-tools/webhooks', [Modules\Webhook\Controllers\MainController::class, 'store']);
    Route::post('/developer-tools/webhooks/{uuid}', [Modules\Webhook\Controllers\MainController::class, 'update']);
    Route::delete('/developer-tools/webhooks/{uuid}', [Modules\Webhook\Controllers\MainController::class, 'destroy']);
    Route::post('/webhooks/trigger/{event}', [Modules\Webhook\Controllers\MainController::class, 'trigger']);
    Route::post('/webhooks/trigger/{event}/test', [Modules\Webhook\Controllers\MainController::class, 'test']);
});

Route::prefix('admin')->middleware(['auth:admin'])->group(function () {
    Route::post('/addons/setup/webhooks', [Modules\Webhook\Controllers\SetupController::class, 'store']);
    Route::put('/addons/setup/webhooks', [Modules\Webhook\Controllers\SetupController::class, 'update']);
});