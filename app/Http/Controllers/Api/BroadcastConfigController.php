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
        $config = BroadcastProvider::clientConfig();

        return response()->json([
            'statusCode' => 200,
            'success' => true,
            'data' => [
                'provider' => $config['provider'],
                'key' => $config['key'],
                // Pusher السحابي يشتقّ عنوانه من التجميعة؛ عنوان مُخترَع يكسر
                // الاتصال. null هنا تعني «استعمل سلوكك الافتراضي».
                'host' => $config['host'],
                'port' => $config['port'],
                'scheme' => $config['scheme'],
                'force_tls' => $config['force_tls'],
                'cluster' => $config['cluster'],
                'auth_endpoint' => url('/api/v1/broadcasting/auth'),
            ],
        ]);
    }
}
