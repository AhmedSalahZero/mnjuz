<?php

namespace Tests\Feature\Cycle;

use App\Events\ContactAiAssistanceUpdated;
use App\Events\NewChatEvent;
use App\Models\Addon;
use App\Models\Chat;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\SubscriptionPlan;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\IntelliReply\Services\AIResponseService;

/**
 * مساعد الذكاء الاصطناعي في المحادثة: من المفتاح إلى الردّ على واتساب.
 *
 * العطل: يُفعَّل المفتاح فيعمل، ثم تُعاد تحميل الصفحة فيعود مطفأً — والحفظ في
 * قاعدة البيانات سليم، لكن الصفحة لا تُرسل الحقل أصلاً فتقرؤه الواجهة undefined.
 *
 * ولا يكفي اختبار الحقل وحده: السؤال الحقيقي هل يردّ المساعد فعلاً بعد التفعيل.
 * فهنا الدورة كلها بطبقاتها — المفتاح، ثم الصفحة، ثم رسالة واردة تمرّ بالـ
 * webhook والطابور وخدمة الردّ الآلي، ثم طلب OpenAI، ثم الإرسال إلى واتساب —
 * والخارج وحده مزيَّف (OpenAI عبر Http، وواتساب عبر عميل Guzzle وهمي).
 */
class AiAssistantCycleTest extends CycleTestCase
{
    private const REPLY = 'أهلاً بك، كيف أخدمك؟';

    /** @var array<int, array{request: \Psr\Http\Message\RequestInterface}> طلبات Graph الصادرة. */
    private array $graphRequests = [];

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([NewChatEvent::class, ContactAiAssistanceUpdated::class]);
        $this->enableAiModule();
        $this->fakeExternalServices();
    }

    // ------------------------------------------------- التجهيز

    /** الإضافة مفعّلة، والخطة تشملها، ومفتاح OpenAI صالح الشكل في إعدادات المنشأة. */
    private function enableAiModule(array $aiOverrides = []): void
    {
        Addon::factory()->create(['name' => 'AI Assistant', 'status' => 1, 'is_active' => 1]);

        $plan = SubscriptionPlan::firstOrFail();
        $plan->update(['metadata' => json_encode(['message_limit' => -1, 'addons' => ['AI Assistant' => true]])]);

        $metadata = json_decode($this->organization->metadata, true);
        // الردّ التلقائي مطفأ عمداً: العلم على جهة الاتصال هو المفتاح الوحيد
        // الذي يقرّر، فلا يُخفي تفعيلٌ تلقائيّ فشلَ المفتاح.
        $metadata['ai'] = array_merge([
            'active' => true,
            'api_key' => 'sk-test-key',
            'model' => 'gpt-4o-mini',
            'enable_automatic_responses' => false,
            'start_keywords' => '',
            'stop_keywords' => '',
        ], $aiOverrides);
        $this->organization->update(['metadata' => json_encode($metadata)]);
    }

    /**
     * ردّ OpenAI. نبدّل المصنع كلّه: Http::fake مرّة ثانية لا يستبدل الأولى،
     * فيظلّ الردّ الأول هو الذي يطابق.
     */
    private function fakeOpenAi(array $body, int $status = 200): void
    {
        Http::swap(new HttpFactory());
        Http::fake(['api.openai.com/*' => Http::response($body, $status)]);
    }

    private function fakeExternalServices(): void
    {
        $this->fakeOpenAi([
            'choices' => [['message' => ['role' => 'assistant', 'content' => self::REPLY]]],
        ]);

        // واتساب: عميل Guzzle وهمي يسجّل ما يُرسَل إلى Graph ويردّ بمعرّف رسالة.
        // معرّف مختلف لكل ردّ: wam_id فريد في المحادثة، ومكرَّره يُخزَّن مرة واحدة.
        $mock = new MockHandler(array_map(
            fn (int $n) => new Response(200, [], json_encode(['messages' => [['id' => 'wamid.AI-REPLY-' . $n]]])),
            [1, 2, 3]
        ));
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($this->graphRequests));
        $this->app->bind(Client::class, fn () => new Client(['handler' => $stack]));

        // البحث في وثائق المنشأة يمرّ بمكتبة openai-php لا بـ Http، فلا يُزيَّف
        // بالطريقة نفسها. نتجاوزه وحده؛ ما سواه حقيقي.
        $this->app->bind(AIResponseService::class, fn () => new class extends AIResponseService {
            protected function findClosestDocumentByQuery(int $organizationId, ?string $query): array
            {
                return ['success' => false];
            }
        });
    }

    private function asAgent(): void
    {
        $this->actingAs($this->owner);
        session(['current_organization' => $this->organization->id]);
    }

    /** كما يرسله المتصفح: الردّ redirect إلى الصفحة نفسها لا JSON. */
    private function toggle(Contact $contact, $value)
    {
        $this->asAgent();

        return $this->post('/automation/contact/' . $contact->uuid, ['ai_assistant' => $value]);
    }

    private function openedContact(Contact $contact): array
    {
        $this->asAgent();
        $response = $this->get('/chats/' . $contact->uuid);
        $response->assertStatus(200);

        return $response->viewData('page')['props']['contact'];
    }

    /** رسالة نصية تصل من العميل عبر webhook ميتا، فتمرّ بالطابور المتزامن كله. */
    private function customerWrites(string $text = 'السلام عليكم'): void
    {
        $this->postJson($this->webhookUrl(), $this->payload('messages', [
            'contacts' => [['profile' => ['name' => 'عميل'], 'wa_id' => '966502486051']],
            'messages' => [$this->textMessage(['text' => ['body' => $text]])],
        ]))->assertOk();
    }

    private function outboundChats()
    {
        return Chat::where('organization_id', $this->organization->id)->where('type', 'outbound')->get();
    }

    /** @return array<string, mixed> جسم أول طلب أُرسل إلى Graph. */
    private function graphBody(int $index = 0): array
    {
        return json_decode((string) $this->graphRequests[$index]['request']->getBody(), true);
    }

    private function openAiRequests(): array
    {
        return Http::recorded(fn (HttpRequest $request) => str_contains($request->url(), 'api.openai.com'))
            ->map(fn ($pair) => $pair[0])
            ->values()
            ->all();
    }

    // ------------------------------------------------- الحفظ

    public function test_enabling_persists_in_the_database(): void
    {
        $contact = $this->contact();

        $this->toggle($contact, true)->assertRedirect();

        $this->assertSame(1, (int) $contact->fresh()->ai_assistance_enabled);
    }

    public function test_disabling_persists_in_the_database(): void
    {
        $contact = $this->contact(['ai_assistance_enabled' => 1]);

        $this->toggle($contact, false)->assertRedirect();

        $this->assertSame(0, (int) $contact->fresh()->ai_assistance_enabled);
    }

    /** ما يرسله Inertia من الواجهة نصّاً (true/false/1/0) يُقبل كلّه. */
    public function test_the_values_a_browser_can_send_are_accepted(): void
    {
        $contact = $this->contact();

        foreach ([[true, 1], [false, 0], ['1', 1], ['0', 0], [1, 1], [0, 0]] as [$sent, $stored]) {
            $this->toggle($contact, $sent)->assertRedirect();
            $this->assertSame($stored, (int) $contact->fresh()->ai_assistance_enabled, 'القيمة المرسلة: ' . json_encode($sent));
        }
    }

    public function test_a_value_that_is_not_a_boolean_is_rejected_and_nothing_changes(): void
    {
        $contact = $this->contact(['ai_assistance_enabled' => 1]);

        // كما يرسل المتصفح (Inertia لا يطلب JSON): الخطأ يعود redirect مع أخطاء الجلسة.
        $this->toggle($contact, 'ربما')->assertSessionHasErrors('ai_assistant');
        $this->assertSame(1, (int) $contact->fresh()->ai_assistance_enabled);
    }

    public function test_a_missing_value_is_rejected(): void
    {
        $contact = $this->contact();
        $this->asAgent();

        $this->post('/automation/contact/' . $contact->uuid, [])->assertSessionHasErrors('ai_assistant');
        $this->assertSame(0, (int) $contact->fresh()->ai_assistance_enabled);
    }

    /** الـ uuid وحده لا يكفي لتعديل جهة اتصال في منظمة أخرى. */
    public function test_another_organizations_contact_cannot_be_changed(): void
    {
        $stranger = Organization::factory()->create(['created_by' => $this->owner->id]);
        $foreign = Contact::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $stranger->id,
            'first_name' => 'غريب',
            'phone' => '+966500000001',
            'created_by' => $this->owner->id,
        ]);

        $this->asAgent();
        $this->post('/automation/contact/' . $foreign->uuid, ['ai_assistant' => true])->assertNotFound();

        $this->assertSame(0, (int) $foreign->fresh()->ai_assistance_enabled);
    }

    public function test_an_unknown_contact_is_not_found(): void
    {
        $this->asAgent();

        $this->post('/automation/contact/' . Str::uuid(), ['ai_assistant' => true])->assertNotFound();
    }

    public function test_a_guest_cannot_toggle(): void
    {
        $contact = $this->contact();

        $this->postJson('/automation/contact/' . $contact->uuid, ['ai_assistant' => true])->assertUnauthorized();

        $this->assertSame(0, (int) $contact->fresh()->ai_assistance_enabled);
    }

    // ------------------------------------------------- إعادة التحميل (العطل نفسه)

    public function test_the_flag_survives_a_reload(): void
    {
        $contact = $this->contact();
        $this->assertFalse($this->openedContact($contact)['ai_assistance_enabled']);

        $this->toggle($contact, true)->assertRedirect();

        // «reload»: طلب جديد للصفحة نفسها.
        $this->assertTrue($this->openedContact($contact)['ai_assistance_enabled'], 'عاد المفتاح مطفأً بعد إعادة التحميل.');
    }

    public function test_turning_it_off_also_survives_a_reload(): void
    {
        $contact = $this->contact(['ai_assistance_enabled' => 1]);
        $this->assertTrue($this->openedContact($contact)['ai_assistance_enabled']);

        $this->toggle($contact, false)->assertRedirect();

        $this->assertFalse($this->openedContact($contact)['ai_assistance_enabled']);
    }

    /** كل محادثة تحمل حالتها هي: تفعيل واحدة لا يُظهر الأخرى مفعّلة. */
    public function test_each_conversation_reports_its_own_state(): void
    {
        $first = $this->contact(['phone' => '+966500000011']);
        $second = $this->contact(['phone' => '+966500000012']);

        $this->toggle($first, true)->assertRedirect();

        $this->assertTrue($this->openedContact($first)['ai_assistance_enabled']);
        $this->assertFalse($this->openedContact($second)['ai_assistance_enabled']);
    }

    // ------------------------------------------------- الدورة كاملة

    /**
     * التفعيل من الواجهة ثم رسالة من العميل ⇐ يردّ المساعد على واتساب.
     */
    public function test_after_enabling_a_customer_message_gets_an_ai_reply_on_whatsapp(): void
    {
        $contact = $this->contact();
        $this->toggle($contact, true)->assertRedirect();
        $this->assertTrue($this->openedContact($contact)['ai_assistance_enabled']);

        $this->customerWrites('السلام عليكم');

        // 1) طُلب الردّ من OpenAI بمفتاح المنشأة، ورسالة العميل في السياق
        $openAi = $this->openAiRequests();
        $this->assertCount(1, $openAi, 'لم يُطلب ردّ من OpenAI.');
        $this->assertSame('Bearer sk-test-key', $openAi[0]->header('Authorization')[0]);
        $this->assertSame('gpt-4o-mini', $openAi[0]->data()['model']);
        $this->assertContains(
            ['role' => 'user', 'content' => 'السلام عليكم'],
            $openAi[0]->data()['messages'],
            'رسالة العميل لم تدخل سياق المحادثة.'
        );

        // 2) أُرسل الردّ إلى واتساب لرقم العميل بنصّ OpenAI
        $this->assertCount(1, $this->graphRequests, 'لم يُرسَل شيء إلى واتساب.');
        $request = $this->graphRequests[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        // الإصدار v18.0 ومعرّف الرقم من metadata المنشأة (phone_number_id = 2)
        $this->assertSame('https://graph.facebook.com/v18.0/2/messages', (string) $request->getUri());
        $this->assertSame('Bearer test-token', $request->getHeaderLine('Authorization'));
        $this->assertSame($contact->phone, $this->graphBody()['to']);
        $this->assertSame(self::REPLY, $this->graphBody()['text']['body']);

        // 3) وحُفظ الردّ صادراً في المحادثة
        $outbound = $this->outboundChats();
        $this->assertCount(1, $outbound);
        $this->assertSame('wamid.AI-REPLY-1', $outbound[0]->wam_id);
        $this->assertSame($contact->id, (int) $outbound[0]->contact_id);
        $this->assertSame(self::REPLY, json_decode($outbound[0]->metadata, true)['text']['body']);
    }

    /** وبقيّة الرسائل في المحادثة نفسها يردّ عليها أيضاً، لا الأولى فقط. */
    public function test_every_following_message_is_answered_too(): void
    {
        $contact = $this->contact(['ai_assistance_enabled' => 1]);

        $this->customerWrites('أولى');
        $this->customerWrites('ثانية');

        $this->assertCount(2, $this->graphRequests);
        $this->assertCount(2, $this->outboundChats());
    }

    /** الدورة العكسية: التفعيل ثم الإيقاف ⇒ الصمت. */
    public function test_after_disabling_no_reply_is_sent(): void
    {
        $contact = $this->contact();
        $this->toggle($contact, true)->assertRedirect();
        $this->toggle($contact, false)->assertRedirect();

        $this->customerWrites();

        $this->assertCount(0, $this->openAiRequests(), 'سُئل OpenAI رغم الإيقاف.');
        $this->assertCount(0, $this->graphRequests, 'أُرسل ردّ رغم الإيقاف.');
        $this->assertCount(0, $this->outboundChats());
    }

    public function test_a_contact_who_never_enabled_it_gets_no_reply(): void
    {
        $this->contact();

        $this->customerWrites();

        Http::assertNothingSent();
        $this->assertCount(0, $this->graphRequests);
        $this->assertCount(0, $this->outboundChats());
    }

    /** المفتاح يخصّ محادثة واحدة: تفعيله لعميل لا يردّ به المساعد على عميل آخر. */
    public function test_enabling_one_contact_does_not_answer_another(): void
    {
        $enabled = $this->contact(['phone' => '+966500000021', 'ai_assistance_enabled' => 1]);
        $other = $this->contact(['phone' => '+966502486051']);

        $this->customerWrites();

        $this->assertCount(0, $this->graphRequests);
        $this->assertSame(0, Chat::where('contact_id', $other->id)->where('type', 'outbound')->count());
        $this->assertSame(0, Chat::where('contact_id', $enabled->id)->count());
    }

    /** إيقاف الإضافة على مستوى المنشأة يغلب مفتاح المحادثة. */
    public function test_the_organization_switch_overrides_the_contact_flag(): void
    {
        $this->contact(['ai_assistance_enabled' => 1]);
        $metadata = json_decode($this->organization->fresh()->metadata, true);
        $metadata['ai']['active'] = false;
        $this->organization->update(['metadata' => json_encode($metadata)]);

        $this->customerWrites();

        $this->assertCount(0, $this->openAiRequests());
        $this->assertCount(0, $this->graphRequests);
    }

    /** ومفتاح OpenAI غير الصالح لا يُرسَل طلبه، ولا يردّ أحد. */
    public function test_an_unusable_openai_key_sends_nothing(): void
    {
        $this->contact(['ai_assistance_enabled' => 1]);
        $metadata = json_decode($this->organization->fresh()->metadata, true);
        $metadata['ai']['api_key'] = 'not-a-valid-key';
        $this->organization->update(['metadata' => json_encode($metadata)]);

        $this->customerWrites();

        $this->assertCount(0, $this->openAiRequests());
        $this->assertCount(0, $this->graphRequests);
    }

    /** إن فشل OpenAI لا يُرسَل للعميل شيء ولا تنهار المعالجة. */
    public function test_an_openai_failure_sends_nothing_to_the_customer(): void
    {
        $this->fakeOpenAi(['error' => ['message' => 'quota']], 429);
        $this->contact(['ai_assistance_enabled' => 1]);

        $this->customerWrites();

        $this->assertCount(0, $this->graphRequests);
        $this->assertCount(0, $this->outboundChats());
    }

    // ------------------------------------------------- إشعار الشاشات المفتوحة

    /** الحدث يحمل ما تحتاجه الواجهة: أيّ محادثة، وما القيمة الجديدة. */
    private function assertAiUpdate(Contact $contact, bool $enabled): void
    {
        Event::assertDispatched(ContactAiAssistanceUpdated::class, fn (ContactAiAssistanceUpdated $event) =>
            $event->organizationId === $this->organization->id
            && $event->contactId === $contact->id
            // HasUuid يترك الـ uuid كائناً على النموذج المنشأ حديثاً، فنحوّله نصّاً للمقارنة.
            && $event->contactUuid === (string) $contact->uuid
            && $event->enabled === $enabled
        );
    }

    public function test_toggling_it_on_notifies_other_open_screens(): void
    {
        $contact = $this->contact();

        $this->toggle($contact, true)->assertRedirect();

        $this->assertAiUpdate($contact, true);
        Event::assertDispatchedTimes(ContactAiAssistanceUpdated::class, 1);
    }

    public function test_toggling_it_off_notifies_too(): void
    {
        $contact = $this->contact(['ai_assistance_enabled' => 1]);

        $this->toggle($contact, false)->assertRedirect();

        $this->assertAiUpdate($contact, false);
    }

    /** لا تغيّر ⇒ لا كتابة ولا بثّ: الضغط المتكرر لا يُغرق القناة. */
    public function test_setting_the_same_value_notifies_nobody(): void
    {
        $contact = $this->contact(['ai_assistance_enabled' => 1]);

        $this->toggle($contact, true)->assertRedirect();

        Event::assertNotDispatched(ContactAiAssistanceUpdated::class);
    }

    public function test_a_failed_toggle_notifies_nobody(): void
    {
        $contact = $this->contact();

        $this->toggle($contact, 'ربما')->assertSessionHasErrors('ai_assistant');

        Event::assertNotDispatched(ContactAiAssistanceUpdated::class);
    }

    /** العطل الذي طلبه العميل: الخادم يطفئه بنفسه ⇒ الشاشة المفتوحة تعلم. */
    public function test_a_stop_keyword_from_the_customer_notifies_the_open_screen(): void
    {
        $this->enableAiModule(['stop_keywords' => 'stop,توقف']);
        $contact = $this->contact(['ai_assistance_enabled' => 1]);

        $this->customerWrites('توقف من فضلك');

        $this->assertAiUpdate($contact, false);
        Event::assertDispatchedTimes(ContactAiAssistanceUpdated::class, 1);
    }

    public function test_a_start_keyword_from_the_customer_notifies_the_open_screen(): void
    {
        $this->enableAiModule(['start_keywords' => 'مساعد']);
        $contact = $this->contact();

        $this->customerWrites('أريد المساعد');

        $this->assertAiUpdate($contact, true);
        Event::assertDispatchedTimes(ContactAiAssistanceUpdated::class, 1);
    }

    /** والتفعيل التلقائي عند أول رسالة. */
    public function test_the_automatic_enable_on_a_first_message_notifies_the_open_screen(): void
    {
        $this->enableAiModule(['enable_automatic_responses' => true]);
        $contact = $this->contact();

        $this->customerWrites('السلام عليكم');

        $this->assertSame(1, (int) $contact->fresh()->ai_assistance_enabled);
        $this->assertAiUpdate($contact, true);
        $this->assertCount(1, $this->graphRequests, 'فُعِّل تلقائياً ولم يردّ.');
    }

    /** كلمة إيقاف على محادثة مطفأة أصلاً، ورسالة عادية على مفعّلة: لا بثّ بلا تغيّر. */
    public function test_a_message_that_changes_nothing_notifies_nobody(): void
    {
        $this->enableAiModule(['stop_keywords' => 'توقف']);
        $off = $this->contact(['phone' => '+966500000031']);
        $on = $this->contact(['phone' => '+966502486051', 'ai_assistance_enabled' => 1]);

        $this->customerWrites('أهلاً');                          // مفعّلة + لا كلمة ⇒ يبقى كما هو
        Event::assertNotDispatched(ContactAiAssistanceUpdated::class);

        $this->assertSame(1, (int) $on->fresh()->ai_assistance_enabled);
        $this->assertSame(0, (int) $off->fresh()->ai_assistance_enabled);
    }

    /** وبعد البثّ تعرض الصفحة الحالة الجديدة عند التحميل أيضاً. */
    public function test_the_page_shows_what_the_server_changed(): void
    {
        $this->enableAiModule(['stop_keywords' => 'توقف']);
        $contact = $this->contact(['ai_assistance_enabled' => 1]);
        $this->assertTrue($this->openedContact($contact)['ai_assistance_enabled']);

        $this->customerWrites('توقف');

        $this->assertFalse($this->openedContact($contact)['ai_assistance_enabled']);
    }

    // ------------------------------------------------- كلمات البدء والإيقاف

    public function test_a_stop_keyword_switches_it_off_for_that_contact(): void
    {
        $this->enableAiModule(['stop_keywords' => 'stop,توقف']);
        $contact = $this->contact(['ai_assistance_enabled' => 1]);

        $this->customerWrites('توقف من فضلك');

        $this->assertSame(0, (int) $contact->fresh()->ai_assistance_enabled);
        $this->assertCount(0, $this->graphRequests);
        // والصفحة تعرض الحالة الجديدة بعد التحميل
        $this->assertFalse($this->openedContact($contact)['ai_assistance_enabled']);
    }

    public function test_a_start_keyword_switches_it_on_and_gets_a_reply(): void
    {
        $this->enableAiModule(['start_keywords' => 'مساعد']);
        $contact = $this->contact();

        $this->customerWrites('أريد المساعد');

        $this->assertSame(1, (int) $contact->fresh()->ai_assistance_enabled);
        $this->assertCount(1, $this->graphRequests);
        $this->assertTrue($this->openedContact($contact)['ai_assistance_enabled']);
    }
}
