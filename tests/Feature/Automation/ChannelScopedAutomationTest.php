<?php

namespace Tests\Feature\Automation;

use App\Events\NewChatEvent;
use App\Events\WebChatVisitorEvent;
use App\Jobs\ProcessAutoReplyJob;
use App\Jobs\ProcessChannelWebhookJob;
use App\Models\AutoReply;
use App\Models\ChannelAccount;
use App\Models\Chat;
use App\Models\Contact;
use App\Models\WebChatVisitor;
use App\Services\AutoReplyService;
use App\Services\Messaging\AutomationChannels;
use App\Services\Messaging\Channel;
use App\Services\WebChat\WebChatWidgetService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\FlowBuilder\Models\Flow;
use Tests\Feature\FlowBuilder\FlowBuilderTestCase;

/**
 * لكل قناة أتمتتها: الردّ الآلي والـ flow يعملان على القنوات التي اختارها
 * التاجر وحدها. ترحيب الموقع غير ترحيب واتساب، وبوتٌ كُتب لواتساب لا يجيب
 * على تيليجرام.
 */
class ChannelScopedAutomationTest extends FlowBuilderTestCase
{
    private Contact $web;
    private Contact $telegram;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([NewChatEvent::class, WebChatVisitorEvent::class]);
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 77]], 200),
        ]);
        $this->fakeGraph();

        $widget = app(WebChatWidgetService::class)->ensureForOrganization($this->organization->id, 'متجر الاختبار');
        $visitor = WebChatVisitor::create([
            'organization_id' => $this->organization->id,
            'channel_account_id' => $widget->id,
            'token_hash' => WebChatVisitor::hashToken(WebChatVisitor::newToken()),
            'name' => 'سارة',
        ]);
        $this->web = Contact::create([
            'organization_id' => $this->organization->id,
            'channel' => Channel::WEBCHAT,
            'channel_identifier' => $visitor->uuid,
            'channel_account_id' => $widget->id,
            'first_name' => 'سارة',
            'created_by' => 0,
        ]);

        $bot = ChannelAccount::create([
            'organization_id' => $this->organization->id,
            'channel' => Channel::TELEGRAM,
            'external_id' => '7654321',
            'name' => 'بوت الدعم',
            // سُجّل بالتحديثات الحالية: لا إعادة تسجيل تزاحم ما نفحصه.
            'credentials' => ['bot_token' => '7654321:AAF-test', 'webhook_secret' => 's', 'webhook_updates' => 'callback_query'],
            'status' => ChannelAccount::STATUS_ACTIVE,
            'created_by' => 0,
        ]);
        $this->telegram = Contact::create([
            'organization_id' => $this->organization->id,
            'channel' => Channel::TELEGRAM,
            'channel_identifier' => '123456789',
            'channel_account_id' => $bot->id,
            'first_name' => 'أحمد',
            'created_by' => 0,
        ]);
    }

    // ───────────────────── القنوات المتاحة ─────────────────────

    /** واتساب والمحادثة المباشرة وتيليجرام، بترتيب الواجهة. */
    public function test_telegram_now_runs_automation_next_to_whatsapp_and_web_chat(): void
    {
        $this->assertSame([Channel::WHATSAPP, Channel::TELEGRAM, Channel::WEBCHAT], AutomationChannels::available());
        $this->assertFalse(AutomationChannels::allowsChannel(Channel::MESSENGER));
    }

    // ───────────────────── الردود الأساسية ─────────────────────

    public function test_a_reply_chosen_for_web_chat_does_not_answer_on_whatsapp(): void
    {
        $this->reply('مرحبا', 'أهلاً بزائر الموقع', [Channel::WEBCHAT]);

        $this->receive('مرحبا');
        $this->says($this->web, 'مرحبا');

        $this->assertSame([], $this->sentBodies(), 'لا شيء يذهب إلى واتساب.');
        $this->assertSame(['أهلاً بزائر الموقع'], $this->outboundBodies($this->web));
    }

    /** الكلمة نفسها بردّين: كلٌّ يجيب على قناته. */
    public function test_the_same_trigger_answers_differently_per_channel(): void
    {
        $this->reply('السعر', 'الأسعار على واتساب', [Channel::WHATSAPP]);
        $this->reply('السعر', 'الأسعار على تيليجرام', [Channel::TELEGRAM]);

        $this->receive('السعر');
        $this->says($this->telegram, 'السعر');

        $this->assertSame(['الأسعار على واتساب'], $this->sentBodies());
        Http::assertSent(fn ($request) => str_contains($request->url(), '/sendMessage')
            && $request['text'] === 'الأسعار على تيليجرام');
        Http::assertSentCount(1);
    }

    /** ردٌّ أُنشئ قبل اختيار القنوات ولم يُعبّأ (null) يعمل في كل مكان كما كان. */
    public function test_a_reply_without_a_channel_choice_answers_everywhere(): void
    {
        $this->basicReply('مرحبا', 'أهلاً');

        $this->receive('مرحبا');
        $this->says($this->web, 'مرحبا');

        $this->assertSame(['أهلاً'], $this->sentBodies());
        $this->assertSame(['أهلاً'], $this->outboundBodies($this->web));
    }

    // ───────────────────── الـ flows ─────────────────────

    public function test_each_channel_gets_its_own_welcome_flow(): void
    {
        $this->makeFlow([$this->startNode('first_message', null), $this->textNode('2', 'ترحيب واتساب')], [['1', '2']], ['channels' => [Channel::WHATSAPP]]);
        $this->makeFlow([$this->startNode('first_message', null), $this->textNode('2', 'ترحيب الموقع')], [['1', '2']], ['channels' => [Channel::WEBCHAT]]);

        $this->receive('السلام عليكم');
        $this->says($this->web, 'السلام عليكم');

        $this->assertSame(['ترحيب واتساب'], $this->sentBodies());
        $this->assertSame(['ترحيب الموقع'], $this->outboundBodies($this->web));
    }

    public function test_a_keyword_flow_starts_only_on_its_channels(): void
    {
        $this->makeFlow([$this->startNode('keywords', 'start'), $this->textNode('2', 'مسار تيليجرام')], [['1', '2']], ['channels' => [Channel::TELEGRAM]]);

        $this->receive('start');
        $this->says($this->web, 'start');
        $this->assertSame([], $this->sentBodies());
        $this->assertSame([], $this->outboundBodies($this->web));

        $this->says($this->telegram, 'start');
        Http::assertSent(fn ($request) => str_contains($request->url(), '/sendMessage') && $request['text'] === 'مسار تيليجرام');
    }

    /** العائد بعد صمت يُرحَّب به بمسار قناته وحدها. */
    public function test_the_return_after_silence_uses_the_channels_flow(): void
    {
        $this->makeFlow([$this->startNode('first_message', null, 30), $this->textNode('2', 'عدت إلينا')], [['1', '2']], [
            'channels' => [Channel::WEBCHAT],
            'trigger_timeout' => 30,
        ]);

        Chat::create([
            'organization_id' => $this->organization->id,
            'contact_id' => $this->contact->id,
            'type' => 'inbound',
            'metadata' => json_encode(['type' => 'text', 'text' => ['body' => 'قديم']]),
            'status' => 'delivered',
            'created_at' => now()->subHours(3),
        ]);

        $this->receive('مرحبا مجدداً');

        $this->assertSame([], $this->sentBodies(), 'مسار المحادثة المباشرة لا يبدأ على واتساب.');
    }

    /** أزرار الـ flow على تيليجرام لوحةٌ inline، والضغطة تكمل المسار. */
    public function test_a_telegram_flow_sends_real_buttons_and_follows_the_press(): void
    {
        $this->makeFlow([
            $this->startNode('keywords', 'start'),
            $this->buttonsNode('2', 'هل تريد المتابعة؟', ['نعم', 'لا']),
            $this->textNode('3', 'تمّ، شكراً'),
            $this->textNode('4', 'حسناً'),
        ], [['1', '2'], ['2', '3', 'a'], ['2', '4', 'b']], ['channels' => [Channel::TELEGRAM]]);

        $this->says($this->telegram, 'start');

        Http::assertSent(fn ($request) => str_contains($request->url(), '/sendMessage')
            && $request['text'] === 'هل تريد المتابعة؟'
            && $request['reply_markup'] === ['inline_keyboard' => [
                [['text' => 'نعم', 'callback_data' => 'b:نعم']],
                [['text' => 'لا', 'callback_data' => 'b:لا']],
            ]]);

        // الضغطة كما يقرؤها TelegramDriver من callback_query.
        $press = Chat::create([
            'organization_id' => $this->organization->id,
            'contact_id' => $this->telegram->id,
            'type' => 'inbound',
            'metadata' => json_encode(['type' => 'interactive', 'interactive' => [
                'type' => 'button_reply',
                'button_reply' => ['id' => 'b:نعم', 'title' => 'نعم'],
            ]]),
            'status' => 'delivered',
            'channel' => Channel::TELEGRAM,
        ]);
        app(AutoReplyService::class)->checkAutoReply($press, false);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/sendMessage') && $request['text'] === 'تمّ، شكراً');
    }

    /** ضغطة زرّ من تيليجرام تمرّ بالـ webhook كاملاً: تُسجَّل ردّاً تفاعلياً ويُشغَّل عليها الردّ الآلي. */
    public function test_a_telegram_button_press_is_recorded_and_runs_the_automation(): void
    {
        Bus::fake([ProcessAutoReplyJob::class]);

        app()->call([new ProcessChannelWebhookJob($this->telegram->channel_account_id, [
            'update_id' => 900003,
            'callback_query' => [
                'id' => '5511',
                'from' => ['id' => 123456789, 'is_bot' => false, 'first_name' => 'أحمد'],
                'message' => [
                    'message_id' => 77,
                    'date' => 1758600000,
                    'chat' => ['id' => 123456789, 'type' => 'private'],
                    'text' => 'هل تريد المتابعة؟',
                    'reply_markup' => ['inline_keyboard' => [[['text' => 'نعم', 'callback_data' => 'b:نعم']]]],
                ],
                'data' => 'b:نعم',
            ],
        ]), 'handle']);

        $chat = Chat::where('contact_id', $this->telegram->id)->where('type', 'inbound')->latest('id')->first();
        $this->assertNotNull($chat, 'سُجّلت الضغطة على جهة تيليجرام نفسها.');
        $this->assertSame(Channel::TELEGRAM, $chat->channel);
        $this->assertSame('نعم', json_decode($chat->metadata, true)['interactive']['button_reply']['title']);

        Bus::assertDispatched(ProcessAutoReplyJob::class);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/answerCallbackQuery'));
    }

    // ───────────────────── الحفظ من الواجهة ─────────────────────

    public function test_saving_a_reply_needs_at_least_one_available_channel(): void
    {
        $reply = $this->reply('مرحبا', 'أهلاً', [Channel::WHATSAPP]);

        $this->asOwner()->put('/automation/basic/' . $reply->uuid, $this->replyForm(['channels' => []]))
            ->assertSessionHasErrors('channels');
        $this->asOwner()->put('/automation/basic/' . $reply->uuid, $this->replyForm(['channels' => [Channel::MESSENGER]]))
            ->assertSessionHasErrors('channels.0');

        $this->assertSame([Channel::WHATSAPP], $reply->fresh()->channels);
    }

    /**
     * صفحةٌ فُتحت قبل التحديث لا ترسل channels. كان الطلب يُرفض بـ 422 لا
     * تعرضه واجهتها القديمة فيبدو الحفظ معطّلاً. الآن: التعديل يُبقي ما اختير،
     * والجديد يأخذ القنوات الافتراضية — ما عبّأ به الترحيل الردود القائمة.
     */
    public function test_a_form_opened_before_the_update_still_saves(): void
    {
        $reply = $this->reply('مرحبا', 'أهلاً', [Channel::TELEGRAM]);
        $old = $this->replyForm(['response' => 'أهلاً وسهلاً']);
        unset($old['channels']);

        $this->asOwner()->put('/automation/basic/' . $reply->uuid, $old)->assertSessionHasNoErrors();
        $this->assertSame([Channel::TELEGRAM], $reply->fresh()->channels, 'the choice is kept');
        $this->assertSame('أهلاً وسهلاً', json_decode($reply->fresh()->metadata, true)['data']['text']);

        $this->asOwner()->post('/automation/basic', array_merge($old, ['trigger' => 'السعر']))->assertSessionHasNoErrors();
        $created = AutoReply::where('organization_id', $this->organization->id)->where('trigger', 'السعر')->sole();
        $this->assertSame(AutomationChannels::LEGACY, $created->channels);
        $this->assertSame(AutomationChannels::LEGACY, AutomationChannels::defaults());
    }

    public function test_the_chosen_channels_are_saved_in_display_order(): void
    {
        $reply = $this->reply('مرحبا', 'أهلاً', [Channel::WHATSAPP]);

        $this->asOwner()->put('/automation/basic/' . $reply->uuid, $this->replyForm([
            'channels' => [Channel::WEBCHAT, Channel::TELEGRAM, Channel::WEBCHAT],
        ]))->assertSessionHasNoErrors();

        $this->assertSame([Channel::TELEGRAM, Channel::WEBCHAT], $reply->fresh()->channels);
    }

    /** الصفحتان تعرضان القنوات المتاحة بأسمائها. */
    public function test_the_reply_pages_offer_the_available_channels(): void
    {
        $reply = $this->reply('مرحبا', 'أهلاً', [Channel::TELEGRAM]);

        foreach (['/automation/basic/create', '/automation/basic/' . $reply->uuid . '/edit'] as $url) {
            $props = $this->inertiaProps($url);
            $this->assertSame(
                [Channel::WHATSAPP, Channel::TELEGRAM, Channel::WEBCHAT],
                array_column($props['automationChannels'], 'value'),
                $url
            );
        }
    }

    public function test_the_flow_editor_saves_the_channels_of_the_start_node(): void
    {
        $flow = $this->makeFlow([$this->startNode('keywords', 'start'), $this->textNode('2', 'أهلاً')], [['1', '2']], [
            'channels' => AutomationChannels::LEGACY,
        ]);
        $graph = json_decode($flow->metadata, true);

        // مسارٌ حُفظ قبل اختيار القنوات: عقدته بلا مفتاح، فيبقى ما في العمود.
        $this->saveFlow($flow, $graph)->assertOk()->assertJsonPath('success', true);
        $this->assertSame(AutomationChannels::LEGACY, $flow->fresh()->channels);

        $graph['nodes'][0]['data']['metadata']['fields']['channels'] = [Channel::TELEGRAM];
        $this->saveFlow($flow, $graph)->assertOk()->assertJsonPath('success', true);
        $this->assertSame([Channel::TELEGRAM], $flow->fresh()->channels);

        // بلا قناة: المسار المنشور لا يُستبدل.
        $graph['nodes'][0]['data']['metadata']['fields']['channels'] = [];
        $this->saveFlow($flow, $graph)->assertOk()
            ->assertJsonPath('success', false)
            ->assertJsonPath('errors.0.message', __('Choose at least one channel.'));
        $this->assertSame([Channel::TELEGRAM], $flow->fresh()->channels);
    }

    public function test_the_flow_editor_offers_the_available_channels(): void
    {
        $flow = $this->makeFlow([$this->startNode('keywords', 'start'), $this->textNode('2', 'أهلاً')], [['1', '2']]);

        $props = $this->inertiaProps('/automation/flows/' . $flow->uuid);

        $this->assertSame(
            [Channel::WHATSAPP, Channel::TELEGRAM, Channel::WEBCHAT],
            array_column($props['automationChannels'], 'value')
        );
    }

    // ───────────────────── مساعدات ─────────────────────

    /** @param array<int, string> $channels */
    private function reply(string $trigger, string $text, array $channels): AutoReply
    {
        return AutoReply::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'name' => $trigger,
            'trigger' => $trigger,
            'match_criteria' => 'contains',
            'channels' => $channels,
            'metadata' => json_encode(['type' => 'text', 'data' => ['text' => $text]]),
            'created_by' => $this->owner->id,
            'created_at' => now(),
        ]);
    }

    private function replyForm(array $overrides = []): array
    {
        return array_merge([
            'name' => 'ترحيب',
            'trigger' => 'مرحبا',
            'match_criteria' => 'contains',
            'response_type' => 'text',
            'response' => 'أهلاً',
            'channels' => [Channel::WHATSAPP],
        ], $overrides);
    }

    /** رسالة من جهة على قناتها تمرّ بالمسار الحقيقي. */
    private function says(Contact $contact, string $text): Chat
    {
        $chat = Chat::create([
            'organization_id' => $contact->organization_id,
            'contact_id' => $contact->id,
            'type' => 'inbound',
            'metadata' => json_encode(['type' => 'text', 'text' => ['body' => $text]]),
            'status' => 'delivered',
            'channel' => $contact->channel,
        ]);
        app(AutoReplyService::class)->checkAutoReply($chat, false);

        return $chat;
    }

    /** @return array<int, ?string> */
    private function outboundBodies(Contact $contact): array
    {
        return Chat::where('contact_id', $contact->id)->where('type', 'outbound')->orderBy('id')->get()
            ->map(fn (Chat $chat) => json_decode($chat->metadata, true)['text']['body'] ?? null)
            ->all();
    }

    private function asOwner(): static
    {
        return $this->actingAs($this->owner, 'user')
            ->withSession(['current_organization' => $this->organization->id]);
    }

    private function saveFlow(Flow $flow, array $graph)
    {
        return $this->asOwner()->putJson('/automation/flows/' . $flow->uuid, [
            'metadata' => json_encode($graph, JSON_UNESCAPED_UNICODE),
        ]);
    }

    private function inertiaProps(string $url): array
    {
        $response = $this->asOwner()->get($url)->assertOk();

        preg_match('/data-page="([^"]+)"/', $response->getContent(), $matches);
        $this->assertNotEmpty($matches, 'الصفحة لا تحمل بيانات Inertia: ' . $url);

        return json_decode(html_entity_decode($matches[1], ENT_QUOTES), true)['props'];
    }
}
