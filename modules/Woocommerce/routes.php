<?php

use Illuminate\Support\Facades\Route;

Route::prefix('admin')->middleware(['auth:admin'])->group(function () {
    Route::post('/addons/setup/woocommerce', [Modules\Woocommerce\Controllers\SetupController::class, 'store']);
    Route::put('/addons/setup/woocommerce', [Modules\Woocommerce\Controllers\SetupController::class, 'update']);
});