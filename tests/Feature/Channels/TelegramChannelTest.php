<?php

namespace Tests\Feature\Channels;

use App\Models\ChannelAccount;
use App\Models\Chat;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\User;
use App\Services\Messaging\Channel;
use App\Services\Messaging\Drivers\TelegramDriver;
use App\Services\Messaging\InboundMessage;
use App\Services\Messaging\OutboundMedia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * تيليجرام: قراءة التحديثات وإرسال الرسائل.
 *
 * التركيز على ما يفرّقها عن واتساب: message_id تسلسلي لكل محادثة، ولا
 * نافذة زمنية، ولا إشعارات تسليم، وشكل حمولة مختلف كلّياً.
 */
class TelegramChannelTest extends TestCase
{
    use RefreshDatabase;

    private ChannelAccount $account;
    private TelegramDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        $owner = User::factory()->create(['role' => 'user']);
        $organization = Organization::factory()->create(['created_by' => $owner->id]);

        $this->account = ChannelAccount::create([
            'organization_id' => $organization->id,
            'channel' => Channel::TELEGRAM,
            'external_id' => '7654321',
            'name' => 'بوت الدعم',
            'username' => 'support_bot',
            'credentials' => ['bot_token' => '7654321:AAF-test-token', 'webhook_secret' => 's3cr3t'],
            'status' => ChannelAccount::STATUS_ACTIVE,
            'created_by' => $owner->id,
        ]);

        $this->driver = app(TelegramDriver::class);
    }

    // ───────────────────── التحقّق من المصدر ─────────────────────

    /** الترويسة الصحيحة تمرّ، والخاطئة تُرفض. */
    public function test_the_webhook_secret_header_is_checked(): void
    {
        $good = Request::create('/webhook/channel/telegram', 'POST');
        $good->headers->set('X-Telegram-Bot-Api-Secret-Token', 's3cr3t');
        $this->assertTrue($this->driver->verifyWebhook($good, $this->account));

        $bad = Request::create('/webhook/channel/telegram', 'POST');
        $bad->headers->set('X-Telegram-Bot-Api-Secret-Token', 'wrong');
        $this->assertFalse($this->driver->verifyWebhook($bad, $this->account));

        $none = Request::create('/webhook/channel/telegram', 'POST');
        $this->assertFalse($this->driver->verifyWebhook($none, $this->account));
    }

    // ───────────────────── قراءة الوارد ─────────────────────

    /** رسالة نصّية تُقرأ بشكل metadata الخاص بواتساب لا بشكل تيليجرام. */
    public function test_a_text_message_is_normalised_to_the_whatsapp_shape(): void
    {
        $events = $this->parse($this->update(['text' => 'السلام عليكم']));

        $this->assertCount(1, $events);
        $message = $events[0];
        $this->assertInstanceOf(InboundMessage::class, $message);

        // هذا هو العقد مع فقاعة المحادثة وتطبيق الجوال.
        $this->assertSame(['type' => 'text', 'text' => ['body' => 'السلام عليكم']], $message->metadata);
        $this->assertSame('123456789', $message->senderId);
        $this->assertSame('أحمد سالم', $message->senderName);
        $this->assertSame('ahmed', $message->senderUsername);
        $this->assertFalse($message->hasMedia());
    }

    /**
     * معرّف الرسالة يحمل معرّف المحادثة.
     *
     * بدونه تتصادم الرسالة رقم ١ من محادثتين مختلفتين على الفهرس الفريد
     * العالمي، فتُبتلع إحداهما صامتة.
     */
    public function test_the_message_id_is_namespaced_by_chat_so_two_chats_never_collide(): void
    {
        $first = $this->parse($this->update(['text' => 'أ'], chatId: 111, messageId: 1))[0];
        $second = $this->parse($this->update(['text' => 'ب'], chatId: 222, messageId: 1))[0];

        $this->assertNotSame($first->providerMessageId, $second->providerMessageId);
        $this->assertSame('tg:7654321:111:1', $first->providerMessageId);
        $this->assertSame('tg:7654321:222:1', $second->providerMessageId);
    }

    /** الصورة تصل بعدّة مقاسات — نأخذ الأكبر. */
    public function test_a_photo_takes_the_largest_size_and_keeps_its_caption(): void
    {
        $message = $this->parse($this->update([
            'caption' => 'هذه فاتورتي',
            'photo' => [
                ['file_id' => 'small', 'width' => 90, 'height' => 90, 'file_size' => 1000],
                ['file_id' => 'large', 'width' => 1280, 'height' => 720, 'file_size' => 90000],
            ],
        ]))[0];

        $this->assertSame(['type' => 'image', 'image' => ['caption' => 'هذه فاتورتي']], $message->metadata);
        $this->assertTrue($message->hasMedia());
        $this->assertSame('large', $message->media->providerId);
        $this->assertSame('image', $message->media->type);
    }

    /** الرسالة الصوتية تُميَّز عن ملف الصوت — كما تفعل واتساب. */
    public function test_a_voice_note_is_marked_as_voice(): void
    {
        $message = $this->parse($this->update([
            'voice' => ['file_id' => 'v1', 'mime_type' => 'audio/ogg', 'duration' => 3],
        ]))[0];

        $this->assertSame(['type' => 'audio', 'audio' => ['voice' => true]], $message->metadata);
        $this->assertTrue($message->media->isVoice);
    }

    /** المستند يحمل اسمه. */
    public function test_a_document_keeps_its_filename(): void
    {
        $message = $this->parse($this->update([
            'document' => ['file_id' => 'd1', 'file_name' => 'عقد.pdf', 'mime_type' => 'application/pdf'],
        ]))[0];

        $this->assertSame('document', $message->metadata['type']);
        $this->assertSame('عقد.pdf', $message->metadata['document']['filename']);
        $this->assertSame('عقد.pdf', $message->media->fileName);
    }

    /** الموقع يُقرأ بنفس شكل موقع واتساب. */
    public function test_a_location_matches_the_whatsapp_location_shape(): void
    {
        $message = $this->parse($this->update([
            'location' => ['latitude' => 21.4858, 'longitude' => 39.1925],
        ]))[0];

        $this->assertSame('location', $message->metadata['type']);
        $this->assertSame(21.4858, $message->metadata['location']['latitude']);
        $this->assertSame(39.1925, $message->metadata['location']['longitude']);
    }

    /**
     * الملصق المتحرّك لا يعرضه المتصفّح، فنحفظ الإيموجي نصّاً بدل فقاعة
     * فارغة لا تقول شيئاً للموظّف.
     */
    public function test_an_animated_sticker_becomes_its_emoji(): void
    {
        $message = $this->parse($this->update([
            'sticker' => ['file_id' => 's1', 'emoji' => '😀', 'is_animated' => true],
        ]))[0];

        $this->assertSame(['type' => 'text', 'text' => ['body' => '😀']], $message->metadata);
        $this->assertFalse($message->hasMedia());
    }

    /** المحادثات الجماعية ليست محادثات عملاء — تُتجاهل. */
    public function test_group_chats_are_ignored(): void
    {
        $update = $this->update(['text' => 'مرحبا']);
        $update['message']['chat']['type'] = 'group';

        $this->assertSame([], $this->parse($update));
    }

    /** حمولة بلا رسالة لا تُنتج شيئاً ولا ترمي. */
    public function test_an_update_without_a_message_is_ignored(): void
    {
        $this->assertSame([], $this->parse(['update_id' => 1]));
        $this->assertSame([], $this->parse([]));
    }

    // ───────────────────── الإرسال ─────────────────────

    /** إرسال نصّ ينادي sendMessage ويُرجع معرّفاً مُنمَّقاً. */
    public function test_sending_text_returns_a_namespaced_id(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 55]], 200),
        ]);

        $result = $this->driver->sendText($this->account, $this->contact('123456789'), 'أهلاً بك');

        $this->assertTrue($result->success);
        $this->assertSame('tg:7654321:123456789:55', $result->providerMessageId);
        // لا إشعار تسليم في تيليجرام، فـ sent هي الحالة النهائية.
        $this->assertSame('sent', $result->status);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/sendMessage')
                && $request['chat_id'] === '123456789'
                && $request['text'] === 'أهلاً بك';
        });
    }

    /** الصورة تُرسل عبر sendPhoto لا sendDocument. */
    public function test_an_image_is_sent_with_send_photo(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 56]], 200),
        ]);

        $media = new OutboundMedia(type: 'image', url: 'https://cdn.example.com/a.jpg', caption: 'تفضّل');
        $result = $this->driver->sendMedia($this->account, $this->contact('123456789'), $media);

        $this->assertTrue($result->success);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/sendPhoto')
            && $request['photo'] === 'https://cdn.example.com/a.jpg'
            && $request['caption'] === 'تفضّل');
    }

    /** حظر العميل للبوت يُترجَم إلى رسالة يفهمها الموظّف لا إلى رمز خام. */
    public function test_a_blocked_bot_produces_a_readable_error(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => false,
                'error_code' => 403,
                'description' => 'Forbidden: bot was blocked by the user',
            ], 403),
        ]);

        $result = $this->driver->sendText($this->account, $this->contact('123456789'), 'مرحبا');

        $this->assertFalse($result->success);
        $this->assertSame('403', $result->errorCode);
        $this->assertStringContainsString('blocked', strtolower($result->errorMessage));
    }

    // ───────────────────── القدرات ─────────────────────

    /** تيليجرام بلا نافذة زمنية وبلا قوالب وبلا إشعارات تسليم. */
    public function test_telegram_capabilities_reflect_the_platform(): void
    {
        $capabilities = $this->driver->capabilities();

        $this->assertNull($capabilities->windowHours);
        $this->assertTrue($capabilities->hasUnlimitedWindow());
        $this->assertFalse($capabilities->templates);
        $this->assertFalse($capabilities->deliveryReceipts);
        $this->assertTrue($capabilities->supportsMedia('document'));
    }

    /** ونافذة جهة اتصال تيليجرام مفتوحة دائماً — حتى بلا رسالة واردة. */
    public function test_a_telegram_window_is_always_open(): void
    {
        $contact = $this->contact('123456789');

        $this->assertNull(\App\Helpers\MessagingWindowHelper::windowHoursFor($contact));
        $this->assertTrue(\App\Helpers\MessagingWindowHelper::isMessagingWindowOpen($contact));
    }

    // ───────────────────── مساعدات ─────────────────────

    private function update(array $content, int $chatId = 123456789, int $messageId = 42): array
    {
        return [
            'update_id' => 900001,
            'message' => array_merge([
                'message_id' => $messageId,
                'from' => [
                    'id' => $chatId,
                    'is_bot' => false,
                    'first_name' => 'أحمد',
                    'last_name' => 'سالم',
                    'username' => 'ahmed',
                ],
                'chat' => ['id' => $chatId, 'type' => 'private'],
                'date' => 1758600000,
            ], $content),
        ];
    }

    private function parse(array $payload): array
    {
        return $this->driver->parseWebhook(
            Request::create('/webhook/channel/telegram', 'POST', $payload),
            $this->account
        );
    }

    private function contact(string $identifier): Contact
    {
        return Contact::create([
            'organization_id' => $this->account->organization_id,
            'channel' => Channel::TELEGRAM,
            'channel_identifier' => $identifier,
            'channel_account_id' => $this->account->id,
            'phone' => null,
            'first_name' => 'أحمد',
            'created_by' => 0,
        ]);
    }
}
