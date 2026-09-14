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

    Route::get('/contact-categories', [App\Http\Controllers\ApiController::class, 'listContactCategories']);
    Route::post('/contact-categories', [App\Http\Controllers\ApiController::class, 'storeContactCategory']);
    Route::put('/contact-categories/{uuid}', [App\Http\Controllers\ApiController::class, 'storeContactCategory']);
    Route::delete('/contact-categories/{uuid}', [App\Http\Controllers\ApiController::class, 'destroyContactCategory']);

    Route::get('/canned-replies', [App\Http\Controllers\ApiController::class, 'listCannedReplies']);
    Route::post('/canned-replies', [App\Http\Controllers\ApiController::class, 'storeCannedReply']);
    Route::put('/canned-replies/{uuid}', [App\Http\Controllers\ApiController::class, 'storeCannedReply']);
    Route::delete('/canned-replies/{uuid}', [App\Http\Controllers\ApiController::class, 'destroyCannedReply']);

    Route::get('/templates', [App\Http\Controllers\ApiController::class, 'listTemplates']);
    Route::get('/verify', [App\Http\Controllers\ApiController::class, 'verifyApiKey']);
	
	});
    
});

Route::prefix('v1')->group(function () {
	
	Route::post('/verification/send', [App\Http\Controllers\VerificationController::class, 'requestCode'])->middleware('throttle:6,1');
	Route::post('/verification/confirm', [App\Http\Controllers\VerificationController::class, 'confirmCode'])->middleware('throttle:10,1');


// Public routes (no authentication required)
Route::prefix('auth')->group(function () {
    Route::post('login', [App\Http\Controllers\AuthController::class, 'login']);
    Route::post('tfa/verify', [App\Http\Controllers\AuthController::class, 'tfaVerify']);
});

// Protected routes (require authentication via Sanctum)

Route::middleware(['auth:sanctum'])->prefix('auth')->group(function () {
    Route::post('logout', [App\Http\Controllers\AuthController::class, 'logout']);
    Route::post('set-current-organization', [App\Http\Controllers\AuthController::class, 'setCurrentOrganization']);
    Route::post('leave-current-organization', [App\Http\Controllers\AuthController::class, 'leaveCurrentOrganization']);
    Route::post('delete-account', [App\Http\Controllers\AuthController::class, 'deleteAccount']);
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

	Route::get('/contact-categories', [App\Http\Controllers\ApiController::class, 'listContactCategories']);
	Route::post('/contact-categories', [App\Http\Controllers\ApiController::class, 'storeContactCategory']);
	Route::put('/contact-categories/{uuid}', [App\Http\Controllers\ApiController::class, 'storeContactCategory']);
	Route::delete('/contact-categories/{uuid}', [App\Http\Controllers\ApiController::class, 'destroyContactCategory']);
	
	// Route::get('contacts/{uuid}/add-to-group', [App\Http\Controllers\ApiController::class, 'addToGroup']);
	// Route::get('contacts/{uuid}/remove-from-group', [App\Http\Controllers\ApiController::class, 'removeFromGroup']);

	
	Route::post('/send-msg', [App\Http\Controllers\ApiController::class, 'sendMsg']);
    // نقطة الملفات المستقلّة معطّلة عمداً: /send-msg يقبل الملف والملفات
    // والنصّ معاً، ونقطتان لغرض واحد تتفرّقان عند أول تعديل. المعالج نفسه
    // باقٍ ويُستدعى من sendMsg.
    // Route::post('/send-media', [App\Http\Controllers\ApiController::class, 'sendFileMessage']);
    // الملف الكبير على قطع: الوكيل الأمامي يقطع أي طلب تجاوز ١٢٥ ثانية، فطلبٌ
    // واحد بملفٍ كبير على شبكة جوال يموت دائماً بلا رسالة. نظيرة مسار الويب
    // /chats/upload/chunk، والمنشأة من التوكن لا من الجلسة.
    Route::post('/chats/upload/chunk', [App\Http\Controllers\Api\ChunkedUploadController::class, 'store']);
    Route::delete('/chats/upload/chunk', [App\Http\Controllers\Api\ChunkedUploadController::class, 'destroy']);
    Route::get('/list-templates', [App\Http\Controllers\ApiController::class, 'listTemplates']);
    Route::post('/send-template', [App\Http\Controllers\ApiController::class, 'sendTemplateMessageByUUID']);
	Route::post('/send-auth-template', [App\Http\Controllers\ApiController::class, 'sendAuthTemplate']);
	// Route::get('/list-chat-contacts', [App\Http\Controllers\ApiController::class, 'listChatContacts']); // removed because it is not used in the mobile app
	// Route::get('/list-messages-for-contact/{uuid}', [App\Http\Controllers\ApiController::class, 'listChatContactsForContact']);
	Route::get('/list-messages-from-uuid-to-end', [App\Http\Controllers\ApiController::class, 'listChatMessagesFromUuidToEnd']);
	// نفس البيانات بلا تكرار جهة الاتصال في كل رسالة. v1 باقٍ حتى ينتقل التطبيق.
	Route::get('/list-messages-from-uuid-to-end-v2', [App\Http\Controllers\ApiController::class, 'listChatMessagesFromUuidToEndV2']);
	Route::delete('/delete-chat-for-contact/{uuid}', [App\Http\Controllers\ApiController::class, 'deleteChatForContact']);
	// Route::get('/media/signed-url', [App\Http\Controllers\ApiController::class, 'getSignedMediaUrl']);
	Route::post('/toggle-ticket-status/{id}', [App\Http\Controllers\ApiController::class, 'toggleTicketStatus']);
	Route::post('assign-ticket',[App\Http\Controllers\ApiController::class, 'assignContactToUserThroughTicket']);
	Route::post('mark-as-read',[App\Http\Controllers\ApiController::class, 'markAsRead']);
	
	Route::post('/request-location', [App\Http\Controllers\ApiController::class, 'requestLocation']);
	Route::post('/send-location', [App\Http\Controllers\ApiController::class, 'sendLocation']);
	Route::get('/organization-location', [App\Http\Controllers\ApiController::class, 'organizationLocation']);
	Route::post('/performance/heartbeat', [App\Http\Controllers\ApiController::class, 'performanceHeartbeat']);
	Route::get('/list-teams', [App\Http\Controllers\ApiController::class, 'listTeamMembers']);

	/*
	|--------------------------------------------------------------------------
	| نقاط التطبيق: التقارير والتذاكر والملف الشخصي والإعدادات والأتمتة
	|--------------------------------------------------------------------------
	| ما كان يفعله المستخدم في الويب وحده. المنشأة تأتي من التوكن لا من
	| الجلسة، ومسارات الـ API بلا جلسة أصلاً.
	*/

	// التقارير — للمالك والمدير
	Route::get('/reports/agent-performance', [App\Http\Controllers\Api\MobileReportController::class, 'agentPerformance']);
	Route::get('/reports/ratings', [App\Http\Controllers\Api\MobileReportController::class, 'ratings']);
	Route::delete('/reports/ratings/{uuid}', [App\Http\Controllers\Api\MobileReportController::class, 'deleteRating']);
	Route::get('/reports/activity-log', [App\Http\Controllers\Api\MobileReportController::class, 'activityLog']);

	// تذاكر الدعم — التغيير والإسناد لهما نقطتاهما أدناه منذ إصدار سابق
	Route::get('/tickets', [App\Http\Controllers\Api\MobileTicketController::class, 'index']);
	Route::get('/tickets/summary', [App\Http\Controllers\Api\MobileTicketController::class, 'summary']);

	// الملف الشخصي
	Route::get('/profile', [App\Http\Controllers\Api\MobileProfileController::class, 'show']);
	Route::put('/profile', [App\Http\Controllers\Api\MobileProfileController::class, 'update']);
	Route::put('/profile/password', [App\Http\Controllers\Api\MobileProfileController::class, 'updatePassword']);

	// الإعدادات العامّة وأوقات العمل
	Route::get('/settings/general', [App\Http\Controllers\Api\MobileSettingController::class, 'general']);
	Route::post('/settings/general', [App\Http\Controllers\Api\MobileSettingController::class, 'updateGeneral']);
	Route::get('/settings/working-hours', [App\Http\Controllers\Api\MobileSettingController::class, 'workingHours']);
	Route::post('/settings/working-hours', [App\Http\Controllers\Api\MobileSettingController::class, 'updateWorkingHours']);

	// الأتمتة الأساسية (الردود الجاهزة)
	Route::get('/automation/basic', [App\Http\Controllers\Api\MobileAutomationController::class, 'index']);
	Route::post('/automation/basic', [App\Http\Controllers\Api\MobileAutomationController::class, 'store']);
	Route::get('/automation/basic/{uuid}', [App\Http\Controllers\Api\MobileAutomationController::class, 'show']);
	Route::put('/automation/basic/{uuid}', [App\Http\Controllers\Api\MobileAutomationController::class, 'update']);
	Route::delete('/automation/basic/{uuid}', [App\Http\Controllers\Api\MobileAutomationController::class, 'destroy']);

	// إعداد البثّ: يسأله التطبيق عند كل فتح فيتبع تبديل المزوّد بلا إصدار جديد.
	Route::get('/broadcast-config', [App\Http\Controllers\Api\BroadcastConfigController::class, 'show']);

	Route::get('/shortcuts/available', [App\Http\Controllers\ApiController::class, 'listShortcutsAvailable']);
	Route::get('/shortcuts', [App\Http\Controllers\ApiController::class, 'listShortcuts']);
	Route::post('/shortcuts', [App\Http\Controllers\ApiController::class, 'storeShortcut']);
	Route::put('/shortcuts/{uuid}', [App\Http\Controllers\ApiController::class, 'updateShortcut']);
	Route::delete('/shortcuts/{uuid}', [App\Http\Controllers\ApiController::class, 'destroyShortcut']);

	
});

});

Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/user/device', [App\Http\Controllers\UserDeviceController::class, 'show']);
    Route::delete('/user/device', [App\Http\Controllers\UserDeviceController::class, 'destroy']);
});

Route::middleware(['auth:sanctum', 'check.active.organization', 'check.has.selected.organization'])->prefix('payments/myfatoorah')->group(function () {
    Route::post('/init', [App\Http\Controllers\Api\MyFatoorahPaymentController::class, 'initialize']);
    Route::get('/status/{paymentId}', [App\Http\Controllers\Api\MyFatoorahPaymentController::class, 'status']);
});

Route::post('/broadcasting/auth', function (Request $request) {
    return Broadcast::auth($request);
})->middleware('auth:sanctum'); // أو auth:api
