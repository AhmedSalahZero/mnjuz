<?php

use App\Http\Middleware\AuthenticateBearerToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

/*Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});*/
Route::get('/translations/{locale}', function ($locale) {
    if (Str::startsWith($locale, 'php_')) {
        return response()->json(['error' => 'Invalid locale'], 400);
    }

    $path = base_path("lang/{$locale}.json");
    
    if (!File::exists($path)) {
        return response()->json(['error' => 'Translation file not found'], 404);
    }

    return response()->json(json_decode(File::get($path), true));
});

Route::middleware([AuthenticateBearerToken::class])->group(function () {
    Route::post('/send', [App\Http\Controllers\ApiController::class, 'sendMessage']);
    Route::post('/send/template', [App\Http\Controllers\ApiController::class, 'sendTemplateMessage']);
    Route::post('/send/media', [App\Http\Controllers\ApiController::class, 'sendMediaMessage']);
    Route::post('/campaigns', [App\Http\Controllers\ApiController::class, 'storeCampaign']);
    
    Route::get('/contacts', [App\Http\Controllers\ApiController::class, 'listContacts']);
    Route::post('/contacts', [App\Http\Controllers\ApiController::class, 'storeContact']);
    Route::put('/contacts/{uuid}', [App\Http\Controllers\ApiController::class, 'storeContact']);
    Route::delete('/contacts/{uuid}', [App\Http\Controllers\ApiController::class, 'destroyContact']);

    Route::get('/contact-groups', [App\Http\Controllers\ApiController::class, 'listContactGroups']);
    Route::post('/contact-groups', [App\Http\Controllers\ApiController::class, 'storeContactGroup']);
    Route::put('/contact-groups/{uuid}', [App\Http\Controllers\ApiController::class, 'storeContactGroup']);
    Route::delete('/contact-groups/{uuid}', [App\Http\Controllers\ApiController::class, 'destroyContactGroup']);

    Route::get('/canned-replies', [App\Http\Controllers\ApiController::class, 'listCannedReplies']);
    Route::post('/canned-replies', [App\Http\Controllers\ApiController::class, 'storeCannedReply']);
    Route::put('/canned-replies/{uuid}', [App\Http\Controllers\ApiController::class, 'storeCannedReply']);
    Route::delete('/canned-replies/{uuid}', [App\Http\Controllers\ApiController::class, 'destroyCannedReply']);

    Route::get('/templates', [App\Http\Controllers\ApiController::class, 'listTemplates']);
    Route::get('/verify', [App\Http\Controllers\ApiController::class, 'verifyApiKey']);
});
