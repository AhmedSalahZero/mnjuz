<?php

namespace Tests\Feature\Channels;

use App\Models\ChannelAccount;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\User;
use App\Services\Messaging\Channel;
use App\Services\Messaging\Drivers\InstagramDriver;
use App\Services\Messaging\Drivers\MessengerDriver;
use App\Services\Messaging\InboundMessage;
use App\Services\Messaging\InboundStatus;
use App\Services\Messaging\OutboundMedia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Messenger و Instagram Direct.
 *
 * المنصّتان تتقاسمان معظم المنطق، فيختبر هذا الملفّ المشترك مرّةً ثم
 * الفروق: object المختلف، وأنواع مرفقات إنستغرام الخاصّة، وغياب حدث
 * التسليم عنها.
 *
 * والفرق الجوهري عن واتساب: الحمولة لا تحمل اسم العميل إطلاقاً.
 */
class MetaChannelsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;
    private ChannelAccount $page;
    private ChannelAccount $instagram;
    private MessengerDriver $messenger;
    private InstagramDriver $instagramDriver;

    private const APP_SECRET = 'meta-app-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.meta.app_secret' => self::APP_SECRET]);

        $owner = User::factory()->create(['role' => 'user']);
        $this->organization = Organization::factory()->create(['created_by' => $owner->id]);

        $this->page = $this->account(Channel::MESSENGER, '1010101010');
        $this->instagram = $this->account(Channel::INSTAGRAM, '2020202020');

        $this->messenger = app(MessengerDriver::class);
        $this->instagramDriver = app(InstagramDriver::class);
    }

    // ───────────────────── التوقيع ─────────────────────

    /**
     * التوقيع يُحسب على الجسم الخام لا على المصفوفة المُحلَّلة.
     *
     * إعادة ترميز JSON تُغيّر البايتات (ترتيب المفاتيح، التهريب) فيفشل
     * التطابق على حمولات صحيحة تماماً.
     */
    public function test_the_signature_is_computed_over_the_raw_body(): void
    {
        $body = json_encode(['object' => 'page', 'entry' => []]);
        $signature = 'sha256=' . hash_hmac('sha256', $body, self::APP_SECRET);

        $request = Request::create('/webhook/channel/messenger', 'POST', [], [], [], [], $body);
        $request->headers->set('X-Hub-Signature-256', $signature);

        $this->assertTrue($this->messenger->verifyWebhook($request, $this->page));
    }

    /** وتوقيع خاطئ أو غائب يُرفض. */
    public function test_a_wrong_or_missing_signature_is_rejected(): void
    {
        $body = json_encode(['object' => 'page']);

        $wrong = Request::create('/webhook/channel/messenger', 'POST', [], [], [], [], $body);
        $wrong->headers->set('X-Hub-Signature-256', 'sha256=' . hash_hmac('sha256', $body, 'other-secret'));
        $this->assertFalse($this->messenger->verifyWebhook($wrong, $this->page));

        $missing = Request::create('/webhook/channel/messenger', 'POST', [], [], [], [], $body);
        $this->assertFalse($this->messenger->verifyWebhook($missing, $this->page));
    }

    /** تحدّي التحقّق الأولي يُردّ فقط حين يطابق الرمز المخزَّن. */
    public function test_the_verification_challenge_matches_the_stored_token(): void
    {
        $this->page->mergeCredentials(['verify_token' => 'my-verify-token']);

        $good = Request::create('/webhook/channel/messenger', 'GET', [
            'hub_mode' => 'subscribe',
            'hub_verify_token' => 'my-verify-token',
            'hub_challenge' => '1158201444',
        ]);
        $this->assertSame('1158201444', $this->messenger->webhookChallenge($good, $this->page));

        $bad = Request::create('/webhook/channel/messenger', 'GET', [
            'hub_mode' => 'subscribe',
            'hub_verify_token' => 'wrong',
            'hub_challenge' => '1158201444',
        ]);
        $this->assertNull($this->messenger->webhookChallenge($bad, $this->page));
    }

    // ───────────────────── التوجيه ─────────────────────

    /** معرّف الحساب يُستخرج من الحمولة لتوجيهها إلى المنشأة الصحيحة. */
    public function test_the_account_is_resolved_from_the_payload(): void
    {
        $request = $this->request(['object' => 'page', 'entry' => [['id' => '1010101010', 'messaging' => []]]]);
        $this->assertSame('1010101010', $this->messenger->resolveAccountExternalId($request));

        // حمولة إنستغرام لا يلتقطها سائق Messenger والعكس.
        $this->assertNull($this->instagramDriver->resolveAccountExternalId($request));
    }

    /** وحمولة قناة أخرى لا تُقرأ بالخطأ. */
    public function test_a_payload_for_another_channel_is_not_parsed(): void
    {
        $request = $this->request(['object' => 'instagram', 'entry' => [['id' => '2020202020', 'messaging' => []]]]);

        $this->assertSame([], $this->messenger->parseWebhook($request, $this->page));
    }

    // ───────────────────── قراءة الوارد ─────────────────────

    /** رسالة نصّية تخرج بشكل واتساب، والوقت يُحوَّل من ميلي ثانية. */
    public function test_a_messenger_text_is_normalised(): void
    {
        $events = $this->messenger->parseWebhook(
            $this->request($this->envelope('page', '1010101010', [
                'sender' => ['id' => 'PSID-777'],
                'recipient' => ['id' => '1010101010'],
                'timestamp' => 1758600000123,
                'message' => ['mid' => 'm_abc', 'text' => 'عندي استفسار'],
            ])),
            $this->page
        );

        $this->assertCount(1, $events);
        $message = $events[0];
        $this->assertInstanceOf(InboundMessage::class, $message);
        $this->assertSame(['type' => 'text', 'text' => ['body' => 'عندي استفسار']], $message->metadata);
        $this->assertSame('PSID-777', $message->senderId);
        $this->assertSame('fb:1010101010:m_abc', $message->providerMessageId);
        $this->assertFalse($message->isEcho);

        // ميتا ترسل الوقت بالميلي ثانية — لو قرأناه ثوانيَ لصارت الرسائل في 1970.
        $this->assertSame('2025-09-23', $message->sentAt->format('Y-m-d'));
    }

    /** المرفق يحمل رابطاً مباشراً مؤقّتاً لا معرّفاً يحتاج نداءً. */
    public function test_an_attachment_carries_a_direct_url(): void
    {
        $message = $this->messenger->parseWebhook(
            $this->request($this->envelope('page', '1010101010', [
                'sender' => ['id' => 'PSID-777'],
                'recipient' => ['id' => '1010101010'],
                'timestamp' => 1758600000123,
                'message' => [
                    'mid' => 'm_img',
                    'attachments' => [[
                        'type' => 'image',
                        'payload' => ['url' => 'https://cdn.fbsbx.com/x.jpg'],
                    ]],
                ],
            ])),
            $this->page
        )[0];

        $this->assertSame('image', $message->metadata['type']);
        $this->assertTrue($message->hasMedia());
        $this->assertSame('https://cdn.fbsbx.com/x.jpg', $message->media->url);
        $this->assertNull($message->media->providerId);
    }

    /** الصدى يُعلَّم، وجهة الاتصال فيه هي المستقبِل لا المُرسِل. */
    public function test_an_echo_is_flagged_and_points_at_the_recipient(): void
    {
        $message = $this->messenger->parseWebhook(
            $this->request($this->envelope('page', '1010101010', [
                'sender' => ['id' => '1010101010'],
                'recipient' => ['id' => 'PSID-777'],
                'timestamp' => 1758600000123,
                'message' => ['mid' => 'm_echo', 'is_echo' => true, 'text' => 'ردّ من صندوق فيسبوك'],
            ])),
            $this->page
        )[0];

        $this->assertTrue($message->isEcho);
        $this->assertSame('PSID-777', $message->senderId);
    }

    /** حدث التسليم يُقرأ حالةً لا رسالة. */
    public function test_a_delivery_event_becomes_a_status(): void
    {
        $status = $this->messenger->parseWebhook(
            $this->request($this->envelope('page', '1010101010', [
                'sender' => ['id' => 'PSID-777'],
                'recipient' => ['id' => '1010101010'],
                'timestamp' => 1758600000123,
                'delivery' => ['mids' => ['m_abc'], 'watermark' => 1758600000123],
            ])),
            $this->page
        )[0];

        $this->assertInstanceOf(InboundStatus::class, $status);
        $this->assertSame('delivered', $status->status);
        $this->assertSame(['fb:1010101010:m_abc'], $status->providerMessageIds);
    }

    /** وحدث القراءة يأتي بعلامة مائية بلا معرّفات. */
    public function test_a_read_event_is_a_watermark(): void
    {
        $status = $this->messenger->parseWebhook(
            $this->request($this->envelope('page', '1010101010', [
                'sender' => ['id' => 'PSID-777'],
                'recipient' => ['id' => '1010101010'],
                'timestamp' => 1758600000123,
                'read' => ['watermark' => 1758600000123],
            ])),
            $this->page
        )[0];

        $this->assertSame('read', $status->status);
        $this->assertTrue($status->isWatermark());
        $this->assertSame('PSID-777', $status->senderId);
    }

    /** نقرة الزرّ تُقرأ رسالةً من نوع button — فيراها الردّ الآلي. */
    public function test_a_postback_becomes_a_button_message(): void
    {
        $message = $this->messenger->parseWebhook(
            $this->request($this->envelope('page', '1010101010', [
                'sender' => ['id' => 'PSID-777'],
                'recipient' => ['id' => '1010101010'],
                'timestamp' => 1758600000123,
                'postback' => ['mid' => 'm_pb', 'title' => 'تتبّع طلبي', 'payload' => 'TRACK_ORDER'],
            ])),
            $this->page
        )[0];

        $this->assertSame('button', $message->metadata['type']);
        $this->assertSame('تتبّع طلبي', $message->metadata['button']['text']);
        $this->assertSame('TRACK_ORDER', $message->metadata['button']['payload']);
    }

    // ───────────────────── إنستغرام ─────────────────────

    /** إنستغرام تقرأ object الخاصّ بها. */
    public function test_instagram_reads_its_own_object(): void
    {
        $message = $this->instagramDriver->parseWebhook(
            $this->request($this->envelope('instagram', '2020202020', [
                'sender' => ['id' => 'IGSID-999'],
                'recipient' => ['id' => '2020202020'],
                'timestamp' => 1758600000123,
                'message' => ['mid' => 'm_ig', 'text' => 'كم السعر؟'],
            ])),
            $this->instagram
        )[0];

        $this->assertSame('ig:2020202020:m_ig', $message->providerMessageId);
        $this->assertSame('IGSID-999', $message->senderId);
    }

    /**
     * ذكر القصّة يصل مرفقاً بلا نصّ. عرضه صورةً مجرّدة يُربك الموظّف،
     * فنُضيف سطراً يوضّح ما هو.
     */
    public function test_a_story_mention_explains_itself(): void
    {
        $message = $this->instagramDriver->parseWebhook(
            $this->request($this->envelope('instagram', '2020202020', [
                'sender' => ['id' => 'IGSID-999'],
                'recipient' => ['id' => '2020202020'],
                'timestamp' => 1758600000123,
                'message' => [
                    'mid' => 'm_story',
                    'attachments' => [[
                        'type' => 'story_mention',
                        'payload' => ['url' => 'https://cdn.example.com/story.jpg'],
                    ]],
                ],
            ])),
            $this->instagram
        )[0];

        $this->assertSame('image', $message->metadata['type']);
        $this->assertNotEmpty($message->metadata['image']['caption']);
        $this->assertSame('https://cdn.example.com/story.jpg', $message->media->url);
    }

    /** الريل يُعامل فيديو لا مستنداً. */
    public function test_a_reel_is_treated_as_video(): void
    {
        $message = $this->instagramDriver->parseWebhook(
            $this->request($this->envelope('instagram', '2020202020', [
                'sender' => ['id' => 'IGSID-999'],
                'recipient' => ['id' => '2020202020'],
                'timestamp' => 1758600000123,
                'message' => [
                    'mid' => 'm_reel',
                    'attachments' => [['type' => 'ig_reel', 'payload' => ['url' => 'https://cdn.example.com/r.mp4']]],
                ],
            ])),
            $this->instagram
        )[0];

        $this->assertSame('video', $message->metadata['type']);
    }

    // ───────────────────── الإرسال ─────────────────────

    /** الإرسال يمرّ على نقطة الرسائل الخاصّة بالحساب ويُرجع معرّفاً مُنمَّقاً. */
    public function test_sending_text_hits_the_account_messages_endpoint(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['recipient_id' => 'PSID-777', 'message_id' => 'm_sent'], 200),
        ]);

        $result = $this->messenger->sendText($this->page, $this->contact(Channel::MESSENGER, 'PSID-777'), 'تفضّل');

        $this->assertTrue($result->success);
        $this->assertSame('fb:1010101010:m_sent', $result->providerMessageId);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/1010101010/messages')
            && $request['recipient']['id'] === 'PSID-777'
            && $request['message']['text'] === 'تفضّل'
            && $request['messaging_type'] === 'RESPONSE');
    }

    /**
     * ميتا لا تقبل تعليقاً مع المرفق، فالتعليق يُرسل رسالةً تالية بدل أن
     * يُسقَط صامتاً.
     */
    public function test_a_caption_is_sent_as_a_second_message(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['message_id' => 'm_media'], 200),
        ]);

        $media = new OutboundMedia(type: 'image', url: 'https://cdn.example.com/a.jpg', caption: 'صورة المنتج');
        $result = $this->messenger->sendMedia($this->page, $this->contact(Channel::MESSENGER, 'PSID-777'), $media);

        $this->assertTrue($result->success);
        Http::assertSentCount(2);
    }

    /** انتهاء النافذة يُترجَم إلى رسالة مفهومة لا إلى رمز ميتا خام. */
    public function test_a_closed_window_error_is_translated(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'error' => ['code' => 10, 'message' => 'This message is sent outside of allowed window.'],
            ], 400),
        ]);

        $result = $this->messenger->sendText($this->page, $this->contact(Channel::MESSENGER, 'PSID-777'), 'مرحبا');

        $this->assertFalse($result->success);
        $this->assertSame('10', $result->errorCode);
        $this->assertStringContainsString('24-hour', $result->errorMessage);
    }

    /** وتوكن مسحوب يضع الحساب في حالة «يحتاج إعادة ربط». */
    public function test_a_revoked_token_marks_the_account_for_reauthorization(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'error' => ['code' => 190, 'message' => 'Error validating access token.'],
            ], 401),
        ]);

        $this->messenger->sendText($this->page, $this->contact(Channel::MESSENGER, 'PSID-777'), 'مرحبا');

        $this->assertTrue($this->page->fresh()->needsReauthorization());
    }

    // ───────────────────── القدرات ─────────────────────

    /** الفروق المعلنة بين المنصّتين تطابق ما توثّقه ميتا. */
    public function test_capabilities_differ_where_the_platforms_differ(): void
    {
        $messenger = $this->messenger->capabilities();
        $instagram = $this->instagramDriver->capabilities();

        $this->assertSame(24, $messenger->windowHours);
        $this->assertSame(24, $instagram->windowHours);

        // لا تعليق مع المرفق في أيٍّ منهما.
        $this->assertFalse($messenger->mediaCaption);
        $this->assertFalse($instagram->mediaCaption);

        // Messenger تُرسل حدث تسليم، وإنستغرام لا.
        $this->assertTrue($messenger->deliveryReceipts);
        $this->assertFalse($instagram->deliveryReceipts);

        // إنستغرام لا تقبل مستندات، وحدّ صورتها أضيق.
        $this->assertTrue($messenger->supportsMedia('document'));
        $this->assertFalse($instagram->supportsMedia('document'));
        $this->assertSame(8 * 1024 * 1024, $instagram->mediaLimit('image'));
    }

    // ───────────────────── مساعدات ─────────────────────

    private function account(string $channel, string $externalId): ChannelAccount
    {
        return ChannelAccount::create([
            'organization_id' => $this->organization->id,
            'channel' => $channel,
            'external_id' => $externalId,
            'name' => 'حساب تجريبي',
            'credentials' => ['access_token' => 'page-token', 'app_secret' => self::APP_SECRET],
            'status' => ChannelAccount::STATUS_ACTIVE,
            'created_by' => 0,
        ]);
    }

    private function envelope(string $object, string $accountId, array $messaging): array
    {
        return [
            'object' => $object,
            'entry' => [[
                'id' => $accountId,
                'time' => 1758600000123,
                'messaging' => [$messaging],
            ]],
        ];
    }

    private function request(array $payload): Request
    {
        return Request::create('/webhook/channel/meta', 'POST', $payload);
    }

    private function contact(string $channel, string $identifier): Contact
    {
        return Contact::create([
            'organization_id' => $this->organization->id,
            'channel' => $channel,
            'channel_identifier' => $identifier,
            'phone' => null,
            'created_by' => 0,
        ]);
    }
}
