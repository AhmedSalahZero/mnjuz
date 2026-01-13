<?php 

use Illuminate\Support\Facades\Route;

Route::match(['get', 'post'], '/whatsapp/exchange-code', [Modules\EmbeddedSignup\Controllers\RegisterController::class, 'handleSignup']);

Route::prefix('admin')->middleware(['auth:admin'])->group(function () {
    Route::post('/addons/setup/embedded-signup', [Modules\EmbeddedSignup\Controllers\SetupController::class, 'store']);
    Route::put('/addons/setup/embedded-signup', [Modules\EmbeddedSignup\Controllers\SetupController::class, 'update']);
});