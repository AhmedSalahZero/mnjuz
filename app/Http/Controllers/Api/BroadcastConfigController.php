<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Broadcasting\BroadcastProvider;
use Illuminate\Http\JsonResponse;

/**
 * إعداد البثّ لتطبيق الجوال.
 *
 * الداشبورد يستقبل هذا الإعداد مع كل تحميل صفحة، فيتبع تبديل المزوّد تلقائياً.
 * أمّا التطبيق فمفاتيحه كانت مضمّنة فيه لحظة بنائه — لا يسأل الخادم عنها ولا
 * يعلم بالتبديل. فلو حُوّل الخادم إلى Reverb ظلّ التطبيق يستمع إلى Pusher:
 * الرسائل تُحفظ ولا تصل لحظياً، ويبدو التطبيق بطيئاً لا معطّلاً.
 *
 * بهذا المسار يسأل التطبيق عند كل فتح، فيتبع كل تبديل لاحق بلا إصدار جديد.
 *
 * السرّ لا يخرج هنا: هو ما يوقّع الأحداث من الخادم، ووصوله جهازاً يعني أن
 * حامله يستطيع البثّ باسمنا. المصادقة على القنوات تبقى على /broadcasting/auth.
 */
class BroadcastConfigController extends Controller
{
    public function show(): JsonResponse
    {
        $user = auth()->user();

        // مزوّد المنشأة المختارة في التطبيق، كما يختاره الأدمن لكل منشأة. الشكل
        // نفسه يصل مع كل منشأة في الدخول والتبديل والملف الشخصي (appConfig):
        // host فارغ لسحابة Pusher (تشتقّ عنوانها من التجميعة)، و auth_endpoint
        // خارج بادئة v1 كما سُجّل في routes/api.php.
        return response()->json([
            'statusCode' => 200,
            'success' => true,
            'data' => BroadcastProvider::appConfig(
                (int) $user?->current_mobile_organization_id ?: null,
                $user?->id ? (int) $user->id : null
            ),
        ]);
    }
}
