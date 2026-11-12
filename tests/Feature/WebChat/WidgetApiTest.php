<?php

namespace Tests\Feature\WebChat;

use App\Events\NewChatEvent;
use App\Jobs\ProcessAutoReplyJob;
use App\Jobs\ProcessWebChatInboundJob;
use App\Models\ChannelAccount;
use App\Models\Chat;
use App\Models\ChatTicket;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Team;
use App\Models\User;
use App\Models\WebChatVisitor;
use App\Services\ChatService;
use App\Services\Messaging\Channel;
use App\Services\WebChat\WebChatWidgetService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * الواجهة العامّة للـ widget: من نموذج الزائر حتى صفّ في صندوق المحادثات.
 */
class WidgetApiTest extends WebChatTestCase
{
    // ───────────────────── الـ widget ─────────────────────

    public function test_config_describes_the_widget_without_any_secret(): void
    {
        config(['broadcasting.default' => 'pusher']);
        $this->setSettings(['allowed_domains' => [], 'appearance' => ['color' => '#123ABC', 'title' => 'تحدّث معنا']]);

        $response = $this->getJson($this->url('config'))->assertOk();

        $response->assertJsonPath('widget.name', 'متجر الاختبار');
        $response->assertJsonPath('widget.appearance.color', '#123abc');
        $response->assertJsonPath('widget.appearance.title', 'تحدّث معنا');
        $response->assertJsonPath('available', true);
        $this->assertArrayHasKey('provider', $response->json('realtime'));

        $body = $response->getContent();
        $this->assertStringNotContainsString('allowed_domains', $body);
        $this->assertStringNotContainsString('credentials', $body);
        $this->assertStringNotContainsString('secret', strtolower($body));
    }

    public function test_every_refusal_is_the_same_404(): void
    {
        $this->getJson('/api/webchat/v1/w/not-a-key/config')->assertNotFound();
        $this->getJson('/api/webchat/v1/w/wc_' . str_repeat('a', 24) . '/config')->assertNotFound();

        $this->setSettings(['enabled' => false]);
        $this->getJson($this->url('config'))->assertNotFound()->assertExactJson(['error' => 'not_found']);
    }

    public function test_the_widget_is_unavailable_without_the_addon_in_the_plan(): void
    {
        $this->plan->update(['metadata' => json_encode(['addons' => []])]);
        Cache::flush();

        $this->getJson($this->url('config'))->assertNotFound();
    }

    public function test_a_telegram_account_key_is_not_a_widget(): void
    {
        ChannelAccount::create([
            'organization_id' => $this->organization->id,
            'channel' => Channel::TELEGRAM,
            'external_id' => 'wc_' . str_repeat('b', 24),
            'credentials' => ['bot_token' => 'x'],
            'status' => ChannelAccount::STATUS_ACTIVE,
            'created_by' => 0,
        ]);

        $this->getJson('/api/webchat/v1/w/wc_' . str_repeat('b', 24) . '/config')->assertNotFound();
    }

    // ───────────────────── المواقع المسموحة ─────────────────────

    public function test_allowed_domains_restrict_where_the_widget_works(): void
    {
        $this->setSettings(['allowed_domains' => ['https://www.Shop.example.com/path', '*.brand.sa']]);

        $this->getJson($this->url('config'), ['Origin' => 'https://evil.test'])->assertForbidden();
        $this->getJson($this->url('config'))->assertForbidden();

        $this->getJson($this->url('config'), ['Origin' => 'https://shop.example.com'])->assertOk();
        $this->getJson($this->url('config'), ['Origin' => 'https://www.shop.example.com'])->assertOk();
        $this->getJson($this->url('config'), ['Origin' => 'https://store.brand.sa'])->assertOk();
        $this->getJson($this->url('config'), ['Origin' => 'https://brand.sa'])->assertOk();
        $this->getJson($this->url('config'), ['Referer' => 'https://shop.example.com/cart'])->assertOk();
        // صفحة المعاينة عندنا.
        $this->getJson($this->url('config'), ['Origin' => rtrim(config('app.url'), '/')])->assertOk();

        $this->getJson($this->url('config'), ['Origin' => 'https://brand.sa.evil.test'])->assertForbidden();
    }

    // ───────────────────── النموذج ─────────────────────

    public function test_a_session_stores_the_visitor_with_a_hashed_token_and_e164_phone(): void
    {
        [$token, $visitor] = $this->openSession();

        $this->assertNotNull($visitor);
        $this->assertSame('+966501234567', $visitor->phone);
        $this->assertSame('سارة', $visitor->name);
        $this->assertSame(WebChatVisitor::hashToken($token), $visitor->token_hash);
        $this->assertSame('https://shop.example.com/products/1', $visitor->first_page_url);
        $this->assertNull($visitor->contact_id, 'جهة الاتصال تُنشأ مع أول رسالة لا مع النموذج.');
        $this->assertSame(0, Contact::where('channel', Channel::WEBCHAT)->count());
    }

    public function test_a_local_number_uses_the_widget_default_country(): void
    {
        [, $visitor] = $this->openSession(['phone' => '0501234567']);

        $this->assertSame('+966501234567', $visitor->phone);
    }

    public function test_the_form_rejects_missing_or_invalid_data_and_bots(): void
    {
        $valid = ['name' => 'سارة', 'phone' => '+966501234567', 'elapsed_ms' => 4000];

        $this->postJson($this->url('sessions'), ['name' => 'سارة', 'elapsed_ms' => 4000])->assertStatus(422)->assertJsonValidationErrors('phone');
        $this->postJson($this->url('sessions'), ['phone' => '+966501234567', 'elapsed_ms' => 4000])->assertStatus(422)->assertJsonValidationErrors('name');
        $this->postJson($this->url('sessions'), ['phone' => '12'] + $valid)->assertStatus(422)->assertJsonValidationErrors('phone');
        $this->postJson($this->url('sessions'), ['website' => 'http://spam'] + $valid)->assertStatus(422);
        $this->postJson($this->url('sessions'), ['elapsed_ms' => 200] + $valid)->assertStatus(422);

        $this->assertSame(0, WebChatVisitor::count());
    }

    public function test_email_and_consent_follow_the_widget_settings(): void
    {
        $this->setSettings(['prechat' => ['email' => 'required', 'consent_text' => 'أوافق على سياسة الخصوصية']]);
        $base = ['name' => 'سارة', 'phone' => '+966501234567', 'elapsed_ms' => 4000];

        $this->postJson($this->url('sessions'), $base + ['consent' => true])->assertStatus(422)->assertJsonValidationErrors('email');
        $this->postJson($this->url('sessions'), $base + ['email' => 'sara@example.com'])->assertStatus(422)->assertJsonValidationErrors('consent');
        $this->postJson($this->url('sessions'), $base + ['email' => 'Sara@Example.com', 'consent' => true])->assertCreated();

        $this->assertSame('sara@example.com', WebChatVisitor::first()->email);
    }

    // ───────────────────── حقل الجوال حسب إعداد التاجر ─────────────────────

    /** widget حُفظ قبل وجود الإعداد: لا مفتاح phone في إعداداته، فيبقى الرقم إجبارياً. */
    public function test_a_widget_saved_before_the_phone_setting_still_requires_the_phone(): void
    {
        $this->setSettings(['prechat' => ['default_country' => 'SA', 'email' => 'off']]);

        $this->getJson($this->url('config'))->assertOk()->assertJsonPath('widget.prechat.phone', 'required');
        $this->postJson($this->url('sessions'), ['name' => 'سارة', 'elapsed_ms' => 4000])
            ->assertStatus(422)->assertJsonValidationErrors('phone');

        $this->assertSame(0, WebChatVisitor::count());
    }

    public function test_an_optional_phone_may_be_left_empty_but_not_wrong(): void
    {
        $this->setSettings(['prechat' => ['phone' => 'optional']]);
        $base = ['name' => 'سارة', 'elapsed_ms' => 4000];
        $phoneOf = fn (TestResponse $response) => WebChatVisitor::where('uuid', $response->json('visitor.id'))->value('phone');

        $this->getJson($this->url('config'))->assertOk()->assertJsonPath('widget.prechat.phone', 'optional');

        // رقمٌ كُتب خطأً يُردّ ليُصحَّح، لا يُسقط بصمت.
        $this->postJson($this->url('sessions'), ['phone' => '12'] + $base)->assertStatus(422)->assertJsonValidationErrors('phone');
        $this->assertSame(0, WebChatVisitor::count());

        $this->assertNull($phoneOf($this->postJson($this->url('sessions'), $base)->assertCreated()));
        $this->assertNull($phoneOf($this->postJson($this->url('sessions'), ['phone' => '   '] + $base)->assertCreated()));
        $this->assertSame('+966501234567', $phoneOf($this->postJson($this->url('sessions'), ['phone' => '0501234567'] + $base)->assertCreated()));
    }

    /** المخفيّ لا يُحفظ ولا يُتحقَّق منه — ولو أرسله widget قديم بقي في متصفّح الزائر. */
    public function test_a_hidden_phone_is_ignored_even_if_an_old_widget_sends_one(): void
    {
        $this->setSettings(['prechat' => ['phone' => 'off']]);

        $this->getJson($this->url('config'))->assertOk()->assertJsonPath('widget.prechat.phone', 'off');

        [, $visitor] = $this->openSession();
        [, $wrong] = $this->openSession(['phone' => '12']);

        $this->assertNull($visitor->phone);
        $this->assertNull($wrong->phone);
    }

    /** ما يصل webhook التاجر هو raw كما هو: بلا رقمٍ لا مفتاح phone أصلاً. */
    public function test_without_a_phone_the_merchant_webhook_gets_no_phone_key(): void
    {
        Queue::fake();
        $this->setSettings(['prechat' => ['phone' => 'off']]);
        [$token] = $this->openSession();

        $this->send($token, ['text' => 'مرحبا'])->assertStatus(202);

        Queue::assertPushed(ProcessWebChatInboundJob::class, function (ProcessWebChatInboundJob $job) {
            $payload = (fn () => $this->payload)->call($job);

            return $payload['sender_phone'] === null
                && $payload['raw']['visitor']['name'] === 'سارة'
                && !array_key_exists('phone', $payload['raw']['visitor']);
        });
    }

    /** الإرسال للزائر بمعرّفه لا برقمه: زائرٌ بلا رقم يصله ردّ الموظّف في الـ widget. */
    public function test_a_visitor_without_a_phone_is_answered_inside_the_widget(): void
    {
        $this->setSettings(['prechat' => ['phone' => 'optional']]);
        $start = $this->postJson($this->url('sessions'), ['name' => 'سارة', 'elapsed_ms' => 4000])->assertCreated();
        $token = $start->json('token');
        $visitor = WebChatVisitor::where('uuid', $start->json('visitor.id'))->first();

        $this->send($token, ['text' => 'هل المنتج متوفّر؟'])->assertStatus(202);
        $contact = Contact::find($visitor->refresh()->contact_id);

        $this->assertSame($visitor->uuid, $contact->channel_identifier);
        $this->assertNull($contact->phone);
        $this->assertNull($contact->formatted_phone);
        $this->assertTrue(ChatTicket::where('contact_id', $contact->id)->exists());

        Http::fake();
        $this->actAsOwner();

        $result = (new ChatService($this->organization->id))->sendMessage(
            Request::create('/chats', 'POST', ['uuid' => $contact->uuid, 'message' => 'نعم، متوفّر'])
        );

        $this->assertTrue($result->success);
        Http::assertNothingSent();

        $last = collect($this->getJson($this->url('messages'), ['X-Webchat-Token' => $token])->assertOk()->json('messages'))->last();
        $this->assertSame('out', $last['direction']);
        $this->assertSame('نعم، متوفّر', $last['text']);
    }

    public function test_no_new_sessions_once_the_subscription_expired(): void
    {
        Subscription::where('organization_id', $this->organization->id)->update(['valid_until' => now()->subDay()]);
        Cache::flush();

        $this->postJson($this->url('sessions'), ['name' => 'سارة', 'phone' => '+966501234567', 'elapsed_ms' => 4000])
            ->assertForbidden()->assertJsonPath('error', 'unavailable');
    }

    public function test_sessions_are_rate_limited_per_ip(): void
    {
        RateLimiter::clear('wcs:127.0.0.1');

        for ($i = 0; $i < 5; $i++) {
            $this->openSession();
        }

        $this->postJson($this->url('sessions'), ['name' => 'سارة', 'phone' => '+966501234567', 'elapsed_ms' => 4000])
            ->assertStatus(429);
    }

    // ───────────────────── الرسائل ─────────────────────

    public function test_a_message_needs_the_visitor_token(): void
    {
        $this->send('', ['text' => 'مرحبا'])->assertUnauthorized();
        $this->send('wrong-token', ['text' => 'مرحبا'])->assertUnauthorized();
    }

    public function test_a_message_is_queued_on_the_high_queue(): void
    {
        Queue::fake();
        [$token] = $this->openSession();

        $this->send($token, ['text' => 'مرحبا'])->assertStatus(202)->assertJsonPath('status', 'pending');

        Queue::assertPushedOn('high', ProcessWebChatInboundJob::class);
    }

    public function test_a_message_lands_in_the_inbox_like_a_whatsapp_one(): void
    {
        $whatsapp = Contact::create([
            'organization_id' => $this->organization->id,
            'phone' => '+966501234567',
            'first_name' => 'سارة',
            'created_by' => 0,
        ]);
        [$token, $visitor] = $this->openSession();
        $clientId = (string) Str::uuid();

        $this->send($token, ['client_message_id' => $clientId, 'text' => "مرحبا\x07 أريد طلب"])->assertStatus(202);

        $visitor->refresh();
        $contact = Contact::find($visitor->contact_id);
        $chat = Chat::where('contact_id', $contact->id)->first();

        // جهة منفصلة عن جهة واتساب لنفس الرقم، برقم عرضٍ لا هويّة.
        $this->assertNotSame($whatsapp->id, $contact->id);
        $this->assertSame(Channel::WEBCHAT, $contact->channel);
        $this->assertSame($visitor->uuid, $contact->channel_identifier);
        $this->assertNull($contact->phone);
        $this->assertNotEmpty($contact->formatted_phone);

        // نفس شكل صفّ واتساب: الفقاعة والإشعار والردّ الآلي تقرؤه كما هو.
        $this->assertSame('inbound', $chat->type);
        $this->assertSame(Channel::WEBCHAT, $chat->channel);
        $this->assertSame(['type' => 'text', 'text' => ['body' => 'مرحبا أريد طلب']], json_decode($chat->metadata, true));
        $this->assertStringStartsWith('wc:', $chat->wam_id);

        // التذكرة والإسناد والردّ الآلي — كما في واتساب.
        $this->assertTrue(ChatTicket::where('contact_id', $contact->id)->exists());
        Bus::assertDispatched(ProcessAutoReplyJob::class);
        Event::assertDispatched(NewChatEvent::class);
    }

    public function test_resending_the_same_client_message_id_records_one_message(): void
    {
        [$token, $visitor] = $this->openSession();
        $clientId = (string) Str::uuid();

        $this->send($token, ['client_message_id' => $clientId, 'text' => 'مرحبا'])->assertStatus(202);
        $this->send($token, ['client_message_id' => $clientId, 'text' => 'مرحبا'])->assertStatus(202);

        $this->assertSame(1, Chat::where('contact_id', $visitor->fresh()->contact_id)->count());
    }

    /**
     * فشلٌ بعد حفظ الرسالة يُعيد المهمّة، والمُعادة تجد الرسالة مكرَّرة.
     * يجب أن يُربط الزائر رغم ذلك، وإلّا لم يرَ محادثته ولا الردّ عليها.
     */
    public function test_a_retry_of_an_already_saved_message_still_links_the_visitor(): void
    {
        [$token, $visitor] = $this->openSession();
        $clientId = (string) Str::uuid();
        $this->send($token, ['client_message_id' => $clientId, 'text' => 'مرحبا'])->assertStatus(202);
        $contactId = $visitor->refresh()->contact_id;

        // كأنّ المحاولة الأولى سقطت قبل الربط.
        $visitor->forceFill(['contact_id' => null])->save();

        $this->send($token, ['client_message_id' => $clientId, 'text' => 'مرحبا'])->assertStatus(202);

        $this->assertSame((int) $contactId, (int) $visitor->refresh()->contact_id);
        $this->assertSame(1, Chat::where('contact_id', $contactId)->count());
    }

    public function test_an_empty_message_after_cleaning_is_refused(): void
    {
        [$token] = $this->openSession();

        $this->send($token, ['text' => "  \x01\x02  "])->assertStatus(422);
        $this->send($token, ['text' => str_repeat('a', 5000)])->assertStatus(422);
    }

    // ───────────────────── السجلّ ─────────────────────

    public function test_history_shows_only_this_visitors_conversation(): void
    {
        [$tokenA, $visitorA] = $this->openSession();
        [$tokenB] = $this->openSession(['name' => 'خالد']);

        $this->send($tokenA, ['text' => 'رسالة سارة']);
        $this->send($tokenB, ['text' => 'رسالة خالد']);

        $contactA = $visitorA->fresh()->contact_id;
        Chat::create([
            'organization_id' => $this->organization->id,
            'contact_id' => $contactA,
            'type' => 'outbound',
            'user_id' => User::first()->id,
            'metadata' => json_encode(['type' => 'text', 'text' => ['body' => 'أهلاً سارة']]),
            'status' => 'sent',
            'channel' => Channel::WEBCHAT,
        ]);
        Chat::create([
            'organization_id' => $this->organization->id,
            'contact_id' => $contactA,
            'type' => 'outbound',
            'metadata' => json_encode(['type' => 'text', 'text' => ['body' => 'محذوفة']]),
            'status' => 'sent',
            'channel' => Channel::WEBCHAT,
            'deleted_at' => now(),
        ]);

        $messages = $this->getJson($this->url('messages'), ['X-Webchat-Token' => $tokenA])->assertOk()->json('messages');

        $this->assertSame(['رسالة سارة', 'أهلاً سارة'], array_column($messages, 'text'));
        $this->assertSame(['in', 'out'], array_column($messages, 'direction'));
        $this->assertSame('agent', $messages[1]['sender']['kind']);
        $this->assertNotNull($messages[0]['client_id']);
        $this->assertArrayNotHasKey('wam_id', $messages[0]);
        $this->assertArrayNotHasKey('metadata', $messages[0]);

        $after = $this->getJson($this->url('messages?after=' . $messages[0]['seq']), ['X-Webchat-Token' => $tokenA])->json('messages');
        $this->assertSame(['أهلاً سارة'], array_column($after, 'text'));
    }

    public function test_unread_count_and_read_marks(): void
    {
        [$token, $visitor] = $this->openSession();
        $this->send($token, ['text' => 'مرحبا']);
        $contactId = $visitor->fresh()->contact_id;

        $reply = Chat::create([
            'organization_id' => $this->organization->id,
            'contact_id' => $contactId,
            'type' => 'outbound',
            'user_id' => 0,
            'metadata' => json_encode(['type' => 'text', 'text' => ['body' => 'أهلاً']]),
            'status' => 'sent',
            'channel' => Channel::WEBCHAT,
        ]);

        $this->getJson($this->url('session'), ['X-Webchat-Token' => $token])
            ->assertJsonPath('unread_count', 1)
            ->assertJsonPath('last_message.text', 'أهلاً')
            ->assertJsonPath('last_message.sender.kind', 'bot');

        $this->postJson($this->url('read'), ['seq' => $reply->id + 1000], ['X-Webchat-Token' => $token])
            ->assertOk()->assertJsonPath('unread_count', 0);

        $this->assertSame($reply->id, (int) $visitor->fresh()->last_read_chat_id, 'العلامة لا تتجاوز آخر رسالة.');
        $this->assertSame('read', $reply->fresh()->status);
    }

    // ───────────────────── الأزرار والقوائم ─────────────────────

    public function test_a_button_reply_uses_the_stored_title_not_the_browsers(): void
    {
        [$token, $visitor] = $this->openSession();
        $this->send($token, ['text' => 'ابدأ']);
        $contactId = $visitor->fresh()->contact_id;

        $offer = Chat::create([
            'organization_id' => $this->organization->id,
            'contact_id' => $contactId,
            'type' => 'outbound',
            'user_id' => 0,
            'metadata' => json_encode([
                'type' => 'text',
                'text' => ['body' => 'كيف نساعدك؟'],
                'interactive_options' => [
                    'kind' => 'buttons',
                    'buttons' => [['id' => 'btn-1', 'title' => 'المبيعات'], ['id' => 'btn-2', 'title' => 'الدعم']],
                ],
            ]),
            'status' => 'sent',
            'channel' => Channel::WEBCHAT,
        ]);

        $this->send($token, ['type' => 'interactive', 'reply' => ['message_id' => $offer->uuid, 'option_id' => 'btn-2'], 'text' => 'مزوّر'])
            ->assertStatus(202);

        $reply = Chat::where('contact_id', $contactId)->where('type', 'inbound')->orderByDesc('id')->first();
        $this->assertSame(
            ['type' => 'interactive', 'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => 'btn-2', 'title' => 'الدعم']]],
            json_decode($reply->metadata, true)
        );

        // الخيار يظهر في السجلّ أزراراً يضغطها الزائر.
        $history = $this->getJson($this->url('messages'), ['X-Webchat-Token' => $token])->json('messages');
        $offered = collect($history)->firstWhere('id', $offer->uuid);
        $this->assertSame('buttons', $offered['interactive']['kind']);
        $this->assertSame('الدعم', collect($history)->last()['text']);
    }

    public function test_an_option_that_was_never_offered_to_this_visitor_is_refused(): void
    {
        [$tokenA, $visitorA] = $this->openSession();
        [$tokenB] = $this->openSession(['name' => 'خالد']);
        $this->send($tokenA, ['text' => 'ابدأ']);
        $this->send($tokenB, ['text' => 'ابدأ']);

        $offerToA = Chat::create([
            'organization_id' => $this->organization->id,
            'contact_id' => $visitorA->fresh()->contact_id,
            'type' => 'outbound',
            'metadata' => json_encode(['type' => 'text', 'text' => ['body' => '؟'], 'interactive_options' => ['kind' => 'buttons', 'buttons' => [['id' => 'btn-1', 'title' => 'نعم']]]]),
            'status' => 'sent',
            'channel' => Channel::WEBCHAT,
        ]);

        $this->send($tokenA, ['type' => 'interactive', 'reply' => ['message_id' => $offerToA->uuid, 'option_id' => 'btn-9']])->assertStatus(422);
        $this->send($tokenB, ['type' => 'interactive', 'reply' => ['message_id' => $offerToA->uuid, 'option_id' => 'btn-1']])->assertStatus(422);
    }

    // ───────────────────── الملفات ─────────────────────

    public function test_an_image_upload_becomes_an_image_message_with_stored_media(): void
    {
        Storage::fake('local');
        Setting::updateOrCreate(['key' => 'storage_system'], ['value' => 'local']);
        [$token, $visitor] = $this->openSession();
        $this->send($token, ['text' => 'مرحبا']);

        $file = UploadedFile::fake()->image('صورة.png', 20, 20);

        $this->post($this->url('uploads'), [
            'client_message_id' => (string) Str::uuid(),
            'file' => $file,
            'caption' => 'المنتج المطلوب',
        ], ['X-Webchat-Token' => $token, 'Accept' => 'application/json'])->assertStatus(202);

        $chat = Chat::where('contact_id', $visitor->fresh()->contact_id)->orderByDesc('id')->first();
        $metadata = json_decode($chat->metadata, true);

        $this->assertSame('image', $metadata['type']);
        $this->assertSame('المنتج المطلوب', $metadata['image']['caption']);
        $this->assertNotNull($chat->fresh()->media_id, 'مهمّة الوسائط نقلت الملف إلى التخزين الدائم.');

        $history = $this->getJson($this->url('messages'), ['X-Webchat-Token' => $token])->json('messages');
        $this->assertStringContainsString('/media/public/', (string) collect($history)->last()['media']['url']);
    }

    public function test_dangerous_files_are_refused_whatever_their_name(): void
    {
        Storage::fake('local');
        [$token] = $this->openSession();
        $headers = ['X-Webchat-Token' => $token, 'Accept' => 'application/json'];

        $php = UploadedFile::fake()->createWithContent('photo.png', "<?php echo 'x'; ?>");
        $svg = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"></svg>');
        $html = UploadedFile::fake()->createWithContent('page.pdf', '<html><script>alert(1)</script></html>');

        foreach ([$php, $svg, $html] as $file) {
            $this->post($this->url('uploads'), ['client_message_id' => (string) Str::uuid(), 'file' => $file], $headers)
                ->assertStatus(422)
                ->assertJsonValidationErrors('file');
        }
    }

    public function test_uploads_can_be_turned_off(): void
    {
        $this->setSettings(['behavior' => ['uploads' => false]]);
        [$token] = $this->openSession();

        $this->post($this->url('uploads'), [
            'client_message_id' => (string) Str::uuid(),
            'file' => UploadedFile::fake()->image('a.png'),
        ], ['X-Webchat-Token' => $token, 'Accept' => 'application/json'])->assertForbidden();
    }

    // ───────────────────── ردّ الموظّف ─────────────────────

    /**
     * الموظّف يردّ من صندوق المحادثات بعد أيام: لا نافذة ٢٤ ساعة ولا نداء
     * شبكة، ويجد الزائر الردّ باسم الموظّف حين يعود.
     */
    public function test_an_agents_reply_reaches_the_visitor_days_later(): void
    {
        [$token, $visitor] = $this->openSession();
        $this->send($token, ['text' => 'هل المنتج متوفّر؟'])->assertStatus(202);
        $contact = Contact::find($visitor->refresh()->contact_id);

        $this->travel(3)->days();
        Http::fake();
        $agent = $this->actAsOwner();

        $result = (new ChatService($this->organization->id))->sendMessage(
            Request::create('/chats', 'POST', ['uuid' => $contact->uuid, 'message' => 'نعم، متوفّر 👍'])
        );

        $this->assertTrue($result->success);
        Http::assertNothingSent();

        $last = collect($this->getJson($this->url('messages'), ['X-Webchat-Token' => $token])->assertOk()->json('messages'))->last();
        $this->assertSame('out', $last['direction']);
        $this->assertSame('نعم، متوفّر 👍', $last['text']);
        $this->assertSame(['kind' => 'agent', 'name' => $agent->first_name], $last['sender']);
    }

    /** وما لا مقابل له في المتصفّح يُرفض قبل أن يُسجَّل: القوالب وطلب الموقع. */
    public function test_templates_and_location_requests_are_refused_for_a_visitor(): void
    {
        [$token, $visitor] = $this->openSession();
        $this->send($token, ['text' => 'مرحبا'])->assertStatus(202);
        $contact = Contact::find($visitor->refresh()->contact_id);
        Http::fake();
        $this->actAsOwner();
        $service = new ChatService($this->organization->id);

        $template = $service->sendTemplateMessage(
            (object) ['template' => 'no-such-template', 'header' => ['format' => 'TEXT', 'parameters' => []]],
            $contact->uuid
        );

        $this->assertFalse($template->success);
        $this->assertSame(422, $service->requestLocation($contact->uuid, 'أرسل موقعك')->getStatusCode());
        Http::assertNothingSent();
        $this->assertSame(0, Chat::where('contact_id', $contact->id)->where('type', 'outbound')->count());
    }
}
