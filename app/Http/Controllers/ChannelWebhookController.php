<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessChannelWebhookJob;
use App\Models\ChannelAccount;
use App\Services\Broadcasting\BroadcastProvider;
use App\Services\Messaging\Channel;
use App\Services\Messaging\ChannelManager;
use App\Services\SubscriptionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Response;

/**
 * مدخل الـ webhooks للقنوات غير واتساب.
 *
 * واتساب تبقى على WebhookController كما هي — لم نلمسها. الفصل مقصود:
 * مسار واتساب يعمل على الإنتاج منذ سنة، وإدخاله في تجريد جديد يوم واحد
 * مخاطرة بلا مقابل.
 *
 * المسار: /webhook/channel/{channel}/{account?}
 *   - تيليجرام يحتاج {account} لأن تحديثاته لا تحمل معرّف البوت.
 *   - ميتا وتيك توك تُوجَّه بمعرّف داخل الحمولة نفسها.
 *
 * القاعدة هنا: لا عمل ثقيل في الطلب. نتحقّق من التوقيع ونرمي الحمولة في
 * طابور، لأن المنصّات تُعيد الإرسال إن تأخّر الردّ — وتيك توك تفرض خمس
 * ثوانٍ فقط.
 */
class ChannelWebhookController extends Controller
{
    public function __construct(private readonly ChannelManager $channels)
    {
        BroadcastProvider::apply();
    }

    public function handle(Request $request, string $channel, ?string $account = null)
    {
        $channel = Channel::normalize($channel);

        if ($channel === null || $channel === Channel::WHATSAPP) {
            return Response::json(['error' => 'Not found'], 404);
        }

        $driver = $this->channels->driver($channel);
        $channelAccount = $this->resolveAccount($request, $channel, $account);

        // التحقّق الأولي (handshake) يصل قبل أن يكون هناك أي حساب فعّال،
        // فيُعالَج أولاً.
        if ($request->isMethod('get')) {
            $challenge = $driver->webhookChallenge($request, $channelAccount);

            return $challenge !== null
                ? Response::make($challenge, 200)
                : Response::json(['error' => 'Forbidden'], 403);
        }

        if (!$driver->verifyWebhook($request, $channelAccount)) {
            Log::warning('Channel webhook signature rejected', [
                'channel' => $channel,
                'account' => $channelAccount?->id,
                'ip' => $request->ip(),
            ]);

            return Response::json(['error' => 'Invalid signature'], 401);
        }

        if (!$channelAccount) {
            // حمولة صحيحة التوقيع لحساب لا نعرفه: ليست خطأً بالضرورة —
            // قد يكون العميل فصل الحساب للتوّ. 200 يمنع إعادة الإرسال.
            Log::info('Channel webhook for unknown account', ['channel' => $channel]);

            return Response::json(['status' => 'ignored'], 200);
        }

        if (!$channelAccount->isActive()) {
            return Response::json(['status' => 'inactive'], 200);
        }

        if (SubscriptionService::isSubscriptionLimitReachedForInboundMessages($channelAccount->organization_id)) {
            return Response::json(['status' => 'limit_reached'], 200);
        }

        ProcessChannelWebhookJob::dispatch(
            $channelAccount->id,
            $request->all(),
            $this->forwardedHeaders($request),
            $request->getContent()
        )->onQueue('high');

        return Response::json(['status' => 'success'], 200);
    }

    /**
     * الحساب المقصود: من المسار إن وُجد، وإلّا من الحمولة.
     */
    private function resolveAccount(Request $request, string $channel, ?string $accountUuid): ?ChannelAccount
    {
        if ($accountUuid) {
            return ChannelAccount::where('uuid', $accountUuid)
                ->where('channel', $channel)
                ->first();
        }

        $externalId = $this->channels->driver($channel)->resolveAccountExternalId($request);

        return $externalId ? ChannelAccount::findForWebhook($channel, $externalId) : null;
    }

    /**
     * الترويسات التي يحتاجها السائق داخل الطابور للتحقّق مرّة ثانية أو
     * لقراءة بيانات لا توجد في الجسم. لا نُمرّر كل الترويسات: بعضها يحمل
     * أسراراً لا داعي لبقائها في جدول الطوابير.
     */
    private function forwardedHeaders(Request $request): array
    {
        $keep = [
            'x-hub-signature-256',
            'tiktok-signature',
            'x-telegram-bot-api-secret-token',
            'content-type',
        ];

        $headers = [];

        foreach ($keep as $name) {
            $value = $request->header($name);

            if ($value !== null) {
                $headers[$name] = $value;
            }
        }

        return $headers;
    }
}
