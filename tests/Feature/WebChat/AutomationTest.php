<?php

namespace Tests\Feature\WebChat;

use App\Events\NewChatEvent;
use App\Events\WebChatVisitorEvent;
use App\Jobs\SendTextMessageJob;
use App\Models\AutoReply;
use App\Models\ChannelAccount;
use App\Models\Chat;
use App\Models\Contact;
use App\Models\WebChatVisitor;
use App\Services\AutoReplyService;
use App\Services\ConversationRatingService;
use App\Services\Messaging\Channel;
use App\Services\Messaging\ChannelAutomationTransport;
use App\Services\Messaging\Drivers\WebChatDriver;
use App\Services\Messaging\OutboundMessageRecorder;
use App\Services\WebChat\WebChatMessagePresenter;
use App\Services\WebChat\WebChatSettings;
use App\Services\WebChat\WebChatWidgetService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\FlowBuilder\Models\FlowLog;
use Modules\FlowBuilder\Services\ActionExecutionService;
use Modules\IntelliReply\Services\AIResponseService;
use Tests\Feature\FlowBuilder\FlowBuilderTestCase;

/**
 * البوت على المحادثة المباشرة: نفس الردود الآلية والـ flows والمساعد الذكي
 * ورسالة ساعات العمل والتقييم — تصل الزائر بلا نداء شبكة، وبأزرار يضغطها.
 */
class AutomationTest extends FlowBuilderTestCase
{
    private ChannelAccount $widget;
    private WebChatVisitor $visitor;
    private Contact $web;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([NewChatEvent::class, WebChatVisitorEvent::class]);
        Http::fake();

        $this->widget = app(WebChatWidgetService::class)->ensureForOrganization($this->organization->id, 'متجر الاختبار');
        $this->visitor = WebChatVisitor::create([
            'organization_id' => $this->organization->id,
            'channel_account_id' => $this->widget->id,
            'token_hash' => WebChatVisitor::hashToken(WebChatVisitor::newToken()),
            'name' => 'سارة',
            'phone' => '+966501234567',
        ]);
        $this->web = Contact::create([
            'organization_id' => $this->organization->id,
            'channel' => Channel::WEBCHAT,
            'channel_identifier' => $this->visitor->uuid,
            'channel_account_id' => $this->widget->id,
            'first_name' => 'سارة',
            'formatted_phone' => '+966 50 123 4567',
            'created_by' => 0,
        ]);
    }

    // ───────────────────── الردود الآلية ─────────────────────

    public function test_a_basic_reply_reaches_the_visitor_without_any_network_call(): void
    {
        $this->basicReply('مرحبا', 'أهلاً {first_name}، رقمك {phone}');

        $this->visitorSays('مرحبا');

        $this->assertSame(['أهلاً سارة، رقمك +966 50 123 4567'], $this->outboundBodies());
        $this->assertSame([], $this->graphRequests, 'لا شيء يذهب إلى واتساب.');
        Http::assertNothingSent();
    }

    /** ردّ الترحيب يصل قناة الزائر لحظياً — وإن سُجّل قبل ربط الزائر بجهته. */
    public function test_the_automatic_reply_is_pushed_to_the_visitors_channel(): void
    {
        $this->visitor->forceFill(['contact_id' => null])->save();
        $this->basicReply('مرحبا', 'أهلاً بك');

        $this->visitorSays('مرحبا');

        Event::assertDispatched(WebChatVisitorEvent::class, fn (WebChatVisitorEvent $e) => $e->visitorUuid === $this->visitor->uuid
            && $e->payload['message']['text'] === 'أهلاً بك'
            && $e->payload['message']['sender'] === ['kind' => 'bot']);
    }

    public function test_a_location_request_reply_arrives_as_plain_text(): void
    {
        $this->autoReply('موقع', ['type' => 'location_request', 'data' => ['text' => 'أرسل عنوانك']]);

        $this->visitorSays('موقع');

        $chat = $this->lastOutbound();
        $this->assertSame(['type' => 'text', 'text' => ['body' => 'أرسل عنوانك']], json_decode($chat->metadata, true));
    }

    public function test_an_image_reply_reaches_the_visitor_with_its_link(): void
    {
        $this->autoReply('صورة', ['type' => 'image', 'data' => ['file' => [
            'name' => 'menu.png',
            'location' => 'uploads/media/menu.png',
            'url' => 'https://cdn.example.com/uploads/media/menu.png',
        ]]]);

        $this->visitorSays('صورة');

        $chat = $this->lastOutbound()->load('media');
        $this->assertSame('image', json_decode($chat->metadata, true)['type']);
        $this->assertSame('https://cdn.example.com/uploads/media/menu.png', $chat->media->path);
        $this->assertSame('image/png', $chat->media->type);
    }

    // ───────────────────── الـ flows ─────────────────────

    public function test_a_flow_sends_buttons_the_visitor_can_press(): void
    {
        $this->makeFlow([
            $this->startNode('keywords', 'start'),
            $this->textNode('2', 'مرحباً {first_name}'),
            $this->buttonsNode('3', 'هل تريد المتابعة؟', ['نعم', 'لا'], 'اختر واحداً'),
            $this->textNode('4', 'تمّ، شكراً'),
            $this->textNode('5', 'حسناً'),
        ], [['1', '2'], ['2', '3'], ['3', '4', 'a'], ['3', '5', 'b']]);

        $this->visitorSays('start');
        $this->visitorSays('التالي');

        $buttons = json_decode($this->lastOutbound()->metadata, true);
        $this->assertSame('هل تريد المتابعة؟', $buttons['text']['body']);
        $this->assertSame([
            'kind' => 'buttons',
            'footer' => 'اختر واحداً',
            'buttons' => [['id' => 'button1', 'title' => 'نعم'], ['id' => 'button2', 'title' => 'لا']],
        ], $buttons['interactive_options']);

        // الضغطة كما يرسلها الـ widget: العنوان من النسخة المحفوظة.
        $this->visitorPresses('button1', 'نعم');

        $this->assertSame(['مرحباً سارة', 'هل تريد المتابعة؟', 'تمّ، شكراً'], $this->outboundBodies());
        $this->assertSame(3, FlowLog::count());
        $this->assertSame([], $this->graphRequests);
    }

    public function test_a_flow_session_stays_open_while_waiting_for_the_choice(): void
    {
        $this->makeFlow([
            $this->startNode('keywords', 'start'),
            $this->buttonsNode('2', 'تأكيد؟', ['تأكيد', 'إلغاء']),
            $this->textNode('3', 'أُكِّد'),
        ], [['1', '2'], ['2', '3', 'a']]);

        $this->visitorSays('start');

        $this->assertNotNull(\Modules\FlowBuilder\Models\FlowUserData::where('contact_id', $this->web->id)->first());
    }

    public function test_a_flow_sends_media_a_link_button_and_a_list(): void
    {
        $this->makeFlow([
            $this->startNode('keywords', 'start'),
            $this->rawNode('2', 'media', [
                'type' => 'media', 'mediaType' => 'image', 'caption' => 'كتالوج {first_name}',
                'media' => ['path' => 'https://cdn.example.com/catalog.jpg', 'location' => 'aws', 'metadata' => json_encode(['name' => 'catalog.jpg'])],
            ]),
            $this->rawNode('3', 'buttons', [
                'type' => 'interactive buttons', 'buttonType' => 'cta', 'body' => 'تصفّح المتجر', 'footer' => 'متجرنا',
                'headerType' => 'text', 'headerText' => 'عرض اليوم',
                'ctaUrlButton' => ['displayText' => 'افتح المتجر', 'url' => 'https://shop.example.com/'],
            ]),
            $this->rawNode('4', 'list', [
                'type' => 'interactive list', 'body' => 'اختر القسم', 'footer' => 'نردّ خلال دقائق',
                'headerType' => 'image', 'headerMedia' => ['path' => 'https://cdn.example.com/header.jpg'],
                'buttonLabel' => 'الأقسام',
                'sections' => [['title' => 'المبيعات', 'rows' => [['title' => 'طلب جديد', 'description' => 'ابدأ طلباً']]]],
            ]),
        ], [['1', '2'], ['2', '3'], ['3', '4']]);

        $this->visitorSays('start');
        $this->visitorSays('التالي');
        $this->visitorSays('التالي');

        [$media, $cta, $list] = Chat::with('media')->where('contact_id', $this->web->id)->where('type', 'outbound')->orderBy('id')->get()->all();

        $this->assertSame('كتالوج سارة', json_decode($media->metadata, true)['image']['caption']);
        $this->assertSame('https://cdn.example.com/catalog.jpg', $media->media->path);

        // الموظّف يقرأ الرابط في النصّ كما في واتساب؛ الزائر يرى الزرّ وحده.
        $ctaMeta = json_decode($cta->metadata, true);
        $this->assertSame("تصفّح المتجر\n\nافتح المتجر: https://shop.example.com/", $ctaMeta['text']['body']);
        $ctaView = $this->presenter()->present($cta);
        $this->assertSame('تصفّح المتجر', $ctaView['text']);
        $this->assertSame([
            'kind' => 'cta_url', 'display_text' => 'افتح المتجر', 'url' => 'https://shop.example.com/',
            'header' => ['type' => 'text', 'text' => 'عرض اليوم'], 'footer' => 'متجرنا',
        ], $ctaView['interactive']);

        $listView = $this->presenter()->present($list);
        $this->assertSame(['type' => 'image', 'url' => 'https://cdn.example.com/header.jpg'], $listView['interactive']['header']);
        $this->assertSame('الأقسام', $listView['interactive']['button']);
        $this->assertSame('طلب جديد', $listView['interactive']['sections'][0]['rows'][0]['title']);

        $this->assertSame(3, FlowLog::count());
    }

    public function test_update_contact_keeps_the_phone_out_of_the_whatsapp_identity(): void
    {
        $whatsappTwin = Contact::create([
            'organization_id' => $this->organization->id,
            'phone' => '+966551112222',
            'first_name' => 'نفس الرقم',
            'created_by' => 0,
        ]);

        $updated = (new ActionExecutionService($this->organization->id))
            ->executeAction('update_contact', ['target_field' => 'phone'], $this->web, '0551112222');

        $this->assertTrue($updated);
        $this->web->refresh();
        $this->assertNull($this->web->phone, 'رقم الهويّة لواتساب وحده.');
        $this->assertNotSame($whatsappTwin->id, $this->web->id);
        $this->assertStringContainsString('55', (string) $this->web->formatted_phone);
        $this->assertSame('+966551112222', $this->visitor->fresh()->phone);
    }

    // ───────────────────── المساعد الذكي وساعات العمل والتقييم ─────────────────────

    public function test_an_ai_text_reply_reaches_the_visitor(): void
    {
        $service = app(AIResponseService::class);
        $send = new \ReflectionMethod($service, 'sendResponse');
        $send->setAccessible(true);

        $this->assertTrue($send->invoke($service, $this->web, 'text', ['text' => 'المنتج متوفّر.'], ['allow_audio_response' => false]));
        $this->assertSame(['المنتج متوفّر.'], $this->outboundBodies());
        $this->assertSame([], $this->graphRequests);
    }

    public function test_an_ai_voice_reply_keeps_its_transcript(): void
    {
        $response = app(ChannelAutomationTransport::class)->sendMedia(
            $this->web->uuid, 'audio', 'speech.mp3', 'public/audios/speech.mp3',
            'https://app.example.com/media/public/audios/speech.mp3', 'local', null, 'هذا نصّ الردّ الصوتي'
        );

        $this->assertTrue($response->success);
        $metadata = json_decode($response->data->chat->metadata, true);
        $this->assertSame('audio', $metadata['type']);
        $this->assertSame('هذا نصّ الردّ الصوتي', $metadata['transcript']);
    }

    public function test_the_working_hours_notice_reaches_the_visitor(): void
    {
        (new SendTextMessageJob($this->organization->id, $this->web->uuid, 'نحن خارج أوقات العمل'))->handle();

        $this->assertSame(['نحن خارج أوقات العمل'], $this->outboundBodies());
        $this->assertSame([], $this->graphRequests);
    }

    public function test_the_rating_survey_reaches_a_visitor_of_an_organization_without_whatsapp(): void
    {
        $this->organization->update(['metadata' => json_encode(['ratings' => ['active' => true, 'cooldown_days' => 0]])]);
        $this->visitorSays('شكراً');

        $rating = ConversationRatingService::onConversationClosed($this->web->fresh());

        $this->assertNotNull($rating);
        $this->assertSame('+966 50 123 4567', $rating->contact_phone);
        $options = json_decode($this->lastOutbound()->metadata, true)['interactive_options'];
        $this->assertSame('cta_url', $options['kind']);
        $this->assertStringContainsString('/rate/' . $rating->token, $options['url']);
    }

    // ───────────────────── الفشل ─────────────────────

    public function test_a_closed_window_fails_without_a_network_call(): void
    {
        config(['messaging.automation_channels' => [Channel::WEBCHAT, Channel::MESSENGER]]);
        $messenger = $this->messengerContact();
        Chat::create([
            'organization_id' => $this->organization->id,
            'contact_id' => $messenger->id,
            'type' => 'inbound',
            'metadata' => json_encode(['type' => 'text', 'text' => ['body' => 'قديم']]),
            'status' => 'delivered',
            'channel' => Channel::MESSENGER,
            'created_at' => now()->subDays(3),
        ]);

        $response = app(ChannelAutomationTransport::class)->sendMessage($messenger->uuid, 'متأخّر');

        $this->assertFalse($response->success);
        $this->assertSame('window_closed', $response->data->error->code);
        Http::assertNothingSent();
    }

    public function test_a_failure_inside_the_send_never_escapes(): void
    {
        $this->partialMock(OutboundMessageRecorder::class)
            ->shouldReceive('recordText')
            ->andThrow(new \RuntimeException('database is gone'));

        $response = app(ChannelAutomationTransport::class)->sendMessage($this->web->uuid, 'مرحبا');

        $this->assertFalse($response->success);
        $this->assertSame('exception', $response->data->error->code);
    }

    public function test_a_disabled_widget_fails_cleanly(): void
    {
        $this->widget->update(['status' => ChannelAccount::STATUS_DISABLED]);

        $response = app(ChannelAutomationTransport::class)->sendMessage($this->web->uuid, 'مرحبا');

        $this->assertFalse($response->success);
        $this->assertSame('not_connected', $response->data->error->code);
    }

    public function test_other_channels_get_no_automation_unless_allowed(): void
    {
        $messenger = $this->messengerContact();
        $this->basicReply('مرحبا', 'أهلاً');

        $chat = $this->inboundFrom($messenger, 'مرحبا');
        app(AutoReplyService::class)->checkAutoReply($chat, false);

        $this->assertSame(0, Chat::where('contact_id', $messenger->id)->where('type', 'outbound')->count());
        $this->assertFalse(app(AIResponseService::class)->handleAIResponse($chat, 'مرحبا'));
        Http::assertNothingSent();
    }

    // ───────────────────── أدوات ─────────────────────

    private function visitorSays(string $text): Chat
    {
        $chat = $this->inboundFrom($this->web, $text);
        app(AutoReplyService::class)->checkAutoReply($chat, false);

        return $chat;
    }

    /** ضغطة الزائر كما يسجّلها WebChatInboundService. */
    private function visitorPresses(string $id, string $title): void
    {
        $chat = Chat::create([
            'organization_id' => $this->organization->id,
            'contact_id' => $this->web->id,
            'type' => 'inbound',
            'metadata' => json_encode(['type' => 'interactive', 'interactive' => [
                'type' => 'button_reply',
                'button_reply' => ['id' => $id, 'title' => $title],
            ]]),
            'status' => 'delivered',
            'channel' => Channel::WEBCHAT,
        ]);

        app(AutoReplyService::class)->checkAutoReply($chat, false);
    }

    private function inboundFrom(Contact $contact, string $text): Chat
    {
        return Chat::create([
            'organization_id' => $contact->organization_id,
            'contact_id' => $contact->id,
            'type' => 'inbound',
            'metadata' => json_encode(['type' => 'text', 'text' => ['body' => $text]]),
            'status' => 'delivered',
            'channel' => $contact->channel,
        ]);
    }

    private function messengerContact(): Contact
    {
        $account = ChannelAccount::create([
            'organization_id' => $this->organization->id,
            'channel' => Channel::MESSENGER,
            'external_id' => 'page-1',
            'credentials' => ['page_access_token' => 't'],
            'status' => ChannelAccount::STATUS_ACTIVE,
            'created_by' => 0,
        ]);

        return Contact::create([
            'organization_id' => $this->organization->id,
            'channel' => Channel::MESSENGER,
            'channel_identifier' => 'psid-1',
            'channel_account_id' => $account->id,
            'first_name' => 'منى',
            'created_by' => 0,
        ]);
    }

    private function autoReply(string $trigger, array $metadata): void
    {
        AutoReply::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'name' => $trigger,
            'trigger' => $trigger,
            'match_criteria' => 'contains',
            'metadata' => json_encode($metadata),
            'created_by' => $this->owner->id,
            'created_at' => now(),
        ]);
    }

    private function rawNode(string $id, string $type, array $fields): array
    {
        return [
            'id' => $id,
            'type' => $type,
            'label' => "{$type} node",
            'position' => ['x' => 0, 'y' => 0],
            'data' => ['title' => $type, 'metadata' => ['fields' => $fields]],
        ];
    }

    private function lastOutbound(): Chat
    {
        $chat = Chat::where('contact_id', $this->web->id)->where('type', 'outbound')->orderByDesc('id')->first();
        $this->assertNotNull($chat, 'وصل الزائرَ ردّ.');

        return $chat;
    }

    /** @return array<int, ?string> */
    private function outboundBodies(): array
    {
        return Chat::where('contact_id', $this->web->id)->where('type', 'outbound')->orderBy('id')->get()
            ->map(fn (Chat $chat) => json_decode($chat->metadata, true)['text']['body'] ?? null)
            ->all();
    }

    private function presenter(): WebChatMessagePresenter
    {
        return new WebChatMessagePresenter(WebChatSettings::for($this->widget));
    }
}
