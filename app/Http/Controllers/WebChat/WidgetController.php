<?php

namespace App\Http\Controllers\WebChat;

use App\Events\NewChatEvent;
use App\Events\WebChatVisitorEvent;
use App\Http\Controllers\Controller;
use App\Http\Middleware\WebChat\AuthenticateWebChatVisitor;
use App\Http\Middleware\WebChat\ResolveWebChatWidget;
use App\Http\Requests\WebChat\SendWebChatMessageRequest;
use App\Http\Requests\WebChat\StartWebChatSessionRequest;
use App\Http\Requests\WebChat\UploadWebChatFileRequest;
use App\Models\ChannelAccount;
use App\Models\Chat;
use App\Models\WebChatVisitor;
use App\Services\Broadcasting\BroadcastProvider;
use App\Services\Messaging\Drivers\WebChatDriver;
use App\Services\WebChat\WebChatInboundService;
use App\Services\WebChat\WebChatMessagePresenter;
use App\Services\WebChat\WebChatSettings;
use App\Services\WebChat\WebChatUploads;
use App\Services\WebChat\WebChatWidgetService;
use App\Services\WorkingHoursService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * الواجهة العامّة للمحادثة المباشرة — ما يناديه الـ widget من موقع التاجر.
 *
 * لا جلسة ولا كوكي: الـ widget يُعرَّف بمفتاحه في المسار، والزائر بتوكنه في
 * ترويسة X-Webchat-Token. الحرّاس في routes/webchat.php.
 */
class WidgetController extends Controller
{
    /** أقصى ما يُبثّ للموظّف من صحّ القراءة في المرّة الواحدة. */
    private const READ_BROADCAST_LIMIT = 20;

    public function __construct(private readonly WebChatInboundService $inbound)
    {
    }

    /** ما يرسم به الـ widget نفسه. يُخزَّن دقيقةً عند المتصفّح. */
    public function config(Request $request): JsonResponse
    {
        $account = $this->widget($request);
        $organizationId = (int) $account->organization_id;

        return response()->json([
            'widget' => WebChatSettings::publicView($account),
            'available' => WebChatWidgetService::acceptsInbound($organizationId),
            'open_now' => !WorkingHoursService::isOutsideConfiguredHours($organizationId),
            // بثّ معطَّل (log/null) لا يُعطى الـ widget إعداداً لا يتّصل بشيء:
            // null تعني «اكتفِ بالمزامنة».
            'realtime' => self::realtimeEnabled() ? BroadcastProvider::clientConfig($organizationId) : null,
            // الواجهة الكاملة يُحمّلها الـ loader عند أول ضغطة، برابط فيه بصمة البناء.
            'bundle' => \App\Services\WebChat\WebChatAssets::bundleUrl(),
            'limits' => [
                'text' => SendWebChatMessageRequest::MAX_TEXT,
                // الحدّ الفعلي (حدّ النوع مقصوصاً بحدّ PHP): ما يُعلَن هو ما يُقبل،
                // فيُرفض الملف الكبير في المتصفّح برسالة لا بعد رفعه.
                'media' => WebChatUploads::limits(),
                'accept' => WebChatUploads::acceptedMimeTypes(),
                'files_per_send' => WebChatUploads::MAX_FILES_PER_SEND,
            ],
        ])->header('Cache-Control', ResolveWebChatWidget::isPreview($request) ? 'no-store' : 'public, max-age=60')
            // الردّ نفسه يختلف قبولاً ورفضاً بحسب الموقع: لا يُخزَّن لموقعٍ ويُقدَّم لآخر.
            ->header('Vary', 'Origin');
    }

    /**
     * توقيع قناة الزائر الخاصّة بسرّ مزوّد منشأته.
     *
     * Broadcast::auth لا يصلح هنا: يرفض كل قناة خاصّة لمن لا مستخدم له.
     * والقناة الوحيدة المسموحة قناة هذا الزائر نفسه — تُقارَن حرفاً بحرف.
     */
    public function broadcastAuth(Request $request)
    {
        $visitor = $this->visitor($request);
        $organizationId = (int) $visitor->organization_id;
        $socketId = (string) $request->input('socket_id', '');
        $expected = 'private-' . WebChatVisitorEvent::channelName($organizationId, (string) $visitor->uuid);

        if (!preg_match('/^\d{1,20}\.\d{1,20}$/', $socketId)
            || !hash_equals($expected, (string) $request->input('channel_name', ''))
        ) {
            return response()->json(['error' => 'forbidden'], 403);
        }

        if (!self::realtimeEnabled()) {
            return response()->json(['error' => 'unavailable'], 403);
        }

        try {
            BroadcastProvider::apply();
            $signature = Broadcast::connection(BroadcastProvider::connectionNameForOrganization($organizationId))
                ->getPusher()
                ->authorizeChannel($expected, $socketId);
        } catch (\Throwable $e) {
            Log::warning('Web chat: channel authorization failed', [
                'organization_id' => $organizationId,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['error' => 'unavailable'], 403);
        }

        return response($signature, 200, ['Content-Type' => 'application/json']);
    }

    /** هل البثّ اللحظي يعمل أصلاً؟ log وnull مقبولان في الإعداد ولا يتّصلان بشيء. */
    private static function realtimeEnabled(): bool
    {
        $default = (string) config('broadcasting.default');

        return config("broadcasting.connections.{$default}.driver") === 'pusher';
    }

    /**
     * النموذج: زائر جديد وتوكنه. التوكن يُعاد هنا مرّة واحدة ولا يُخزَّن منه
     * إلا بصمته — من فقده بدأ محادثة جديدة.
     */
    public function startSession(StartWebChatSessionRequest $request): JsonResponse
    {
        $account = $request->widget();
        $token = WebChatVisitor::newToken();
        $pageUrl = $this->pageUrl($request->input('page_url'));

        $visitor = WebChatVisitor::create([
            'organization_id' => $account->organization_id,
            'channel_account_id' => $account->id,
            'token_hash' => WebChatVisitor::hashToken($token),
            'name' => $request->visitorName(),
            'phone' => $request->e164(),
            'email' => $request->emailOrNull(),
            'locale' => mb_substr((string) $request->input('locale', ''), 0, 10) ?: null,
            'first_page_url' => $pageUrl,
            'last_page_url' => $pageUrl,
            'referrer' => $this->pageUrl($request->input('referrer')),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 512) ?: null,
            'ip' => WebChatVisitor::truncateIp($request->ip()),
            'country' => $this->country($request),
            'last_seen_at' => now(),
        ]);

        return response()->json([
            'token' => $token,
            'visitor' => $this->visitorView($visitor),
            'channel' => $this->channelFor($visitor),
        ], 201);
    }

    /** حالة الزائر عند كل تحميل صفحة: الشارة وآخر رسالة. */
    public function session(Request $request): JsonResponse
    {
        $visitor = $this->visitor($request);
        $last = $this->baseQuery($visitor)?->orderByDesc('id')->with(['media', 'user'])->first();

        return response()->json([
            'visitor' => $this->visitorView($visitor),
            'channel' => $this->channelFor($visitor),
            'unread_count' => $this->unreadCount($visitor),
            'last_message' => $last ? WebChatMessagePresenter::forVisitor($visitor)->present($last) : null,
        ]);
    }

    /**
     * سجلّ المحادثة. after = ما بعد رسالة (للمزامنة بعد انقطاع)، before = ما
     * قبلها (للتمرير إلى الأعلى)، وبلا أيّهما آخر الرسائل.
     */
    public function messages(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'after' => ['nullable', 'integer', 'min:0'],
            'before' => ['nullable', 'integer', 'min:1'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $visitor = $this->visitor($request);
        $limit = (int) ($validated['limit'] ?? 30);
        $query = $this->baseQuery($visitor);

        if (!$query) {
            return response()->json(['messages' => [], 'has_more' => false]);
        }

        $query->with(['media', 'user']);

        if (isset($validated['after'])) {
            $rows = $query->where('id', '>', (int) $validated['after'])->orderBy('id')->limit($limit + 1)->get();
            $hasMore = $rows->count() > $limit;
            $rows = $rows->take($limit);
        } else {
            if (isset($validated['before'])) {
                $query->where('id', '<', (int) $validated['before']);
            }

            $rows = $query->orderByDesc('id')->limit($limit + 1)->get();
            $hasMore = $rows->count() > $limit;
            $rows = $rows->take($limit)->reverse()->values();
        }

        return response()->json([
            'messages' => WebChatMessagePresenter::forVisitor($visitor)->presentMany($rows),
            'has_more' => $hasMore,
        ]);
    }

    /** رسالة من الزائر. 202: تُسجَّل في الطابور، وتصل عبر البثّ أو المزامنة. */
    public function send(SendWebChatMessageRequest $request): JsonResponse
    {
        $account = $request->widget();
        $visitor = $request->visitor();
        $this->touchPage($visitor, $request->input('page_url'));

        if ($request->input('type') === 'interactive') {
            $accepted = $this->inbound->dispatchInteractiveReply(
                $account,
                $visitor,
                $request->clientMessageId(),
                (string) $request->input('reply.message_id'),
                (string) $request->input('reply.option_id')
            );

            if (!$accepted) {
                return response()->json(['error' => 'option_not_found'], 422);
            }
        } else {
            $text = $request->messageText();

            if ($text === '') {
                return response()->json([
                    'error' => 'validation_failed',
                    'errors' => ['text' => [__('Message cannot be empty.')]],
                ], 422);
            }

            $this->inbound->dispatchText($account, $visitor, $request->clientMessageId(), $text);
        }

        return response()->json([
            'client_message_id' => $request->clientMessageId(),
            'status' => 'pending',
        ], 202);
    }

    /** ملف من الزائر: يُحفظ مؤقّتاً خارج public، وتنقله مهمّة الوسائط. */
    public function upload(UploadWebChatFileRequest $request): JsonResponse
    {
        $account = $request->widget();

        if (!WebChatSettings::for($account)['behavior']['uploads']) {
            return response()->json(['error' => 'uploads_disabled'], 403);
        }

        $file = $request->classified();
        $path = $request->file('file')->storeAs(
            WebChatDriver::tempDirectory($account),
            strtolower((string) Str::ulid()) . '.' . $file['extension'],
            WebChatDriver::TEMP_DISK
        );

        if ($path === false) {
            return response()->json(['error' => 'upload_failed'], 500);
        }

        $this->inbound->dispatchMedia($account, $request->visitor(), $request->clientMessageId(), $file + ['temp_path' => $path], $request->caption());

        return response()->json([
            'client_message_id' => $request->clientMessageId(),
            'status' => 'pending',
        ], 202);
    }

    /**
     * علامة القراءة: آخر رسالة رآها الزائر. لا ترجع إلى الوراء، ولا تتجاوز
     * آخر رسالة في محادثته — فلا يُعلَّم ما لم يصل بعد.
     */
    public function markRead(Request $request): JsonResponse
    {
        $validated = $request->validate(['seq' => ['required', 'integer', 'min:1']]);
        $visitor = $this->visitor($request);
        $query = $this->baseQuery($visitor);

        if (!$query) {
            return response()->json(['unread_count' => 0]);
        }

        $latest = (int) (clone $query)->max('id');
        $seq = min((int) $validated['seq'], $latest);

        if ($seq > (int) $visitor->last_read_chat_id) {
            $visitor->forceFill(['last_read_chat_id' => $seq])->saveQuietly();

            // صحّ القراءة للموظّف: الرسائل الصادرة حتى هذه العلامة صارت مقروءة.
            $unread = Chat::where('contact_id', $visitor->contact_id)
                ->where('type', 'outbound')
                ->where('id', '<=', $seq)
                ->whereIn('status', ['sent', 'delivered']);

            $changed = (clone $unread)->orderByDesc('id')->limit(self::READ_BROADCAST_LIMIT)->pluck('id')->all();
            $unread->update(['status' => 'read']);

            if ($changed !== []) {
                $organizationId = (int) $visitor->organization_id;

                // بعد الردّ: كل بثّ يبني حمولته باستعلامات، والزائر لا ينتظرها.
                dispatch(static fn () => self::broadcastReadStatuses($organizationId, $changed))->afterResponse();
            }
        }

        return response()->json(['unread_count' => $this->unreadCount($visitor)]);
    }

    /**
     * صحّ القراءة يصل الموظّف لحظياً، بنفس بثّ حالة واتساب (statusChanged).
     * الأحدث وحده: الأقدم لا تراه الواجهة غالباً، والمزامنة تستدركه.
     *
     * @param  array<int, int>  $chatIds
     */
    public static function broadcastReadStatuses(int $organizationId, array $chatIds): void
    {
        foreach (Chat::with('chatLog')->whereIn('id', $chatIds)->get() as $chat) {
            if (!$chat->chatLog) {
                continue;
            }

            try {
                event(new NewChatEvent([[
                    'type' => 'chat',
                    'value' => $chat->chatLog->relatedEntities,
                    'tempMessageId' => $chat->wam_id,
                ]], $organizationId, false, true));
            } catch (\Throwable $e) {
                Log::warning('Web chat: read status broadcast failed', ['chat_id' => $chat->id, 'error' => $e->getMessage()]);
            }
        }
    }

    /* ───────────────────────── مساعدات ───────────────────────── */

    private function widget(Request $request): ChannelAccount
    {
        return $request->attributes->get(ResolveWebChatWidget::ATTRIBUTE);
    }

    private function visitor(Request $request): WebChatVisitor
    {
        return $request->attributes->get(AuthenticateWebChatVisitor::ATTRIBUTE);
    }

    /** رسائل محادثة الزائر، أو null إن لم يكتب بعد. */
    private function baseQuery(WebChatVisitor $visitor)
    {
        if (!$visitor->contact_id) {
            return null;
        }

        return Chat::query()
            ->where('contact_id', $visitor->contact_id)
            ->whereIn('type', ['inbound', 'outbound'])
            ->whereNull('deleted_at');
    }

    private function unreadCount(WebChatVisitor $visitor): int
    {
        $query = $this->baseQuery($visitor);

        return $query
            ? $query->where('type', 'outbound')->where('id', '>', (int) $visitor->last_read_chat_id)->count()
            : 0;
    }

    /** قناة الزائر الخاصّة كما يشترك بها pusher-js — أو null إن كان البثّ معطّلاً. */
    private function channelFor(WebChatVisitor $visitor): ?string
    {
        return self::realtimeEnabled()
            ? 'private-' . WebChatVisitorEvent::channelName((int) $visitor->organization_id, (string) $visitor->uuid)
            : null;
    }

    private function visitorView(WebChatVisitor $visitor): array
    {
        return [
            'id' => $visitor->uuid,
            'name' => $visitor->name,
        ];
    }

    private function touchPage(WebChatVisitor $visitor, mixed $pageUrl): void
    {
        $pageUrl = $this->pageUrl($pageUrl);

        if ($pageUrl !== null && $pageUrl !== $visitor->last_page_url) {
            $visitor->forceFill(['last_page_url' => $pageUrl])->saveQuietly();
        }
    }

    /** رابط صفحة الزائر: http(s) وحدها، فالموظّف قد يضغطه. */
    private function pageUrl(mixed $value): ?string
    {
        return WebChatMessagePresenter::safeUrl(mb_substr((string) $value, 0, 2048));
    }

    /** الدولة من Cloudflare إن كان أمامنا. لا نخمّنها من الـ IP. */
    private function country(Request $request): ?string
    {
        $country = strtoupper((string) $request->headers->get('CF-IPCountry', ''));

        return preg_match('/^[A-Z]{2}$/', $country) && $country !== 'XX' ? $country : null;
    }
}
