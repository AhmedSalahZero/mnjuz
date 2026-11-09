<?php

namespace Tests\Feature\Channels;

use App\Events\NewChatEvent;
use App\Models\ChannelAccount;
use App\Models\Chat;
use App\Models\ChatLog;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Setting;
use App\Models\Team;
use App\Models\User;
use App\Services\ChatService;
use App\Services\Messaging\Channel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * إرسال الموظّف من الويب على القنوات الجديدة.
 *
 * ChatService هو المسار الذي تضغط عليه الواجهة. الاختبار هنا يُثبّت ثلاثة
 * أشياء: أن الرسالة تصل المنصّة الصحيحة، وأنها تُسجَّل بنفس صفوف واتساب،
 * وأن ما لا تدعمه القناة يُرفض برسالة مفهومة قبل أي نداء شبكة.
 */
class ChatServiceChannelTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;
    private User $owner;
    private ChannelAccount $telegram;
    private ChatService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['role' => 'user']);
        $this->organization = Organization::factory()->create(['created_by' => $this->owner->id]);

        Team::factory()->create([
            'user_id' => $this->owner->id,
            'organization_id' => $this->organization->id,
            'role' => 'owner',
        ]);

        Setting::updateOrCreate(['key' => 'storage_system'], ['value' => 'local']);

        $this->telegram = ChannelAccount::create([
            'organization_id' => $this->organization->id,
            'channel' => Channel::TELEGRAM,
            'external_id' => 'bot-9',
            'credentials' => ['bot_token' => 'tok', 'webhook_secret' => 's'],
            'status' => ChannelAccount::STATUS_ACTIVE,
            'created_by' => 0,
        ]);

        $this->actingAs($this->owner);
        session(['current_organization' => $this->organization->id]);

        $this->service = new ChatService($this->organization->id);
    }

    // ───────────────────── الإرسال ─────────────────────

    /** رسالة نصّية تصل تيليجرام وتُسجَّل بنفس صفوف واتساب. */
    public function test_a_text_message_reaches_telegram_and_is_recorded(): void
    {
        Event::fake([NewChatEvent::class]);
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 77]], 200),
        ]);

        $contact = $this->telegramContact();

        $result = $this->service->sendMessage($this->request($contact, ['message' => 'أهلاً بك']));

        $this->assertTrue($result->success);

        $chat = Chat::where('contact_id', $contact->id)->first();
        $this->assertNotNull($chat);
        $this->assertSame('outbound', $chat->type);
        $this->assertSame(Channel::TELEGRAM, $chat->channel);
        $this->assertSame('tg:bot-9:tg-777:77', $chat->wam_id);
        $this->assertSame((int) $this->owner->id, (int) $chat->user_id);
        $this->assertStringContainsString('أهلاً بك', $chat->metadata);

        // سطر chat_logs هو ما تبني عليه الواجهة الخيط الزمني.
        $this->assertSame(1, ChatLog::where('entity_type', 'chat')->where('entity_id', $chat->id)->count());

        Event::assertDispatched(NewChatEvent::class);
    }

    /** ورسالة فارغة تُرفض قبل أي نداء. */
    public function test_an_empty_message_is_refused(): void
    {
        Http::fake();

        $response = $this->service->sendMessage($this->request($this->telegramContact(), ['message' => '   ']));

        $this->assertSame(422, $response->getStatusCode());
        Http::assertNothingSent();
    }

    /** وفشل المنصّة يصل الواجهة برسالته لا برمز غامض. */
    public function test_a_platform_failure_is_surfaced_to_the_agent(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => false,
                'error_code' => 403,
                'description' => 'Forbidden: bot was blocked by the user',
            ], 403),
        ]);

        $response = $this->service->sendMessage($this->request($this->telegramContact(), ['message' => 'مرحبا']));

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('blocked', strtolower($response->getData()->message));

        // ولا يُسجَّل صفّ لرسالة لم تصل.
        $this->assertSame(0, Chat::count());
    }

    /** وقناة غير مربوطة تُرفض برسالة تقول ماذا يفعل الموظّف. */
    public function test_an_unconnected_channel_is_refused_with_guidance(): void
    {
        Http::fake();

        $contact = Contact::create([
            'organization_id' => $this->organization->id,
            'channel' => Channel::MESSENGER,
            'channel_identifier' => 'PSID-1',
            'phone' => null,
            'created_by' => 0,
        ]);

        $response = $this->service->sendMessage($this->request($contact, ['message' => 'مرحبا']));

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('Settings', $response->getData()->message);
        Http::assertNothingSent();
    }

    // ───────────────────── بوّابات القدرات ─────────────────────

    /** القوالب مرفوضة على تيليجرام. */
    public function test_templates_are_refused_on_telegram(): void
    {
        $contact = $this->telegramContact();

        $result = $this->service->sendTemplateMessage(
            (object) ['template' => 'no-such-template', 'header' => ['format' => 'TEXT', 'parameters' => []]],
            $contact->uuid
        );

        $this->assertFalse($result->success);
        $this->assertStringContainsString('Telegram', $result->message);
    }

    /** وطلب الموقع كذلك — لا مقابل له خارج واتساب. */
    public function test_location_requests_are_refused_on_telegram(): void
    {
        Http::fake();

        $response = $this->service->requestLocation($this->telegramContact()->uuid, 'أرسل موقعك');

        $this->assertSame(422, $response->getStatusCode());
        Http::assertNothingSent();
    }

    /** والحظر كذلك. */
    public function test_blocking_is_refused_on_telegram(): void
    {
        $response = $this->service->blockContact($this->organization, $this->telegramContact());

        $this->assertSame(422, $response->getStatusCode());
    }

    /**
     * أمّا إرسال الموقع فتدعمه تيليجرام فعلاً — فيمرّ ويُسجَّل بنفس شكل
     * الموقع الوارد، فتعرضه الفقاعة بلا كود جديد.
     */
    public function test_sending_a_location_works_on_telegram(): void
    {
        Event::fake([NewChatEvent::class]);
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 88]], 200),
        ]);

        $contact = $this->telegramContact();

        $result = $this->service->sendLocation($contact->uuid, [
            'latitude' => 21.4858,
            'longitude' => 39.1925,
            'name' => 'المقرّ',
        ], $this->owner->id);

        $this->assertTrue($result->success);

        $chat = Chat::where('contact_id', $contact->id)->first();
        $metadata = json_decode($chat->metadata, true);

        $this->assertSame('location', $metadata['type']);
        $this->assertSame(21.4858, $metadata['location']['latitude']);
        $this->assertSame('المقرّ', $metadata['location']['name']);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/sendLocation'));
    }

    /** وإرسال الموقع مرفوض على ميسنجر — ميتا لا تُتيحه. */
    public function test_sending_a_location_is_refused_on_messenger(): void
    {
        Http::fake();

        $contact = Contact::create([
            'organization_id' => $this->organization->id,
            'channel' => Channel::MESSENGER,
            'channel_identifier' => 'PSID-2',
            'phone' => null,
            'created_by' => 0,
        ]);

        $response = $this->service->sendLocation($contact->uuid, [
            'latitude' => 21.4858,
            'longitude' => 39.1925,
        ]);

        $this->assertSame(422, $response->getStatusCode());
        Http::assertNothingSent();
    }

    // ───────────────────── النافذة ─────────────────────

    /**
     * نافذة تيليجرام مفتوحة دائماً، فالإرسال يمرّ لجهة اتصال لم تراسلنا
     * منذ شهر — بخلاف واتساب.
     */
    public function test_telegram_has_no_window_so_an_old_conversation_still_accepts_messages(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 99]], 200),
        ]);

        $contact = $this->telegramContact();
        $contact->update(['last_inbound_chat_created_at' => now()->subMonth()]);

        $result = $this->service->sendMessage($this->request($contact, ['message' => 'تذكير']));

        $this->assertTrue($result->success);
    }

    /**
     * ورسالة النافذة المغلقة على قناة أخرى لا تذكر واتساب ولا القوالب —
     * ذكرهما يُرسل الموظّف يبحث عمّا لا وجود له.
     */
    public function test_the_closed_window_message_names_the_right_channel(): void
    {
        $contact = Contact::create([
            'organization_id' => $this->organization->id,
            'channel' => Channel::MESSENGER,
            'channel_identifier' => 'PSID-3',
            'phone' => null,
            'created_by' => 0,
        ]);

        $message = \App\Helpers\MessagingWindowHelper::closedWindowMessage($contact);

        $this->assertStringContainsString('Messenger', $message);
        $this->assertStringNotContainsString('Whatsapp', $message);
        $this->assertStringNotContainsString('template', strtolower($message));
    }

    /** ونصّ واتساب يبقى كما كان حرفياً — ملفّ اللغة يترجمه اليوم. */
    public function test_the_whatsapp_closed_window_message_is_unchanged(): void
    {
        $whatsapp = Contact::create([
            'organization_id' => $this->organization->id,
            'phone' => '+966500000099',
            'created_by' => 0,
        ]);

        $this->assertSame(
            __('Whatsapp does not allow sending messages 24 hours after they last messaged you. However, you can send them a template message'),
            \App\Helpers\MessagingWindowHelper::closedWindowMessage($whatsapp)
        );

        // وبلا جهة اتصال كذلك — المسارات القائمة تُناديها هكذا.
        $this->assertSame(
            __('Whatsapp does not allow sending messages 24 hours after they last messaged you. However, you can send them a template message'),
            \App\Helpers\MessagingWindowHelper::closedWindowMessage()
        );
    }

    // ───────────────────── مساعدات ─────────────────────

    private function telegramContact(): Contact
    {
        return Contact::create([
            'organization_id' => $this->organization->id,
            'channel' => Channel::TELEGRAM,
            'channel_identifier' => 'tg-777',
            'channel_account_id' => $this->telegram->id,
            'phone' => null,
            'first_name' => 'أحمد',
            'created_by' => 0,
        ]);
    }

    private function request(Contact $contact, array $data): Request
    {
        return Request::create('/chats', 'POST', array_merge(['uuid' => $contact->uuid], $data));
    }
}
