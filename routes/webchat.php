<?php

use App\Http\Controllers\WebChat\WidgetController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| المحادثة المباشرة — الواجهة العامّة للـ widget
|--------------------------------------------------------------------------
|
| تحت /api/webchat/v1 فيشملها إعداد CORS القائم (api/*)، لكن بمجموعة
| وسطاء خاصّة لا مجموعة api: تلك تحدّ ٦٠ طلباً في الدقيقة لكل IP، وتفترض
| منشأةً في الجلسة، وتمرّ على حرّاس sanctum. هنا لا جلسة ولا مستخدم.
|
| الترتيب: الـ widget أولاً (404 لأي مفتاح غير صالح)، ثم الموقع، ثم الحدّ
| من الطلبات، ثم الزائر.
*/

Route::prefix('w/{key}')
    ->middleware(['webchat.widget', 'webchat.origin'])
    ->group(function () {
        Route::get('config', [WidgetController::class, 'config'])
            ->middleware('throttle:webchat-config');

        Route::post('sessions', [WidgetController::class, 'startSession'])
            ->middleware(['throttle:webchat-session', 'webchat.inbound']);

        Route::middleware('webchat.visitor')->group(function () {
            Route::get('session', [WidgetController::class, 'session'])
                ->middleware('throttle:webchat-read');
            Route::get('messages', [WidgetController::class, 'messages'])
                ->middleware('throttle:webchat-read');
            Route::post('messages', [WidgetController::class, 'send'])
                ->middleware(['throttle:webchat-message', 'webchat.inbound']);
            Route::post('uploads', [WidgetController::class, 'upload'])
                ->middleware(['throttle:webchat-upload', 'webchat.inbound']);
            Route::post('read', [WidgetController::class, 'markRead'])
                ->middleware('throttle:webchat-read');
        });
    });
