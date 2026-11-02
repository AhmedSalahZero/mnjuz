<?php

use Illuminate\Support\Facades\Route;

// نفس حراسة /automation/basic في routes/web.php: كانت auth:user وحدها، فيفتح
// الموظف (agent) المسارات ويعدّلها، ويصل أيّ مستخدم إلى flow منشأة أخرى بالـ uuid.
Route::middleware([
    'auth:user',
    'check.email.verification',
    'check.active.organization',
    'check.organization',
    'org.agent.restrict',
    'check.subscription',
])->group(function () {
    Route::prefix('/automation/flows')->group(function () {
        Route::get('/', [Modules\FlowBuilder\Controllers\FlowController::class, 'index']);                      // List all flows
        Route::get('/{flow}', [Modules\FlowBuilder\Controllers\FlowController::class, 'view']); 
        Route::post('/', [Modules\FlowBuilder\Controllers\FlowController::class, 'store']);                     // Create a new flow
        Route::get('/duplicate/{uuid}', [Modules\FlowBuilder\Controllers\FlowController::class, 'duplicate']);
        Route::post('/upload-media/{uuid}/{stepId}', [Modules\FlowBuilder\Controllers\FlowController::class, 'uploadMedia']);                     
        Route::put('/{flow}', [Modules\FlowBuilder\Controllers\FlowController::class, 'update']);               // Update a flow
        Route::delete('/{flow}', [Modules\FlowBuilder\Controllers\FlowController::class, 'destroy']);           // Delete a flow
    });
});

Route::prefix('admin')->middleware(['auth:admin'])->group(function () {
    Route::post('/addons/setup/flow-builder', [Modules\FlowBuilder\Controllers\SetupController::class, 'store']);
    Route::put('/addons/setup/flow-builder', [Modules\FlowBuilder\Controllers\SetupController::class, 'update']);
});