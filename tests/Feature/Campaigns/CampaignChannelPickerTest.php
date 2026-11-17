<?php

namespace Tests\Feature\Campaigns;

use App\Models\Campaign;
use App\Models\CampaignLog;
use App\Models\ChannelAccount;
use App\Models\Contact;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * اختيار قناة الحملة: واتساب (الافتراضية)، تيليجرام، البريد، وSMS قريباً.
 * اختيار واحد، ولكل قناة شكل رسالتها. حملة بلا قناة تبقى واتساب كما كانت —
 * تطبيق الجوال لا يرسل قناة.
 */
class CampaignChannelPickerTest extends CampaignTestCase
{
    private ChannelAccount $bot;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 901]], 200)]);

        $this->bot = ChannelAccount::create([
            'organization_id' => $this->organization->id, 'channel' => 'telegram', 'external_id' => 'bot-1',
            'credentials' => ['bot_token' => 'tok', 'webhook_updates' => 'callback_query'],
            'status' => ChannelAccount::STATUS_ACTIVE, 'created_by' => 0,
        ]);
    }

    /** مرفقات الحملات تُكتب في storage/app/public فعلاً: لا تبقى بعد الاختبار. */
    protected function tearDown(): void
    {
        foreach (Campaign::all() as $campaign) {
            $url = $campaign->messagePayload()['media']['url'] ?? null;

            if ($url) {
                \Illuminate\Support\Facades\Storage::disk('local')->delete(Str::after((string) parse_url($url, PHP_URL_PATH), '/media/'));
            }
        }

        parent::tearDown();
    }

    private function asOwner(): static
    {
        return $this->actingAs($this->owner, 'user')
            ->withSession(['current_organization' => $this->organization->id]);
    }

    private function telegramCustomer(string $chatId = '5551'): Contact
    {
        return $this->customer('', 'سالم', [
            'channel' => 'telegram', 'channel_identifier' => $chatId, 'channel_account_id' => $this->bot->id, 'phone' => null,
        ]);
    }

    private function telegramForm(array $overrides = []): array
    {
        return array_merge([
            'name' => 'عرض تيليجرام',
            'channel' => 'telegram',
            'message_text' => 'عرض اليوم يا {first_name}',
            'contacts' => 'all',
            'skip_schedule' => true,
        ], $overrides);
    }

    public function test_the_create_page_offers_exactly_whatsapp_telegram_email_and_sms(): void
    {
        $response = $this->asOwner()->get('/campaigns/create')->assertOk();

        $options = collect($response->viewData('page')['props']['campaignChannels']);

        $this->assertSame(['whatsapp', 'telegram', 'email', 'sms'], $options->pluck('value')->all());

        $byChannel = $options->keyBy('value');
        $this->assertTrue($byChannel['whatsapp']['connected']);
        $this->assertTrue($byChannel['telegram']['connected']);
        $this->assertFalse($byChannel['email']['connected']);
        $this->assertSame('/settings/channels/email', $byChannel['email']['connect_url']);
        $this->assertTrue($byChannel['sms']['coming_soon']);
        $this->assertNull($byChannel['sms']['connect_url']);
        $this->assertSame(1024, $byChannel['telegram']['rules']['caption_max']);
        $this->assertSame('template', $byChannel['whatsapp']['rules']['kind']);
    }

    /** الموظّف يرى المنتقي لكن بلا روابط ربط — لا يدير القنوات. */
    public function test_an_agent_sees_no_connect_links(): void
    {
        $agent = User::factory()->create(['role' => 'user']);
        Team::factory()->create(['user_id' => $agent->id, 'organization_id' => $this->organization->id, 'role' => 'agent', 'created_by' => $this->owner->id]);

        $response = $this->actingAs($agent, 'user')
            ->withSession(['current_organization' => $this->organization->id])
            ->get('/campaigns/create')
            ->assertOk();

        foreach ($response->viewData('page')['props']['campaignChannels'] as $option) {
            $this->assertNull($option['connect_url'], $option['value']);
        }
    }

    /**
     * «غير متصل» ورابط «ربط» عنصران متجاوران بلا مسافة نصّية — Vue يحذف
     * المسافة بين العنصرين — فكانا يظهران «غير متصلربط». الفاصل الظاهر وgap
     * يفصلانهما على الشاشة وفي النصّ المنسوخ.
     */
    public function test_the_not_connected_status_is_separated_from_its_connect_link(): void
    {
        $form = file_get_contents(resource_path('js/Components/CampaignForm.vue'));

        $this->assertMatchesRegularExpression(
            '/class="[^"]*\bgap-x-\d[^"]*">\s*<span>\{\{ \$t\(\'Not connected\'\) \}\}<\/span>\s*'
            . '<template v-if="option\.connect_url">\s*<span aria-hidden="true">·<\/span>\s*<Link[^>]*>\s*\{\{ \$t\(\'Connect\'\) \}\}/',
            $form,
            'الحالة ورابط الربط بلا فاصل بينهما'
        );
    }

    /** بلا قناة = واتساب: نموذج الويب القديم وتطبيق الجوال. */
    public function test_a_campaign_without_a_channel_stays_whatsapp(): void
    {
        $this->customer('+966500000002', 'نورة');

        $this->createFromWeb($this->webForm($this->template(), 'all'))->assertRedirect();

        $this->assertSame('whatsapp', Campaign::latest('id')->first()->channel);
    }

    public function test_new_messenger_instagram_and_tiktok_campaigns_are_refused(): void
    {
        $this->telegramCustomer();

        foreach (['messenger', 'instagram', 'tiktok', 'sms', 'webchat'] as $channel) {
            $this->createFromWeb($this->telegramForm(['channel' => $channel]))
                ->assertStatus(422)
                ->assertJsonValidationErrors(['channel']);
        }

        $this->assertSame(0, Campaign::count());
    }

    /**
     * حملة ماسنجر قائمة قبل التقييد تكمل إرسالها: المهامّ تقرأ قناة الحملة
     * لا قائمة المنتقي.
     */
    public function test_an_existing_messenger_campaign_is_still_processed(): void
    {
        $messenger = ChannelAccount::create([
            'organization_id' => $this->organization->id, 'channel' => 'messenger', 'external_id' => 'page-1',
            'credentials' => ['access_token' => 't', 'verify_token' => 'v'],
            'status' => ChannelAccount::STATUS_ACTIVE, 'created_by' => 0,
        ]);
        $contact = $this->customer('', 'ليلى', ['channel' => 'messenger', 'channel_identifier' => 'psid-1', 'channel_account_id' => $messenger->id, 'phone' => null]);

        $campaign = Campaign::create([
            'uuid' => (string) Str::uuid(), 'organization_id' => $this->organization->id, 'name' => 'ماسنجر قديمة',
            'channel' => 'messenger', 'channel_account_id' => $messenger->id, 'template_id' => null, 'contact_group_id' => 0,
            'metadata' => '{}', 'message_payload' => json_encode(['text' => 'مرحباً', 'media' => null], JSON_UNESCAPED_UNICODE),
            'created_by' => $this->owner->id, 'status' => 'ongoing', 'scheduled_at' => now(),
        ]);
        $log = CampaignLog::create(['campaign_id' => $campaign->id, 'contact_id' => $contact->id, 'status' => 'pending']);

        $this->dispatcherTick();

        $this->assertNotSame('pending', $log->fresh()->status);
    }

    public function test_a_telegram_caption_is_limited_to_1024_characters_with_an_attachment(): void
    {
        $this->telegramCustomer();

        $this->createFromWeb($this->telegramForm([
            'message_text' => str_repeat('أ', 1025),
            'message_media' => UploadedFile::fake()->image('offer.jpg'),
        ]))->assertStatus(422)->assertJsonValidationErrors(['message_text']);

        $this->createFromWeb($this->telegramForm([
            'message_text' => str_repeat('أ', 1024),
            'message_media' => UploadedFile::fake()->image('offer.jpg'),
        ]))->assertRedirect();
    }

    public function test_a_telegram_text_without_attachment_allows_4096_characters(): void
    {
        $this->telegramCustomer();

        $this->createFromWeb($this->telegramForm(['message_text' => str_repeat('أ', 4096)]))->assertRedirect();
        $this->createFromWeb($this->telegramForm(['message_text' => str_repeat('أ', 4097)]))
            ->assertStatus(422)->assertJsonValidationErrors(['message_text']);
    }

    /** تيليجرام يجلب الصورة من رابطنا، وحدّه للصورة بالرابط 5MB. */
    public function test_a_telegram_photo_over_5mb_is_refused(): void
    {
        $this->telegramCustomer();

        $this->createFromWeb($this->telegramForm([
            'message_media' => UploadedFile::fake()->image('big.jpg')->size(6 * 1024),
        ]))->assertStatus(422)->assertJsonValidationErrors(['message_media']);
    }

    /** صفحة HTML مرفقاً تُعرض على نطاق التطبيق: تُرفض. */
    public function test_an_html_file_is_refused_as_a_telegram_attachment(): void
    {
        $this->telegramCustomer();

        $this->createFromWeb($this->telegramForm([
            'message_media' => UploadedFile::fake()->createWithContent('page.html', '<script>alert(1)</script>'),
        ]))->assertStatus(422)->assertJsonValidationErrors(['message_media']);
    }

    /**
     * رابط المرفق على التخزين المحلّي يُخدَم فعلاً. كان /media/1 لأن put()
     * يُرجع true، فيفشل إرسال كل مرفق تيليجرام.
     */
    public function test_a_telegram_attachment_url_is_served(): void
    {
        $this->telegramCustomer();

        $this->createFromWeb($this->telegramForm([
            'message_media' => UploadedFile::fake()->image('offer.png'),
        ]))->assertRedirect();

        $media = Campaign::latest('id')->first()->messagePayload()['media'];
        $this->assertNotNull($media);
        $this->assertStringContainsString('/media/public/', $media['url']);

        $path = parse_url($media['url'], PHP_URL_PATH);

        try {
            $this->get($path)->assertOk()->assertHeader('Content-Type', 'image/png');
        } finally {
            \Illuminate\Support\Facades\Storage::disk('local')->delete(Str::after($path, '/media/'));
        }
    }

    /** وحملة تيليجرام نصّية تصل البوت بمتغيّراتها. */
    public function test_a_telegram_campaign_reaches_the_bot(): void
    {
        $this->telegramCustomer('7771');

        $this->createFromWeb($this->telegramForm())->assertRedirect();
        $this->schedulerMinute();
        $this->dispatcherTick();

        Http::assertSent(fn ($request) => str_contains($request->url(), '/sendMessage')
            && (string) $request['chat_id'] === '7771'
            && $request['text'] === 'عرض اليوم يا سالم');
    }
}
