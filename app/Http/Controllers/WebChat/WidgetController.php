<?php

namespace App\Http\Controllers\WebChat;

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
use Illuminate\Support\Str;

/**
 * الواجهة العامّة للمحادثة المباشرة — ما يناديه الـ widget من موقع التاجر.
 *
 * لا جلسة ولا كوكي: الـ widget يُعرَّف بمفتاحه في المسار، والزائر بتوكنه في
 * ترويسة X-Webchat-Token. الحرّاس في routes/webchat.php.
 */
class WidgetController extends Controller
{
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
            'realtime' => BroadcastProvider::clientConfig($organizationId),
            'limits' => [
                'text' => SendWebChatMessageRequest::MAX_TEXT,
                'media' => app(\App\Services\Messaging\ChannelManager::class)
                    ->capabilities(\App\Services\Messaging\Channel::WEBCHAT)->mediaLimits,
                'accept' => WebChatUploads::acceptedMimeTypes(),
            ],
        ])->header('Cache-Control', 'public, max-age=60');
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
        ], 201);
    }

    /** حالة الزائر عند كل تحميل صفحة: الشارة وآخر رسالة. */
    public function session(Request $request): JsonResponse
    {
        $visitor = $this->visitor($request);
        $last = $this->baseQuery($visitor)?->orderByDesc('id')->with(['media', 'user'])->first();

        return response()->json([
            'visitor' => $this->visitorView($visitor),
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
            Chat::where('contact_id', $visitor->contact_id)
                ->where('type', 'outbound')
                ->where('id', '<=', $seq)
                ->whereIn('status', ['sent', 'delivered'])
                ->update(['status' => 'read']);
        }

        return response()->json(['unread_count' => $this->unreadCount($visitor)]);
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
