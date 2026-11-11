<?php

namespace Tests\Feature\ConversationCycle;

use App\Events\NewChatEvent;
use App\Events\WebChatVisitorEvent;
use App\Models\AutoReply;
use App\Models\ChannelAccount;
use App\Models\Chat;
use App\Models\ChatTicket;
use App\Models\Contact;
use App\Models\Setting;
use App\Models\Subscription;
use App\Services\Messaging\Channel;
use App\Services\WebChat\WebChatWidgetService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\FlowBuilder\FlowBuilderTestCase;

/**
 * دورة المحادثة من أبوابها الحقيقية، على كل قناة.
 *
 * العميل يكتب عبر ما يكتب منه فعلاً: webhook واتساب من Meta، وwebhook
 * تيليجرام بترويسته السرّية، وواجهة الـ widget للمحادثة المباشرة. والموظّف
 * يردّ من صندوق الوارد (POST /chats) ويُغلق من رأس المحادثة. الطابور متزامن
 * (phpunit.xml)، فكل وظيفة تقع في الطريق تعمل فعلاً — لا شيء مُستبدل إلا
 * الشبكة الخارجية: Graph وتيليجرام.
 *
 * ما تراه المنصّات محفوظ هنا: طلبات Graph في graphRequests، وطلبات
 * تيليجرام في Http::recorded()، وما يصل لمتصفّح الزائر في WebChatVisitorEvent.
 */
abstract class ConversationCycleTestCase extends FlowBuilderTestCase
{
    public const CHANNELS = [Channel::WHATSAPP, Channel::TELEGRAM, Channel::WEBCHAT];

    protected const WHATSAPP_FROM = '966500000001';
    protected const TELEGRAM_CHAT = 123456789;
    protected const TELEGRAM_SECRET = 's3cr3t';

    protected string $identifier;
    protected ChannelAccount $bot;
    protected ChannelAccount $widget;
    protected ?string $webToken = null;
    private int $telegramUpdate = 900000;

    /** @return array<string, array{0: string}> */
    public static function channels(): array
    {
        return array_combine(self::CHANNELS, array_map(fn ($c) => [$c], self::CHANNELS));
    }

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        // قائمة محدّدة: fake() العامّة تُعطّل مولّد uuid في الموديلات.
        Event::fake([NewChatEvent::class, WebChatVisitorEvent::class]);

        $sent = 500;
        Http::fake([
            'api.telegram.org/*' => function () use (&$sent) {
                return Http::response(['ok' => true, 'result' => ['message_id' => ++$sent]], 200);
            },
        ]);

        $this->identifier = (string) Str::uuid();
        $this->organization->forceFill(['identifier' => $this->identifier])->save();
        Setting::create(['key' => 'whatsapp_callback_token', 'value' => 'verify-token']);

        Subscription::where('organization_id', $this->organization->id)->first()->plan->update([
            'metadata' => json_encode([
                'addons' => ['Flow builder' => true, 'Web Chat' => true],
                'message_limit' => -1,
            ]),
        ]);

        $this->settings([
            'tickets' => ['active' => true, 'assignment_mode' => 'manual', 'auto_assignment' => false, 'allow_agents_to_view_all_chats' => true],
            'ratings' => ['active' => true],
        ]);

        $this->widget = app(WebChatWidgetService::class)->ensureForOrganization($this->organization->id, 'متجر الاختبار');
        $this->bot = ChannelAccount::create([
            'organization_id' => $this->organization->id,
            'channel' => Channel::TELEGRAM,
            'external_id' => '7654321',
            'name' => 'بوت الدعم',
            'username' => 'support_bot',
            // سُجّل بالتحديثات الحالية: لا إعادة تسجيل webhook تزاحم ما نفحصه.
            'credentials' => ['bot_token' => '7654321:AAF-test', 'webhook_secret' => self::TELEGRAM_SECRET, 'webhook_updates' => 'callback_query'],
            'status' => ChannelAccount::STATUS_ACTIVE,
            'created_by' => $this->owner->id,
        ]);
    }

    /** يدمج في metadata المنشأة ويُسقط نسختها المخزّنة عند وظيفة التذاكر. */
    protected function settings(array $merge): void
    {
        $metadata = json_decode($this->organization->fresh()->metadata, true) ?: [];
        $this->organization->forceFill(['metadata' => json_encode(array_replace_recursive($metadata, $merge))])->save();
        Cache::forget("org_settings_{$this->organization->id}");
    }

    // ───────────────────── العميل ─────────────────────

    /**
     * رسالة نصّية من العميل. $id معرّفها عند المنصّة (wamid، رقم رسالة
     * تيليجرام، client_message_id للـ widget): تمريره مرّتين = إعادة إرسال.
     */
    protected function customerWrites(string $channel, string $text, ?string $id = null): void
    {
        match ($channel) {
            Channel::WHATSAPP => $this->whatsappWebhook([
                'contacts' => [['profile' => ['name' => 'ميثاء'], 'wa_id' => self::WHATSAPP_FROM]],
                'messages' => [$this->whatsappMessage(['type' => 'text', 'text' => ['body' => $text]], $id)],
            ]),
            Channel::TELEGRAM => $this->telegramWebhook([
                'update_id' => $id !== null ? (int) $id : ++$this->telegramUpdate,
                'message' => [
                    'message_id' => $id !== null ? (int) $id : $this->telegramUpdate,
                    'date' => now()->timestamp,
                    'chat' => ['id' => self::TELEGRAM_CHAT, 'type' => 'private'],
                    'from' => ['id' => self::TELEGRAM_CHAT, 'is_bot' => false, 'first_name' => 'أحمد', 'last_name' => 'سالم'],
                    'text' => $text,
                ],
            ]),
            Channel::WEBCHAT => $this->widgetSend(['text' => $text, 'client_message_id' => $id ?? (string) Str::uuid()]),
        };
    }

    /** ضغطة زرّ أو صفّ قائمة على آخر ما عُرض على العميل. */
    protected function customerPresses(string $channel, string $title): void
    {
        match ($channel) {
            Channel::WHATSAPP => $this->whatsappWebhook([
                'contacts' => [['profile' => ['name' => 'ميثاء'], 'wa_id' => self::WHATSAPP_FROM]],
                'messages' => [$this->whatsappMessage([
                    'type' => 'interactive',
                    'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => 'btn-' . Str::random(4), 'title' => $title]],
                ])],
            ]),
            Channel::TELEGRAM => $this->telegramWebhook([
                'update_id' => ++$this->telegramUpdate,
                'callback_query' => [
                    'id' => 'cb' . $this->telegramUpdate,
                    'from' => ['id' => self::TELEGRAM_CHAT, 'is_bot' => false, 'first_name' => 'أحمد'],
                    'message' => [
                        'message_id' => 600,
                        'date' => now()->timestamp,
                        'chat' => ['id' => self::TELEGRAM_CHAT, 'type' => 'private'],
                        'text' => 'اختر',
                        'reply_markup' => ['inline_keyboard' => [[['text' => $title, 'callback_data' => 'b:' . $title]]]],
                    ],
                    'chat_instance' => '-1',
                    'data' => 'b:' . $title,
                ],
            ]),
            Channel::WEBCHAT => $this->widgetPress($title),
        };
    }

    protected function customer(string $channel): Contact
    {
        return match ($channel) {
            Channel::WHATSAPP => $this->contact->fresh(),
            Channel::TELEGRAM => Contact::where('organization_id', $this->organization->id)
                ->where('channel', Channel::TELEGRAM)
                ->where('channel_identifier', (string) self::TELEGRAM_CHAT)
                ->firstOrFail(),
            Channel::WEBCHAT => Contact::where('organization_id', $this->organization->id)
                ->where('channel', Channel::WEBCHAT)
                ->firstOrFail(),
        };
    }

    private function whatsappMessage(array $content, ?string $id = null): array
    {
        return [
            'from' => self::WHATSAPP_FROM,
            'id' => $id ?? 'wamid.IN-' . Str::random(20),
            'timestamp' => (string) now()->timestamp,
        ] + $content;
    }

    private function whatsappWebhook(array $value): void
    {
        $this->postJson('/webhook/whatsapp/' . $this->identifier, [
            'object' => 'whatsapp_business_account',
            'entry' => [['id' => '123456', 'changes' => [['field' => 'messages', 'value' => $value]]]],
        ])->assertOk();
    }

    protected function whatsappStatus(string $wamId, string $status): void
    {
        $this->whatsappWebhook(['statuses' => [[
            'id' => $wamId,
            'status' => $status,
            'timestamp' => (string) now()->timestamp,
            'recipient_id' => self::WHATSAPP_FROM,
        ]]]);
    }

    protected function telegramWebhook(array $update, ?string $secret = self::TELEGRAM_SECRET): TestResponse
    {
        $headers = $secret === null ? [] : ['X-Telegram-Bot-Api-Secret-Token' => $secret];

        return $this->postJson('/webhook/channel/telegram/' . $this->bot->uuid, $update, $headers)->assertOk();
    }

    private function widgetToken(): string
    {
        if ($this->webToken === null) {
            $this->webToken = $this->postJson($this->widgetUrl('sessions'), [
                'name' => 'سارة',
                'phone' => '+966 50 123 4567',
                'elapsed_ms' => 4000,
                'page_url' => 'https://shop.example.com/products/1',
            ])->assertCreated()->json('token');
        }

        return $this->webToken;
    }

    protected function widgetUrl(string $path): string
    {
        return '/api/webchat/v1/w/' . $this->widget->external_id . '/' . $path;
    }

    private function widgetSend(array $body): void
    {
        $this->postJson($this->widgetUrl('messages'), $body + [
            'client_message_id' => (string) Str::uuid(),
            'type' => 'text',
        ], ['X-Webchat-Token' => $this->widgetToken()])->assertStatus(202);
    }

    private function widgetPress(string $title): void
    {
        $offer = Chat::where('contact_id', $this->customer(Channel::WEBCHAT)->id)
            ->where('type', 'outbound')
            ->orderByDesc('id')
            ->get()
            ->first(fn (Chat $chat) => isset(json_decode($chat->metadata, true)['interactive_options']));
        $this->assertNotNull($offer, 'no options were offered to the visitor');

        $options = json_decode($offer->metadata, true)['interactive_options'];
        $choices = $options['buttons'] ?? collect($options['sections'] ?? [])->flatMap(fn ($s) => $s['rows'])->all();
        $choice = collect($choices)->firstWhere('title', $title);
        $this->assertNotNull($choice, "«{$title}» was not offered");

        $this->widgetSend(['type' => 'interactive', 'reply' => ['message_id' => $offer->uuid, 'option_id' => $choice['id']]]);
    }

    /** الزائر قرأ حتى آخر رسالة (علامة القراءة في الـ widget). */
    protected function visitorReadsEverything(): void
    {
        $latest = (int) Chat::where('contact_id', $this->customer(Channel::WEBCHAT)->id)->max('id');

        $this->postJson($this->widgetUrl('read'), ['seq' => $latest], ['X-Webchat-Token' => $this->widgetToken()])->assertOk();
    }

    // ───────────────────── الموظّف ─────────────────────

    protected function asOwner(): static
    {
        $this->actingAs($this->owner);
        session(['current_organization' => $this->organization->id]);

        return $this;
    }

    protected function agentReplies(Contact $contact, string $text): void
    {
        $this->asOwner()->post('/chats', [
            'uuid' => $contact->uuid,
            'message' => $text,
            'type' => 'text',
            'tempMessageId' => (string) Str::uuid(),
        ])->assertOk();
    }

    protected function agentCloses(Contact $contact): void
    {
        $this->asOwner()->put('/tickets/' . $contact->uuid . '/update', ['status' => 'closed'])->assertRedirect();
    }

    // ───────────────────── التجهيز والقراءة ─────────────────────

    /** @param array<int, string> $channels */
    protected function autoReply(string $trigger, string $reply, array $channels = self::CHANNELS): AutoReply
    {
        return AutoReply::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'name' => $trigger,
            'trigger' => $trigger,
            'match_criteria' => 'contains',
            'channels' => $channels,
            'metadata' => json_encode(['type' => 'text', 'data' => ['text' => $reply]]),
            'created_by' => $this->owner->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function latestTicket(Contact $contact): ?ChatTicket
    {
        return ChatTicket::where('contact_id', $contact->id)->where('is_latest', true)->first();
    }

    /** @return array<int, array<string, mixed>> metadata الرسائل الصادرة بالترتيب. */
    protected function outbound(Contact $contact): array
    {
        return Chat::where('contact_id', $contact->id)
            ->where('type', 'outbound')
            ->orderBy('id')
            ->pluck('metadata')
            ->map(fn ($m) => json_decode($m, true))
            ->all();
    }

    protected function lastOutbound(Contact $contact): Chat
    {
        return Chat::where('contact_id', $contact->id)->where('type', 'outbound')->orderByDesc('id')->firstOrFail();
    }

    /** نصّ الرسالة كما حُفظ، أيّاً كان نوعها. */
    protected static function bodyOf(array $metadata): ?string
    {
        return $metadata['text']['body'] ?? $metadata['interactive']['body']['text'] ?? null;
    }

    /**
     * ما وصل إلى المنصّة نفسها لهذا العميل، بالترتيب: أجسام Graph لواتساب،
     * وطلبات Bot API لتيليجرام. المحادثة المباشرة بلا شبكة: ما يصل هو البثّ.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function deliveredTo(string $channel): array
    {
        return match ($channel) {
            Channel::WHATSAPP => array_values(array_filter(
                $this->sentMessages(),
                fn (array $m) => ($m['to'] ?? null) === '+' . self::WHATSAPP_FROM || ($m['to'] ?? null) === self::WHATSAPP_FROM
            )),
            Channel::TELEGRAM => collect(Http::recorded())
                ->map(fn ($pair) => $pair[0])
                ->filter(fn ($request) => preg_match('#/(sendMessage|sendPhoto|sendVideo|sendDocument)$#', $request->url()))
                ->map(fn ($request) => $request->data())
                ->values()
                ->all(),
            Channel::WEBCHAT => collect(Event::dispatched(WebChatVisitorEvent::class))
                ->map(fn ($call) => $call[0])
                ->filter(fn (WebChatVisitorEvent $event) => ($event->payload['kind'] ?? null) === 'message'
                    && ($event->payload['message']['direction'] ?? null) === 'out')
                ->map(fn (WebChatVisitorEvent $event) => $event->payload['message'])
                ->values()
                ->all(),
        };
    }

    /** @return array<int, string|null> نصوص ما وصل إلى المنصّة بالترتيب. */
    protected function deliveredTexts(string $channel): array
    {
        return array_map(fn (array $sent) => match ($channel) {
            Channel::WHATSAPP => $sent['text']['body'] ?? $sent['interactive']['body']['text'] ?? null,
            Channel::TELEGRAM => $sent['text'] ?? $sent['caption'] ?? null,
            Channel::WEBCHAT => $sent['text'] ?? null,
        }, $this->deliveredTo($channel));
    }

    /** آخر بثّ لصندوق الوارد عن رسالة هذا العميل. */
    protected function inboxBroadcasts(Contact $contact, string $type): array
    {
        return collect(Event::dispatched(NewChatEvent::class))
            ->map(fn ($call) => $call[0])
            ->filter(fn (NewChatEvent $e) => $e->contactId === (int) $contact->id)
            ->map(fn (NewChatEvent $e) => $e->chat[0]['value'] ?? $e->chat['value'] ?? null)
            ->filter(fn ($value) => is_array($value) && ($value['type'] ?? null) === $type)
            ->values()
            ->all();
    }
}
