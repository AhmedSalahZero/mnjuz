<?php

namespace Tests\Feature\Channels;

use App\Models\Campaign;
use App\Models\ChannelAccount;
use App\Models\Chat;
use App\Models\Contact;
use App\Models\ContactGroup;
use App\Models\Organization;
use App\Models\User;
use App\Services\CampaignAudienceService;
use App\Services\Messaging\Channel;
use App\Services\Messaging\ChannelCampaignSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * الحملات على القنوات الجديدة.
 *
 * أخطر نقطة هنا الجمهور: المجموعة الواحدة قد تجمع عملاء واتساب وتيليجرام،
 * وحملة واتساب تُرسَل إلى من لا رقم له تُنتج طلباً بـ to=null ترفضه Meta —
 * فتظهر الحملة فاشلةً جزئياً بلا سبب مفهوم.
 */
class ChannelCampaignTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;
    private User $owner;
    private ChannelAccount $telegram;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['role' => 'user']);
        $this->organization = Organization::factory()->create(['created_by' => $this->owner->id]);

        $this->telegram = ChannelAccount::create([
            'organization_id' => $this->organization->id,
            'channel' => Channel::TELEGRAM,
            'external_id' => 'bot-c',
            'credentials' => ['bot_token' => 'tok'],
            'status' => ChannelAccount::STATUS_ACTIVE,
            'created_by' => 0,
        ]);
    }

    // ───────────────────── الجمهور ─────────────────────

    /** حملة واتساب لا تستهدف جهات القنوات الأخرى. */
    public function test_a_whatsapp_campaign_excludes_other_channels(): void
    {
        $this->contact(Channel::WHATSAPP, '+966500000001');
        $this->contact(Channel::TELEGRAM, null, 'tg-1');
        $this->contact(Channel::TELEGRAM, null, 'tg-2');

        $audience = CampaignAudienceService::query((int) $this->organization->id, null, Channel::WHATSAPP)->get();

        $this->assertCount(1, $audience);
        $this->assertSame('+966500000001', $audience->first()->phone);
    }

    /** وحملة تيليجرام لا تستهدف جهات واتساب. */
    public function test_a_telegram_campaign_excludes_whatsapp_contacts(): void
    {
        $this->contact(Channel::WHATSAPP, '+966500000001');
        $this->contact(Channel::TELEGRAM, null, 'tg-1');

        $audience = CampaignAudienceService::query((int) $this->organization->id, null, Channel::TELEGRAM)->get();

        $this->assertCount(1, $audience);
        $this->assertSame('tg-1', $audience->first()->channel_identifier);
    }

    /** والافتراضي واتساب — فكل نداء قائم يبقى صحيحاً. */
    public function test_the_default_audience_channel_is_whatsapp(): void
    {
        $this->contact(Channel::WHATSAPP, '+966500000001');
        $this->contact(Channel::TELEGRAM, null, 'tg-1');

        $this->assertSame(1, CampaignAudienceService::count((int) $this->organization->id, null));
    }

    /** والانسحاب من التسويق محترَم على كل القنوات. */
    public function test_opted_out_contacts_are_excluded_on_every_channel(): void
    {
        $contact = $this->contact(Channel::TELEGRAM, null, 'tg-1');
        $contact->update(['marketing_opted_out_at' => now()]);

        $this->assertTrue(CampaignAudienceService::isEmpty((int) $this->organization->id, null, Channel::TELEGRAM));
    }

    /** والتصفية تعمل داخل مجموعة بعينها كذلك. */
    public function test_the_channel_filter_applies_inside_a_contact_group(): void
    {
        $group = ContactGroup::create([
            'organization_id' => $this->organization->id,
            'name' => 'مجموعة مختلطة',
            'created_by' => $this->owner->id,
        ]);

        $whatsapp = $this->contact(Channel::WHATSAPP, '+966500000001');
        $telegram = $this->contact(Channel::TELEGRAM, null, 'tg-1');

        $whatsapp->contactGroups()->attach($group->id);
        $telegram->contactGroups()->attach($group->id);

        $this->assertSame(1, CampaignAudienceService::count((int) $this->organization->id, $group->id, Channel::TELEGRAM));
        $this->assertSame(1, CampaignAudienceService::count((int) $this->organization->id, $group->id, Channel::WHATSAPP));
    }

    // ───────────────────── نموذج الحملة ─────────────────────

    /** الحملة القائمة (بلا ذكر قناة) حملة واتساب بقالب. */
    public function test_a_legacy_campaign_is_a_whatsapp_template_campaign(): void
    {
        $campaign = new Campaign(['organization_id' => $this->organization->id]);

        $this->assertSame(Channel::WHATSAPP, $campaign->channel);
        $this->assertTrue($campaign->isTemplateCampaign());
    }

    /** وحملة القناة الأخرى تحمل رسالتها الحرّة. */
    public function test_a_channel_campaign_carries_its_free_message(): void
    {
        $campaign = $this->campaign(['text' => 'عرض اليوم', 'media' => null]);

        $this->assertFalse($campaign->isTemplateCampaign());
        $this->assertSame('عرض اليوم', $campaign->messagePayload()['text']);
        $this->assertNull($campaign->messagePayload()['media']);
    }

    /** وحملة بلا رسالة محفوظة لا تنهار. */
    public function test_a_campaign_without_a_payload_returns_an_empty_message(): void
    {
        $campaign = new Campaign(['channel' => Channel::TELEGRAM]);

        $this->assertSame(['text' => '', 'media' => null], $campaign->messagePayload());
    }

    // ───────────────────── الإرسال ─────────────────────

    /** رسالة الحملة تصل المنصّة وتُسجَّل صفّاً صادراً. */
    public function test_a_campaign_message_is_sent_and_recorded(): void
    {
        // فقط حدث البثّ — Event::fake() بلا وسائط يُعطّل أحداث الموديل
        // كذلك، فلا يُولَّد uuid عند الإنشاء.
        Event::fake([\App\Events\NewChatEvent::class]);
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 501]], 200),
        ]);

        $campaign = $this->campaign(['text' => 'عرض اليوم', 'media' => null]);
        $contact = $this->contact(Channel::TELEGRAM, null, 'tg-1');

        $response = app(ChannelCampaignSender::class)->send($campaign, $contact, $this->owner->id);

        $this->assertTrue($response->success);

        $chat = Chat::where('contact_id', $contact->id)->first();
        $this->assertNotNull($chat);
        $this->assertSame('outbound', $chat->type);
        $this->assertSame(Channel::TELEGRAM, $chat->channel);
        $this->assertStringContainsString('عرض اليوم', $chat->metadata);

        // مسار الحملة يقرأ data->chat->id ليربط السجلّ بالرسالة.
        $this->assertSame($chat->id, $response->data->chat->id);
    }

    /** والمتغيّرات تُستبدل باسم العميل. */
    public function test_placeholders_are_resolved_per_contact(): void
    {
        // فقط حدث البثّ — Event::fake() بلا وسائط يُعطّل أحداث الموديل
        // كذلك، فلا يُولَّد uuid عند الإنشاء.
        Event::fake([\App\Events\NewChatEvent::class]);
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 502]], 200),
        ]);

        $campaign = $this->campaign(['text' => 'مرحباً {first_name}', 'media' => null]);
        $contact = $this->contact(Channel::TELEGRAM, null, 'tg-1');
        $contact->update(['first_name' => 'أحمد']);

        app(ChannelCampaignSender::class)->send($campaign, $contact->fresh(), $this->owner->id);

        Http::assertSent(fn ($request) => str_contains($request['text'] ?? '', 'أحمد'));
    }

    /**
     * والنافذة المغلقة تُفشل الرسالة بسبب صريح لا بخطأ المنصّة — فلا
     * تُعاد محاولة لن تنجح حتى يكتب العميل.
     */
    public function test_a_closed_window_fails_with_an_explicit_reason(): void
    {
        Http::fake();

        // ميسنجر نافذته ٢٤ ساعة، وجهة الاتصال لم تراسلنا قطّ.
        $account = ChannelAccount::create([
            'organization_id' => $this->organization->id,
            'channel' => Channel::MESSENGER,
            'external_id' => 'page-c',
            'credentials' => ['access_token' => 't'],
            'status' => ChannelAccount::STATUS_ACTIVE,
            'created_by' => 0,
        ]);

        $campaign = $this->campaign(['text' => 'عرض', 'media' => null], Channel::MESSENGER, $account->id);
        $contact = $this->contact(Channel::MESSENGER, null, 'PSID-1');

        $response = app(ChannelCampaignSender::class)->send($campaign, $contact, $this->owner->id);

        $this->assertFalse($response->success);
        $this->assertSame('window_closed', $response->data->error->code);
        Http::assertNothingSent();
        $this->assertSame(0, Chat::count());
    }

    /** وتيليجرام بلا نافذة، فالحملة تصل جهة اتصال لم تراسلنا منذ شهر. */
    public function test_telegram_campaigns_ignore_the_window(): void
    {
        // فقط حدث البثّ — Event::fake() بلا وسائط يُعطّل أحداث الموديل
        // كذلك، فلا يُولَّد uuid عند الإنشاء.
        Event::fake([\App\Events\NewChatEvent::class]);
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 503]], 200),
        ]);

        $campaign = $this->campaign(['text' => 'تذكير', 'media' => null]);
        $contact = $this->contact(Channel::TELEGRAM, null, 'tg-1');
        $contact->update(['last_inbound_chat_created_at' => now()->subMonth()]);

        $response = app(ChannelCampaignSender::class)->send($campaign, $contact->fresh(), $this->owner->id);

        $this->assertTrue($response->success);
    }

    /** وقناة غير مربوطة تُفشل الرسالة بلا نداء شبكة. */
    public function test_an_unconnected_channel_fails_without_calling_out(): void
    {
        Http::fake();

        $campaign = $this->campaign(['text' => 'عرض', 'media' => null], Channel::TIKTOK);
        $contact = $this->contact(Channel::TIKTOK, null, 'conv-1');

        $response = app(ChannelCampaignSender::class)->send($campaign, $contact, $this->owner->id);

        $this->assertFalse($response->success);
        Http::assertNothingSent();
    }

    /** وفشل المنصّة يصل بشكل يقرأه مسار الحملة القائم. */
    public function test_a_platform_failure_matches_the_legacy_response_shape(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => false,
                'error_code' => 403,
                'description' => 'Forbidden: bot was blocked by the user',
            ], 403),
        ]);

        $campaign = $this->campaign(['text' => 'عرض', 'media' => null]);
        $contact = $this->contact(Channel::TELEGRAM, null, 'tg-1');

        $response = app(ChannelCampaignSender::class)->send($campaign, $contact, $this->owner->id);

        $this->assertFalse($response->success);
        // CampaignRetryService::extractFailureReason يقرأ هذين المسارين.
        $this->assertObjectHasProperty('data', $response);
        $this->assertNotEmpty($response->data->error->message);
        $this->assertSame(0, Chat::count());
    }

    /** والوسائط تُرسل بنفس الرابط المخزَّن مرّة واحدة للحملة كلّها. */
    public function test_media_is_sent_from_the_stored_url(): void
    {
        // فقط حدث البثّ — Event::fake() بلا وسائط يُعطّل أحداث الموديل
        // كذلك، فلا يُولَّد uuid عند الإنشاء.
        Event::fake([\App\Events\NewChatEvent::class]);
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 504]], 200),
        ]);

        $campaign = $this->campaign([
            'text' => 'تفضّل',
            'media' => [
                'url' => 'https://cdn.example.com/offer.jpg',
                'name' => 'offer.jpg',
                'type' => 'image',
                'mime_type' => 'image/jpeg',
                'size' => 1024,
                'location' => 'amazon',
            ],
        ]);

        $contact = $this->contact(Channel::TELEGRAM, null, 'tg-1');

        $response = app(ChannelCampaignSender::class)->send($campaign, $contact, $this->owner->id);

        $this->assertTrue($response->success);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/sendPhoto')
            && $request['photo'] === 'https://cdn.example.com/offer.jpg');

        $chat = Chat::where('contact_id', $contact->id)->first();
        $this->assertNotNull($chat->media_id);
    }

    // ───────────────────── مساعدات ─────────────────────

    private function campaign(array $payload, string $channel = Channel::TELEGRAM, ?int $accountId = null): Campaign
    {
        $campaign = new Campaign;
        $campaign->organization_id = $this->organization->id;
        $campaign->name = 'حملة تجريبية';
        $campaign->channel = $channel;
        $campaign->channel_account_id = $accountId ?? ($channel === Channel::TELEGRAM ? $this->telegram->id : null);
        $campaign->template_id = null;
        $campaign->contact_group_id = 0;
        $campaign->metadata = '{}';
        $campaign->message_payload = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $campaign->created_by = $this->owner->id;
        $campaign->status = 'scheduled';
        $campaign->scheduled_at = now();
        $campaign->save();

        return $campaign;
    }

    private function contact(string $channel, ?string $phone, ?string $identifier = null): Contact
    {
        return Contact::create([
            'organization_id' => $this->organization->id,
            'channel' => $channel,
            'channel_identifier' => $identifier,
            'channel_account_id' => $channel === Channel::TELEGRAM ? $this->telegram->id : null,
            'phone' => $phone,
            'first_name' => 'عميل',
            'created_by' => 0,
        ]);
    }
}
