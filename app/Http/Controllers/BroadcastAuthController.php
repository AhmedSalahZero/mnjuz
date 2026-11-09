<?php

namespace App\Http\Controllers;

use App\Services\Broadcasting\BroadcastProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;

/**
 * المصادقة على قنوات البثّ للداشبورد (/broadcasting/auth) وللتطبيق
 * (/api/broadcasting/auth).
 *
 * التوقيع بسرّ مزوّد المنشأة صاحبة القناة: منشأةٌ على Reverb يتّصل متصفّحها
 * بخادم Reverb، وتوقيعٌ بسرّ Pusher يرفضه فيفشل الاشتراك بلا خطأ ظاهر —
 * تُحفظ الرسائل ولا تصل لحظياً. رقم المنشأة في اسم القناة، والتحقّق من
 * العضوية يبقى في routes/channels.php كما هو.
 */
class BroadcastAuthController extends Controller
{
    public function __invoke(Request $request)
    {
        BroadcastProvider::useForOrganization(
            BroadcastProvider::organizationFromChannel((string) $request->input('channel_name', ''))
        );

        return Broadcast::auth($request);
    }
}
