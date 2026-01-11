<?php

use Illuminate\Support\Facades\Route;

Route::prefix('admin')->middleware(['auth:admin'])->group(function () {
    Route::post('/addons/setup/razorpay', [Modules\Razorpay\Controllers\SetupController::class, 'store']);
    Route::put('/addons/setup/razorpay', [Modules\Razorpay\Controllers\SetupController::class, 'update']);
});