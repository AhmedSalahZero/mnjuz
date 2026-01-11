<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['auth:user'])->group(function () {
    Route::get('/automation/ai', [Modules\IntelliReply\Controllers\MainController::class, 'index']);
    Route::post('/automation/ai/assistant-setup', [Modules\IntelliReply\Controllers\MainController::class, 'assistant_setup']);
    Route::post('/automation/ai/setup', [Modules\IntelliReply\Controllers\MainController::class, 'setup']);
    Route::post('/automation/ai/activate', [Modules\IntelliReply\Controllers\MainController::class, 'activate']);
    Route::post('/automation/upload/document', [Modules\IntelliReply\Controllers\DocumentController::class, 'store'])->name('upload.document');
    Route::delete('/automation/upload/document/{uuid}', [Modules\IntelliReply\Controllers\DocumentController::class, 'delete']);
    Route::get('/chat-ai', [Modules\IntelliReply\Controllers\ChatController::class, 'chat'])->name('chat');

    Route::post('/automation/contact/{uuid}', [Modules\IntelliReply\Controllers\MainController::class, 'enable_ai_assistant']);
    Route::get('/automation/chat/suggestion', [Modules\IntelliReply\Controllers\MainController::class, 'chat_suggestion']);
});

Route::prefix('admin')->middleware(['auth:admin'])->group(function () {
    Route::post('/addons/setup/ai-assistant', [Modules\IntelliReply\Controllers\SetupController::class, 'store']);
    Route::put('/addons/setup/ai-assistant', [Modules\IntelliReply\Controllers\SetupController::class, 'update']);
});