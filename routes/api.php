<?php

use App\Http\Middleware\AuthenticateBearerToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
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
    Route::get('/contacts', [App\Http\Controllers\ApiController::class, 'listContacts']);
    Route::post('/contacts', [App\Http\Controllers\ApiController::class, 'storeContact']);
    Route::put('/contacts/{uuid}', [App\Http\Controllers\ApiController::class, 'updateContact']);
    Route::delete('/contacts/{uuid}', [App\Http\Controllers\ApiController::class, 'destroyContact']);
	
	Route::middleware(['ExcludeRouteFromDocs'])->group(function () {
		Route::post('/send', [App\Http\Controllers\ApiController::class, 'sendMessage']);
    Route::post('/send/template', [App\Http\Controllers\ApiController::class, 'sendTemplateMessage']);
    Route::post('/send/media', [App\Http\Controllers\ApiController::class, 'sendMediaMessage']);
    Route::post('/campaigns', [App\Http\Controllers\ApiController::class, 'storeCampaign']);
    

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
    
});

Route::prefix('v1')->group(function () {
// Public routes (no authentication required)
Route::prefix('auth')->group(function () {
    Route::post('login', [App\Http\Controllers\AuthController::class, 'login']);
    Route::post('tfa/verify', [App\Http\Controllers\AuthController::class, 'tfaVerify']);
});

// Protected routes (require authentication via Sanctum)

Route::middleware(['auth:sanctum'])->prefix('auth')->group(function () {
    Route::post('logout', [App\Http\Controllers\AuthController::class, 'logout']);
    Route::post('set-current-organization', [App\Http\Controllers\AuthController::class, 'setCurrentOrganization']);
});

Route::middleware(['auth:sanctum','has.mobile.app','check.active.organization','check.has.selected.organization'])->group(function () {
	Route::get('/contacts', [App\Http\Controllers\ApiController::class, 'listContacts']);
    Route::post('/contacts', [App\Http\Controllers\ApiController::class, 'storeContact']);
    Route::put('/contacts/{uuid}', [App\Http\Controllers\ApiController::class, 'updateContact']);
	Route::get('/contacts/{id}', [App\Http\Controllers\ApiController::class, 'getContactDetail']);
    Route::delete('/contacts/{uuid}', [App\Http\Controllers\ApiController::class, 'destroyContact']);
	
	
	Route::get('/contact-groups', [App\Http\Controllers\ApiController::class, 'listContactGroups']);
    Route::post('/contact-groups', [App\Http\Controllers\ApiController::class, 'storeContactGroup']);
    Route::put('/contact-groups/{uuid}', [App\Http\Controllers\ApiController::class, 'storeContactGroup']);
    Route::delete('/contact-groups/{uuid}', [App\Http\Controllers\ApiController::class, 'destroyContactGroup']);
	
	// Route::get('contacts/{uuid}/add-to-group', [App\Http\Controllers\ApiController::class, 'addToGroup']);
	// Route::get('contacts/{uuid}/remove-from-group', [App\Http\Controllers\ApiController::class, 'removeFromGroup']);

	
	Route::post('/send-msg', [App\Http\Controllers\ApiController::class, 'sendMsg']);
    // Route::post('/send-media', [App\Http\Controllers\ApiController::class, 'sendFileMessage']);
    Route::get('/list-templates', [App\Http\Controllers\ApiController::class, 'listTemplates']);
    Route::post('/send-template', [App\Http\Controllers\ApiController::class, 'sendTemplateMessageByUUID']);
	Route::post('/send-auth-template', [App\Http\Controllers\ApiController::class, 'sendAuthTemplate']);
	// Route::get('/list-chat-contacts', [App\Http\Controllers\ApiController::class, 'listChatContacts']); // removed because it is not used in the mobile app
	// Route::get('/list-messages-for-contact/{uuid}', [App\Http\Controllers\ApiController::class, 'listChatContactsForContact']);
	Route::get('/list-messages-from-uuid-to-end', [App\Http\Controllers\ApiController::class, 'listChatMessagesFromUuidToEnd']);
	Route::post('/toggle-ticket-status/{id}', [App\Http\Controllers\ApiController::class, 'toggleTicketStatus']);
	Route::delete('/delete-chat-for-contact/{uuid}', [App\Http\Controllers\ApiController::class, 'deleteChatForContact']);
	// Route::get('/media/signed-url', [App\Http\Controllers\ApiController::class, 'getSignedMediaUrl']);
});

});
Route::post('/broadcasting/auth', function (Request $request) {
    return Broadcast::auth($request);
})->middleware('auth:sanctum'); // أو auth:api
