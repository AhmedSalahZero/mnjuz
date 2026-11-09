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

        // واتساب لها مسارها، والمحادثة المباشرة لا webhook لها أصلاً.
        if ($channel === null || !in_array($channel, Channel::connectable(), true)) {
            return Response::json(['error' => 'Not found'], 404);
        }

        $driver = $this->channels->driver($channel);
        $channelAccount = $this->resolveAccount($request, $channel, $account);

        $this->trace('received', $request, $channel, $channelAccount);

        // التحقّق الأولي (handshake) يصل قبل أن يكون هناك أي حساب فعّال،
        // فيُعالَج أولاً.
        if ($request->isMethod('get')) {
            $challenge = $driver->webhookChallenge($request, $channelAccount);

            $this->trace($challenge !== null ? 'challenge_accepted' : 'challenge_rejected', $request, $channel, $channelAccount);

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
            $this->trace('signature_rejected', $request, $channel, $channelAccount);

            return Response::json(['error' => 'Invalid signature'], 401);
        }

        if (!$channelAccount) {
            // حمولة صحيحة التوقيع لحساب لا نعرفه: ليست خطأً بالضرورة —
            // قد يكون العميل فصل الحساب للتوّ. 200 يمنع إعادة الإرسال.
            Log::info('Channel webhook for unknown account', ['channel' => $channel]);
            $this->trace('unknown_account', $request, $channel, null);

            return Response::json(['status' => 'ignored'], 200);
        }

        if (!$channelAccount->isActive()) {
            $this->trace('account_inactive', $request, $channel, $channelAccount, ['status' => $channelAccount->status]);

            return Response::json(['status' => 'inactive'], 200);
        }

        if (SubscriptionService::isSubscriptionLimitReachedForInboundMessages($channelAccount->organization_id)) {
            $this->trace('limit_reached', $request, $channel, $channelAccount);

            return Response::json(['status' => 'limit_reached'], 200);
        }

        ProcessChannelWebhookJob::dispatch(
            $channelAccount->id,
            $request->all(),
            $this->forwardedHeaders($request),
            $request->getContent()
        )->onQueue('high');

        $this->trace('queued', $request, $channel, $channelAccount);

        return Response::json(['status' => 'success'], 200);
    }

    /**
     * سطر في channel-webhooks.log لكل مرحلة. نسجّل ملخّص الحمولة لا
     * محتواها: نصوص الرسائل بيانات عملاء لا مكان لها في السجلّات.
     */
    private function trace(string $stage, Request $request, string $channel, ?ChannelAccount $account, array $extra = []): void
    {
        try {
            $payload = $request->all();
            $entries = is_array($payload['entry'] ?? null) ? $payload['entry'] : [];

            $events = [];
            foreach ($entries as $entry) {
                foreach ((array) ($entry['messaging'] ?? []) as $item) {
                    foreach (['message', 'postback', 'read', 'delivery', 'reaction', 'referral'] as $kind) {
                        if (isset($item[$kind])) {
                            $events[] = $kind === 'message' && !empty($item['message']['is_echo']) ? 'echo' : $kind;
                        }
                    }
                }
                foreach ((array) ($entry['changes'] ?? []) as $change) {
                    $events[] = 'change:' . ($change['field'] ?? '?');
                }
            }

            Log::channel('channel_webhooks')->info("[{$channel}] {$stage}", array_merge([
                'method' => $request->method(),
                'ip' => $request->ip(),
                'object' => $payload['object'] ?? null,
                'entry_ids' => array_values(array_filter(array_map(fn ($e) => $e['id'] ?? null, $entries))),
                'events' => $events,
                'has_signature' => $request->headers->has('X-Hub-Signature-256')
                    || $request->headers->has('Tiktok-Signature')
                    || $request->headers->has('X-Telegram-Bot-Api-Secret-Token'),
                'account_id' => $account?->id,
                'organization_id' => $account?->organization_id,
            ], $extra));
        } catch (\Throwable) {
            // السجلّ للتشخيص فقط — فشله لا يُسقط استلام الرسالة.
        }
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
