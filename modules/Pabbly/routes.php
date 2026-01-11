<?php

use Illuminate\Support\Facades\Route;

Route::prefix('admin')->middleware(['auth:admin'])->group(function () {
    Route::post('/addons/setup/pabbly-subscriptions', [Modules\Pabbly\Controllers\SetupController::class, 'store']);
    Route::put('/addons/setup/pabbly-subscriptions', [Modules\Pabbly\Controllers\SetupController::class, 'update']);
});