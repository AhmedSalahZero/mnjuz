<?php

namespace Tests\Feature\Channels;

use App\Models\ChannelAccount;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\User;
use App\Services\Messaging\Channel;
use App\Services\Messaging\Drivers\TikTokDriver;
use App\Services\Messaging\InboundMessage;
use App\Services\Messaging\InboundStatus;
use App\Services\Messaging\OutboundMedia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * تيك توك: أضيق المنصّات وأكثرها مؤونة.
 *
 * ثلاثة أشياء تنفرد بها ويجب أن تُثبَّت باختبار:
 *   ١. التوقيع يشمل الوقت ويُرفض بعد خمس ثوانٍ.
 *   ٢. content يصل نصّاً يحوي JSON لا كائناً.
 *   ٣. access token يعيش يوماً واحداً ويحتاج تجديداً دورياً.
 */
class TikTokChannelTest extends TestCase
{
    use RefreshDatabase;

    private ChannelAccount $account;
    private TikTokDriver $driver;

    private const APP_SECRET = 'tiktok-app-secret';
    private const BUSINESS_ID = 'biz-open-id-123';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.tiktok.app_id' => 'tt-app-id',
            'services.tiktok.app_secret' => self::APP_SECRET,
            'services.tiktok.api_version' => 'v1.3',
        ]);

        $owner = User::factory()->create(['role' => 'user']);
        $organization = Organization::factory()->create(['created_by' => $owner->id]);

        $this->account = ChannelAccount::create([
            'organization_id' => $organization->id,
            'channel' => Channel::TIKTOK,
            'external_id' => self::BUSINESS_ID,
            'name' => 'متجر تجريبي',
            'credentials' => [
                'access_token' => 'tt-access-token',
                'refresh_token' => 'tt-refresh-token',
            ],
            'token_expires_at' => now()->addHours(12),
            'refresh_token_expires_at' => now()->addDays(25),
            'status' => ChannelAccount::STATUS_ACTIVE,
            'created_by' => 0,
        ]);

        $this->driver = app(TikTokDriver::class);
    }

    // ───────────────────── التوقيع ─────────────────────

    /** التوقيع الصحيح داخل نافذة الخمس ثوانٍ يمرّ. */
    public function test_a_fresh_valid_signature_passes(): void
    {
        $body = json_encode(['event' => 'im_receive_msg']);
        $request = $this->signed($body, time());

        $this->assertTrue($this->driver->verifyWebhook($request, $this->account));
    }

    /**
     * والتوقيع القديم يُرفض ولو كانت بصمته صحيحة.
     *
     * هذا هو ما يمنع إعادة تشغيل حمولة مسجَّلة (replay attack).
     */
    public function test_a_stale_but_correctly_signed_payload_is_rejected(): void
    {
        $body = json_encode(['event' => 'im_receive_msg']);
        $request = $this->signed($body, time() - 60);

        $this->assertFalse($this->driver->verifyWebhook($request, $this->account));
    }

    /** وبصمة خاطئة تُرفض. */
    public function test_a_wrong_signature_is_rejected(): void
    {
        $body = json_encode(['event' => 'im_receive_msg']);
        $timestamp = time();

        $request = Request::create('/webhook/channel/tiktok', 'POST', [], [], [], [], $body);
        $request->headers->set('Tiktok-Signature', "t={$timestamp},s=" . str_repeat('0', 64));

        $this->assertFalse($this->driver->verifyWebhook($request, $this->account));
    }

    /** وترويسة مشوّهة أو غائبة تُرفض بلا انهيار. */
    public function test_a_malformed_signature_header_is_rejected(): void
    {
        $body = '{}';

        $malformed = Request::create('/webhook/channel/tiktok', 'POST', [], [], [], [], $body);
        $malformed->headers->set('Tiktok-Signature', 'garbage');
        $this->assertFalse($this->driver->verifyWebhook($malformed, $this->account));

        $missing = Request::create('/webhook/channel/tiktok', 'POST', [], [], [], [], $body);
        $this->assertFalse($this->driver->verifyWebhook($missing, $this->account));
    }

    // ───────────────────── التوجيه ─────────────────────

    /** الحساب يُعرف من user_openid. */
    public function test_the_account_is_resolved_from_user_openid(): void
    {
        $request = Request::create('/webhook/channel/tiktok', 'POST', [
            'event' => 'im_receive_msg',
            'user_openid' => self::BUSINESS_ID,
        ]);

        $this->assertSame(self::BUSINESS_ID, $this->driver->resolveAccountExternalId($request));
    }

    // ───────────────────── قراءة الوارد ─────────────────────

    /**
     * content يصل **نصّاً** يحوي JSON. قراءته كمصفوفة مباشرةً تُنتج
     * لا شيء — وهذا خطأ يسهل الوقوع فيه.
     */
    public function test_the_content_field_is_parsed_from_its_json_string(): void
    {
        $events = $this->parse('im_receive_msg', [
            'conversation_id' => 'conv-1',
            'message_id' => 'msg-1',
            'type' => 'text',
            'timestamp' => 1758600000123,
            'from' => 'ahmed_tt',
            'from_user' => ['id' => 'user-999'],
            'to_user' => ['id' => self::BUSINESS_ID],
            'text' => ['body' => 'هل المنتج متوفّر؟'],
        ]);

        $this->assertCount(1, $events);
        $message = $events[0];
        $this->assertInstanceOf(InboundMessage::class, $message);
        $this->assertSame(['type' => 'text', 'text' => ['body' => 'هل المنتج متوفّر؟']], $message->metadata);
    }

    /**
     * المعرّف الذي نُخاطب به هو conversation_id لا معرّف المستخدم —
     * لأن نقطة الإرسال تستقبل المحادثة لا الشخص.
     */
    public function test_the_sender_id_is_the_conversation_not_the_user(): void
    {
        $message = $this->parse('im_receive_msg', [
            'conversation_id' => 'conv-1',
            'message_id' => 'msg-1',
            'type' => 'text',
            'from' => 'ahmed_tt',
            'from_user' => ['id' => 'user-999'],
            'to_user' => ['id' => self::BUSINESS_ID],
            'text' => ['body' => 'مرحبا'],
        ])[0];

        $this->assertSame('conv-1', $message->senderId);
        $this->assertSame('ahmed_tt', $message->senderName);
        $this->assertSame('tt:' . self::BUSINESS_ID . ':msg-1', $message->providerMessageId);
    }

    /** الصورة تحمل ما يلزم لتنزيلها لاحقاً: المحادثة والرسالة والوسائط. */
    public function test_an_image_carries_what_the_download_call_needs(): void
    {
        $message = $this->parse('im_receive_msg', [
            'conversation_id' => 'conv-1',
            'message_id' => 'msg-img',
            'type' => 'image',
            'from_user' => ['id' => 'user-999'],
            'to_user' => ['id' => self::BUSINESS_ID],
            'image' => ['media_id' => 'media-55'],
        ])[0];

        $this->assertSame('image', $message->metadata['type']);
        $this->assertTrue($message->hasMedia());
        $this->assertSame('media-55', $message->media->providerId);
        $this->assertSame('conv-1', $message->media->extra['conversation_id']);
        $this->assertSame('msg-img', $message->media->extra['message_id']);
    }

    /** مشاركة منشور: الرابط هو المحتوى المفيد. */
    public function test_a_shared_post_becomes_its_link(): void
    {
        $message = $this->parse('im_receive_msg', [
            'conversation_id' => 'conv-1',
            'message_id' => 'msg-share',
            'type' => 'share_post',
            'from_user' => ['id' => 'user-999'],
            'to_user' => ['id' => self::BUSINESS_ID],
            'share_post' => ['embed_url' => 'https://tiktok.com/@x/video/1'],
        ])[0];

        $this->assertSame(
            ['type' => 'text', 'text' => ['body' => 'https://tiktok.com/@x/video/1']],
            $message->metadata
        );
    }

    /** im_send_msg صدى رسالة صادرة لا رسالة واردة. */
    public function test_an_outgoing_event_is_marked_as_an_echo(): void
    {
        $message = $this->parse('im_send_msg', [
            'conversation_id' => 'conv-1',
            'message_id' => 'msg-out',
            'type' => 'text',
            'from_user' => ['id' => self::BUSINESS_ID],
            'to_user' => ['id' => 'user-999'],
            'text' => ['body' => 'شكراً لتواصلك'],
        ])[0];

        $this->assertTrue($message->isEcho);
    }

    /** وحدث القراءة يخرج حالةً بعلامة مائية. */
    public function test_a_read_event_becomes_a_watermark_status(): void
    {
        $status = $this->parse('im_mark_read_msg', [
            'conversation_id' => 'conv-1',
            'from_user' => ['id' => 'user-999'],
            'read' => ['last_read_timestamp' => 1758600000123],
        ])[0];

        $this->assertInstanceOf(InboundStatus::class, $status);
        $this->assertSame('read', $status->status);
        $this->assertTrue($status->isWatermark());
    }

    /** حدث غير مدعوم يُتجاهل بهدوء. */
    public function test_an_unknown_event_is_ignored(): void
    {
        $this->assertSame([], $this->parse('im_something_else', ['conversation_id' => 'conv-1']));
    }

    // ───────────────────── الإرسال ─────────────────────

    /** النصّ يُرسل إلى المحادثة بالشكل الذي تفرضه تيك توك. */
    public function test_sending_text_uses_the_conversation_recipient_shape(): void
    {
        Http::fake([
            'business-api.tiktok.com/*' => Http::response([
                'code' => 0,
                'message' => 'OK',
                'data' => ['message' => ['message_id' => 'msg-sent']],
            ], 200),
        ]);

        $result = $this->driver->sendText($this->account, $this->contact('conv-1'), 'تفضّل');

        $this->assertTrue($result->success);
        $this->assertSame('tt:' . self::BUSINESS_ID . ':msg-sent', $result->providerMessageId);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/business/message/send/')
            && $request['recipient_type'] === 'CONVERSATION'
            && $request['recipient'] === 'conv-1'
            && $request['message_type'] === 'TEXT'
            && $request['text']['body'] === 'تفضّل');
    }

    /**
     * تيك توك تُرجع HTTP 200 مع code != 0 عند الفشل.
     * قراءة رمز HTTP وحده تجعل الفشل يبدو نجاحاً.
     */
    public function test_a_business_error_inside_a_200_response_is_a_failure(): void
    {
        Http::fake([
            'business-api.tiktok.com/*' => Http::response([
                'code' => 40001,
                'message' => 'Conversation window expired',
            ], 200),
        ]);

        $result = $this->driver->sendText($this->account, $this->contact('conv-1'), 'مرحبا');

        $this->assertFalse($result->success);
        $this->assertSame('40001', $result->errorCode);
        $this->assertSame('Conversation window expired', $result->errorMessage);
    }

    /** الفيديو مرفوض قبل أي نداء شبكة — تيك توك لا تقبل غير الصور. */
    public function test_video_is_refused_before_any_network_call(): void
    {
        Http::fake();

        $media = new OutboundMedia(type: 'video', url: 'https://cdn.example.com/v.mp4');
        $result = $this->driver->sendMedia($this->account, $this->contact('conv-1'), $media);

        $this->assertFalse($result->success);
        Http::assertNothingSent();
    }

    /** والصورة الأكبر من ثلاثة ميغابايت كذلك. */
    public function test_an_oversized_image_is_refused_before_any_network_call(): void
    {
        Http::fake();

        $media = new OutboundMedia(type: 'image', url: 'https://cdn.example.com/big.jpg', size: 4 * 1024 * 1024);
        $result = $this->driver->sendMedia($this->account, $this->contact('conv-1'), $media);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('3 MB', $result->errorMessage);
        Http::assertNothingSent();
    }

    // ───────────────────── تجديد الصلاحيات ─────────────────────

    /** التوكن المنتهي يُجدَّد تلقائياً وتُحفظ المواعيد الجديدة. */
    public function test_an_expired_token_is_refreshed_automatically(): void
    {
        $this->account->forceFill(['token_expires_at' => now()->subMinute()])->save();

        Http::fake([
            '*/tt_user/oauth2/refresh_token/' => Http::response([
                'code' => 0,
                'data' => [
                    'access_token' => 'new-access-token',
                    'refresh_token' => 'new-refresh-token',
                    'expires_in' => 86400,
                    'refresh_token_expires_in' => 2592000,
                ],
            ], 200),
            'business-api.tiktok.com/*' => Http::response([
                'code' => 0,
                'data' => ['message' => ['message_id' => 'msg-after-refresh']],
            ], 200),
        ]);

        $result = $this->driver->sendText($this->account, $this->contact('conv-1'), 'مرحبا');

        $this->assertTrue($result->success);

        $fresh = $this->account->fresh();
        $this->assertSame('new-access-token', $fresh->credential('access_token'));
        $this->assertSame('new-refresh-token', $fresh->credential('refresh_token'));
        $this->assertTrue($fresh->isActive());
        $this->assertTrue($fresh->token_expires_at->isFuture());
    }

    /**
     * وانتهاء refresh token يوقف المحاولات ويضع الحساب في حالة تحتاج
     * إعادة ربط — بدل أن نطرق باباً مغلقاً مع كل رسالة.
     */
    public function test_an_expired_refresh_token_stops_and_flags_the_account(): void
    {
        $this->account->forceFill([
            'token_expires_at' => now()->subDay(),
            'refresh_token_expires_at' => now()->subDay(),
        ])->save();

        Http::fake();

        $this->driver->sendText($this->account, $this->contact('conv-1'), 'مرحبا');

        $this->assertTrue($this->account->fresh()->needsReauthorization());
        Http::assertNothingSent();
    }

    /** وفشل التجديد يضع الحساب في نفس الحالة بدل أن يمرّ صامتاً. */
    public function test_a_failed_refresh_flags_the_account(): void
    {
        $this->account->forceFill(['token_expires_at' => now()->subMinute()])->save();

        Http::fake([
            '*/tt_user/oauth2/refresh_token/' => Http::response([
                'code' => 40100,
                'message' => 'Invalid refresh token',
            ], 200),
            'business-api.tiktok.com/*' => Http::response(['code' => 0, 'data' => []], 200),
        ]);

        $this->driver->sendText($this->account, $this->contact('conv-1'), 'مرحبا');

        $fresh = $this->account->fresh();
        $this->assertTrue($fresh->needsReauthorization());
        $this->assertStringContainsString('Invalid refresh token', (string) $fresh->last_error);
    }

    // ───────────────────── القدرات ─────────────────────

    /** قيود تيك توك المعلنة تطابق ما توثّقه المنصّة. */
    public function test_tiktok_capabilities_match_the_platform_limits(): void
    {
        $capabilities = $this->driver->capabilities();

        $this->assertSame(48, $capabilities->windowHours);
        $this->assertFalse($capabilities->textWithMedia);
        $this->assertFalse($capabilities->templates);
        $this->assertTrue($capabilities->supportsMedia('image'));
        $this->assertFalse($capabilities->supportsMedia('video'));
        $this->assertFalse($capabilities->supportsMedia('document'));
        $this->assertSame(3 * 1024 * 1024, $capabilities->mediaLimit('image'));
    }

    /** ونافذة جهة اتصال تيك توك ٤٨ ساعة لا ٢٤. */
    public function test_the_tiktok_window_is_forty_eight_hours(): void
    {
        $contact = $this->contact('conv-1');

        $this->assertSame(48, \App\Helpers\MessagingWindowHelper::windowHoursFor($contact));

        $contact->last_inbound_chat_created_at = now()->subHours(30)->toDateTimeString();
        $this->assertTrue(\App\Helpers\MessagingWindowHelper::isMessagingWindowOpen($contact));

        $contact->last_inbound_chat_created_at = now()->subHours(50)->toDateTimeString();
        $this->assertFalse(\App\Helpers\MessagingWindowHelper::isMessagingWindowOpen($contact));
    }

    // ───────────────────── مساعدات ─────────────────────

    private function signed(string $body, int $timestamp): Request
    {
        $signature = hash_hmac('sha256', $timestamp . '.' . $body, self::APP_SECRET);

        $request = Request::create('/webhook/channel/tiktok', 'POST', [], [], [], [], $body);
        $request->headers->set('Tiktok-Signature', "t={$timestamp},s={$signature}");

        return $request;
    }

    private function parse(string $event, array $content): array
    {
        return $this->driver->parseWebhook(
            Request::create('/webhook/channel/tiktok', 'POST', [
                'event' => $event,
                'user_openid' => self::BUSINESS_ID,
                // تيك توك ترسله نصّاً — وهذا هو بيت القصيد.
                'content' => json_encode($content),
            ]),
            $this->account
        );
    }

    private function contact(string $conversationId): Contact
    {
        return Contact::create([
            'organization_id' => $this->account->organization_id,
            'channel' => Channel::TIKTOK,
            'channel_identifier' => $conversationId,
            'channel_account_id' => $this->account->id,
            'phone' => null,
            'created_by' => 0,
        ]);
    }
}
