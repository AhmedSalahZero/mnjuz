<?php

namespace Tests\Feature\ConversationCycle;

use App\Models\ChannelAccount;
use App\Models\Chat;
use App\Models\Contact;
use App\Services\Messaging\Channel;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * مدخل webhooks القنوات غير واتساب عبر HTTP، من التوقيع إلى صندوق الوارد.
 *
 * كانت مغطّاة على مستوى السائق وحده (parseWebhook مباشرةً): لا اختبار يمرّ
 * بالمسار والتوقيع والوظيفة والمسجِّل معاً. فرسالة من ماسنجر أو انستجرام
 * أو تيك توك لم تُسجَّل في أي اختبار قطّ.
 */
class ChannelWebhookIntakeTest extends ConversationCycleTestCase
{
    private const META_SECRET = 'meta-app-secret';
    private const TIKTOK_SECRET = 'tiktok-app-secret';

    /** @var array<string, array{external: string, sender: string}> */
    private const ACCOUNTS = [
        Channel::MESSENGER => ['external' => '1010101010', 'sender' => 'PSID-777'],
        Channel::INSTAGRAM => ['external' => '2020202020', 'sender' => 'IGSID-888'],
        Channel::TIKTOK => ['external' => 'biz-open-id-123', 'sender' => 'conv-1'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.meta.app_secret' => self::META_SECRET,
            'services.tiktok.app_id' => 'tt-app-id',
            'services.tiktok.app_secret' => self::TIKTOK_SECRET,
        ]);
        // ملفّ المرسل وأيّ نداء جانبي: لا شبكة حقيقية.
        Http::fake(['*' => Http::response([], 200)]);

        foreach (self::ACCOUNTS as $channel => $ids) {
            ChannelAccount::create([
                'organization_id' => $this->organization->id,
                'channel' => $channel,
                'external_id' => $ids['external'],
                'name' => 'حساب ' . $channel,
                'credentials' => ['access_token' => 'token', 'app_secret' => self::META_SECRET, 'refresh_token' => 'r'],
                'token_expires_at' => now()->addHours(12),
                'refresh_token_expires_at' => now()->addDays(25),
                'status' => ChannelAccount::STATUS_ACTIVE,
                'created_by' => $this->owner->id,
            ]);
        }
    }

    /** @return array<string, array{0: string}> */
    public static function socialChannels(): array
    {
        return [
            Channel::MESSENGER => [Channel::MESSENGER],
            Channel::INSTAGRAM => [Channel::INSTAGRAM],
            Channel::TIKTOK => [Channel::TIKTOK],
        ];
    }

    #[DataProvider('socialChannels')]
    public function test_a_signed_message_lands_in_the_inbox_with_a_ticket(string $channel): void
    {
        $this->deliver($channel, $this->textPayload($channel, 'عندي استفسار'))
            ->assertOk()
            ->assertJson(['status' => 'success']);

        $contact = Contact::where('organization_id', $this->organization->id)
            ->where('channel', $channel)
            ->where('channel_identifier', self::ACCOUNTS[$channel]['sender'])
            ->first();
        $this->assertNotNull($contact, 'the sender became a contact');

        $chat = Chat::where('contact_id', $contact->id)->where('type', 'inbound')->sole();
        $this->assertSame($channel, $chat->channel);
        $this->assertSame('عندي استفسار', json_decode($chat->metadata, true)['text']['body']);

        $this->assertSame('open', $this->latestTicket($contact)?->status);

        $arrivals = $this->inboxBroadcasts($contact, 'inbound');
        $this->assertCount(1, $arrivals);
        $this->assertSame($channel, $arrivals[0]['channel']);
    }

    #[DataProvider('socialChannels')]
    public function test_a_redelivered_message_is_recorded_once(string $channel): void
    {
        $payload = $this->textPayload($channel, 'مرحبا');

        $this->deliver($channel, $payload)->assertOk();
        $this->deliver($channel, $payload)->assertOk();

        $this->assertSame(1, Chat::where('organization_id', $this->organization->id)->where('channel', $channel)->count());
    }

    #[DataProvider('socialChannels')]
    public function test_a_wrong_signature_is_refused_and_nothing_is_recorded(string $channel): void
    {
        $this->deliver($channel, $this->textPayload($channel, 'مزوّر'), forge: true)->assertStatus(401);

        $this->assertSame(0, Chat::where('organization_id', $this->organization->id)->count());
    }

    public function test_telegram_without_its_secret_header_is_refused(): void
    {
        $update = [
            'update_id' => 1,
            'message' => ['message_id' => 1, 'date' => now()->timestamp, 'chat' => ['id' => self::TELEGRAM_CHAT, 'type' => 'private'], 'from' => ['id' => self::TELEGRAM_CHAT, 'first_name' => 'أحمد'], 'text' => 'مزوّر'],
        ];

        $this->postJson('/webhook/channel/telegram/' . $this->bot->uuid, $update)->assertStatus(401);
        $this->postJson('/webhook/channel/telegram/' . $this->bot->uuid, $update, ['X-Telegram-Bot-Api-Secret-Token' => 'wrong'])->assertStatus(401);

        $this->assertSame(0, Chat::where('organization_id', $this->organization->id)->count());
    }

    /** حساب فصله التاجر: 200 كي لا تعيد المنصّة الإرسال، ولا شيء يُسجَّل. */
    public function test_a_disconnected_account_is_acknowledged_but_ignored(): void
    {
        $this->bot->forceFill(['status' => ChannelAccount::STATUS_DISABLED])->save();

        $this->telegramWebhook([
            'update_id' => 2,
            'message' => ['message_id' => 2, 'date' => now()->timestamp, 'chat' => ['id' => self::TELEGRAM_CHAT, 'type' => 'private'], 'from' => ['id' => self::TELEGRAM_CHAT, 'first_name' => 'أحمد'], 'text' => 'مرحبا'],
        ])->assertJson(['status' => 'inactive']);

        $this->assertSame(0, Chat::where('organization_id', $this->organization->id)->count());
    }

    public function test_web_chat_and_whatsapp_have_no_place_on_the_channel_endpoint(): void
    {
        $this->postJson('/webhook/channel/webchat', [])->assertNotFound();
        $this->postJson('/webhook/channel/whatsapp', [])->assertNotFound();
    }

    // ───────────────────── مساعدات ─────────────────────

    private function textPayload(string $channel, string $text): array
    {
        $ids = self::ACCOUNTS[$channel];

        return match ($channel) {
            Channel::MESSENGER, Channel::INSTAGRAM => [
                'object' => $channel === Channel::MESSENGER ? 'page' : 'instagram',
                'entry' => [[
                    'id' => $ids['external'],
                    'time' => 1758600000123,
                    'messaging' => [[
                        'sender' => ['id' => $ids['sender']],
                        'recipient' => ['id' => $ids['external']],
                        'timestamp' => 1758600000123,
                        'message' => ['mid' => 'm_' . md5($text), 'text' => $text],
                    ]],
                ]],
            ],
            Channel::TIKTOK => [
                'event' => 'im_receive_msg',
                'user_openid' => $ids['external'],
                'content' => json_encode([
                    'conversation_id' => $ids['sender'],
                    'message_id' => 'msg-' . md5($text),
                    'type' => 'text',
                    'timestamp' => 1758600000123,
                    'from' => 'ahmed_tt',
                    'from_user' => ['id' => 'user-999'],
                    'to_user' => ['id' => $ids['external']],
                    'text' => ['body' => $text],
                ]),
            ],
        };
    }

    /** يرسل الجسم الخام موقَّعاً كما توقّعه المنصّة (أو بتوقيع خاطئ). */
    private function deliver(string $channel, array $payload, bool $forge = false): TestResponse
    {
        $body = json_encode($payload);
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];

        if ($channel === Channel::TIKTOK) {
            $timestamp = time();
            $signature = hash_hmac('sha256', $timestamp . '.' . $body, $forge ? 'other' : self::TIKTOK_SECRET);
            $server['HTTP_TIKTOK_SIGNATURE'] = "t={$timestamp},s={$signature}";
        } else {
            $server['HTTP_X_HUB_SIGNATURE_256'] = 'sha256=' . hash_hmac('sha256', $body, $forge ? 'other' : self::META_SECRET);
        }

        return $this->call('POST', '/webhook/channel/' . $channel, [], [], [], $server, $body);
    }
}
