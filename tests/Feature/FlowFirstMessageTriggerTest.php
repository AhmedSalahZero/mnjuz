<?php

namespace Tests\Feature;

use App\Models\Addon;
use App\Models\Chat;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\FlowBuilder\Models\Flow;
use Modules\FlowBuilder\Models\FlowUserData;
use Modules\FlowBuilder\Services\FlowExecutionService;
use Modules\FlowBuilder\Services\FlowService;
use Tests\TestCase;

/**
 * محفّز «أوّل رسالة من العميل».
 *
 * `new_contact` يسأل «هل صفّ جهة الاتصال أُنشئ الآن؟» لا «هل هذه أوّل مرة
 * يكلّمنا فيها؟». والأرقام تدخل النظام من الاستيراد والحملات والإضافة
 * اليدوية قبل أن يكتب أصحابها حرفاً — فرسالة الترحيب كانت تفوت أكثر
 * العملاء، وهو ما اشتكى منه صاحب الحساب.
 */
class FlowFirstMessageTriggerTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;
    private Contact $contact;
    private FlowExecutionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        // جداول الوحدة لا تُحمَّل مع ترحيلات التطبيق
        $this->artisan('migrate', ['--path' => 'modules/FlowBuilder/Database/Migrations'])->run();

        $user = User::factory()->create(['role' => 'user']);
        $this->organization = Organization::factory()->create([
            'created_by' => $user->id,
            'metadata' => json_encode(['whatsapp' => [
                'access_token' => 't', 'app_id' => '1', 'phone_number_id' => '2', 'waba_id' => '3',
            ]]),
        ]);
        Addon::factory()->create(['name' => 'Flow builder', 'status' => 1, 'is_active' => 1]);

        // isModuleEnabled يشترط اشتراكاً وخطةً تُدرج الإضافة — بدونه يخرج
        // executeFlow من أوّل سطر ويمرّ اختبار الوصل لسبب خاطئ.
        $plan = SubscriptionPlan::create([
            'name' => 'P',
            'price' => 0,
            'period' => 'monthly',
            'metadata' => json_encode(['addons' => ['Flow builder' => true]]),
        ]);
        Subscription::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'valid_until' => now()->addYear(),
        ]);

        $this->contact = Contact::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'first_name' => 'Maitha',
            'phone' => '+966500000001',
            'created_by' => $user->id,
        ]);

        $this->service = new FlowExecutionService($this->organization->id);
    }

    private function flow(string $trigger, ?string $keywords = null, string $status = 'active'): int
    {
        return DB::table('flows')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'name' => $trigger . '-' . Str::random(4),
            'trigger' => $trigger,
            'keywords' => $keywords,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function inbound(string $body = 'مرحبا', ?string $deletedAt = null): Chat
    {
        $chat = Chat::create([
            'organization_id' => $this->organization->id,
            'contact_id' => $this->contact->id,
            'type' => 'inbound',
            'metadata' => json_encode(['type' => 'text', 'text' => ['body' => $body]]),
            'status' => 'delivered',
        ]);

        if ($deletedAt !== null) {
            DB::table('chats')->where('id', $chat->id)->update(['deleted_at' => $deletedAt]);
        }

        return $chat;
    }

    /** يُرجع الرسالة بعد ضبط وقتها في القاعدة (المحوّل يمنع الكتابة المباشرة). */
    private function inboundAt(string $body, \Carbon\Carbon $at): Chat
    {
        $chat = $this->inbound($body);
        DB::table('chats')->where('id', $chat->id)->update(['created_at' => $at->toDateTimeString()]);

        return $chat;
    }

    private function timedFlow(string $trigger, int $timeoutMinutes): int
    {
        $id = $this->flow($trigger);
        DB::table('flows')->where('id', $id)->update(['trigger_timeout' => $timeoutMinutes]);

        return $id;
    }

    private function outbound(): Chat
    {
        return Chat::create([
            'organization_id' => $this->organization->id,
            'contact_id' => $this->contact->id,
            'type' => 'outbound',
            'metadata' => json_encode(['type' => 'text', 'text' => ['body' => 'حملة']]),
            'status' => 'sent',
        ]);
    }

    // ------------------------------------------------- أوّل رسالة

    public function test_the_first_inbound_message_is_recognised(): void
    {
        $chat = $this->inbound();

        $this->assertTrue($this->service->isFirstInboundMessage($chat));
    }

    public function test_the_second_message_is_not_the_first(): void
    {
        $this->inbound('السلام عليكم');
        $second = $this->inbound('عندي سؤال');

        $this->assertFalse($this->service->isFirstInboundMessage($second));
    }

    /** الحملة الصادرة لا تُلغي كون رسالة العميل هي الأولى. */
    public function test_an_earlier_outbound_message_does_not_count(): void
    {
        $this->outbound();
        $chat = $this->inbound();

        $this->assertTrue($this->service->isFirstInboundMessage($chat));
    }

    /**
     * المحذوفة تُحسب: حذف موظّفٍ للمحادثة لا يجعل العميل جديداً، وإلا تكرّرت
     * رسالة الترحيب مع كل حذف عابر.
     */
    public function test_a_deleted_earlier_message_still_counts(): void
    {
        $this->inbound('رسالة حُذفت', now()->toDateTimeString());
        $second = $this->inbound('رسالة جديدة');

        $this->assertFalse($this->service->isFirstInboundMessage($second));
    }

    /** رسالة عميل آخر لا تمسّ حساب هذا العميل. */
    public function test_another_contacts_history_is_irrelevant(): void
    {
        $other = Contact::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'phone' => '+966500000002',
            'created_by' => 0,
        ]);
        Chat::create([
            'organization_id' => $this->organization->id,
            'contact_id' => $other->id,
            'type' => 'inbound',
            'metadata' => json_encode(['type' => 'text', 'text' => ['body' => 'أنا غيره']]),
            'status' => 'delivered',
        ]);

        $chat = $this->inbound();

        $this->assertTrue($this->service->isFirstInboundMessage($chat));
    }

    // ------------------------------------------------- اختيار الـ flow

    /** الحالة التي طلبها العميل: رقم مستورد يراسل لأوّل مرة. */
    public function test_an_imported_contact_writing_for_the_first_time_triggers_the_flow(): void
    {
        $flowId = $this->flow('first_message');
        $chat = $this->inbound();

        // isNewContact = false: الصفّ كان موجوداً قبل الرسالة (استيراد أو حملة)
        $flow = $this->service->resolveTriggeredFlow($chat, false, 'مرحبا');

        $this->assertNotNull($flow, 'المستورد يجب أن يستقبل الترحيب — وهذا ما كان يفوت');
        $this->assertSame($flowId, (int) $flow->id);
    }

    public function test_the_second_message_does_not_trigger_it_again(): void
    {
        $this->flow('first_message');
        $this->inbound('السلام عليكم');
        $second = $this->inbound('عندي سؤال');

        $this->assertNull($this->service->resolveTriggeredFlow($second, false, 'عندي سؤال'));
    }

    /** الأولوية: first_message يسبق new_contact حين يتحقّق الشرطان معاً. */
    public function test_first_message_wins_over_new_contact(): void
    {
        $newContactFlow = $this->flow('new_contact');
        $firstMessageFlow = $this->flow('first_message');

        $chat = $this->inbound();
        $flow = $this->service->resolveTriggeredFlow($chat, true, 'مرحبا');

        $this->assertSame($firstMessageFlow, (int) $flow->id);
        $this->assertNotSame($newContactFlow, (int) $flow->id);
    }

    /** وبغيابه يعود new_contact كما كان. */
    public function test_new_contact_still_works_on_its_own(): void
    {
        $newContactFlow = $this->flow('new_contact');
        $chat = $this->inbound();

        $flow = $this->service->resolveTriggeredFlow($chat, true, 'مرحبا');

        $this->assertSame($newContactFlow, (int) $flow->id);
    }

    /** الكلمات المفتاحية تبقى تعمل لمن راسلنا من قبل. */
    public function test_keywords_still_match_for_a_returning_contact(): void
    {
        $keywordFlow = $this->flow('keywords', 'عرض,اسعار');
        $this->inbound('السلام عليكم');
        $second = $this->inbound('عرض');

        $flow = $this->service->resolveTriggeredFlow($second, false, 'عرض');

        $this->assertSame($keywordFlow, (int) $flow->id);
    }

    /** أوّل رسالة بلا flow مخصّص لها تسقط إلى الكلمات المفتاحية. */
    public function test_the_first_message_falls_back_to_keywords(): void
    {
        $keywordFlow = $this->flow('keywords', 'عرض');
        $chat = $this->inbound('عرض');

        $flow = $this->service->resolveTriggeredFlow($chat, false, 'عرض');

        $this->assertSame($keywordFlow, (int) $flow->id);
    }

    public function test_an_inactive_flow_is_ignored(): void
    {
        $this->flow('first_message', null, 'inactive');
        $chat = $this->inbound();

        $this->assertNull($this->service->resolveTriggeredFlow($chat, false, 'مرحبا'));
    }

    public function test_another_organizations_flow_is_ignored(): void
    {
        DB::table('flows')->insert([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id + 999,
            'name' => 'غريب',
            'trigger' => 'first_message',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $chat = $this->inbound();

        $this->assertNull($this->service->resolveTriggeredFlow($chat, false, 'مرحبا'));
    }

    // ------------------------------------------------- الحفظ من الواجهة

    /**
     * المحفّز يُقرأ من عقدة البداية ويُكتب في العمود.
     *
     * العمود enum، وقيمة خارجه يقصّها MySQL إلى فراغ **صامتاً** — فيُحفظ
     * الـ flow بلا محفّز ولا يشتغل أبداً بلا رسالة خطأ.
     */
    public function test_the_canvas_saves_the_new_trigger(): void
    {
        $flow = Flow::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'name' => 'ترحيب',
            'status' => 'inactive',
        ]);

        $metadata = json_encode([
            'nodes' => [
                [
                    'id' => '1',
                    'type' => 'start',
                    'data' => ['metadata' => ['fields' => ['type' => 'first_message', 'keywords' => null]]],
                ],
                [
                    'id' => '2',
                    'type' => 'text',
                    'data' => ['metadata' => ['fields' => ['type' => 'text', 'body' => 'أهلاً بك']]],
                ],
            ],
            'edges' => [['id' => 'e1', 'source' => '1', 'target' => '2']],
        ]);

        (new FlowService())->updateFlow($flow->uuid, ['metadata' => $metadata], 1);

        $saved = Flow::find($flow->id);

        $this->assertSame('first_message', $saved->trigger, 'المحفّز لم يصل العمود — تحقّق من enum');
        $this->assertSame('active', $saved->status, 'المدقّق رفض نشر flow بالمحفّز الجديد');
    }

    /** والقيمة تصل القاعدة كما هي لا مقصوصة. */
    public function test_the_column_accepts_the_new_value(): void
    {
        $id = $this->flow('first_message');

        $this->assertSame('first_message', DB::table('flows')->where('id', $id)->value('trigger'));
    }

    // ------------------------------------------------- الوصل داخل executeFlow

    /**
     * المسار الحقيقي: رسالة واردة ⇒ اختيار الـ flow ⇒ تسجيل العميل فيه.
     *
     * الاختيار وحده لا يكفي: لو لم يستدعه executeFlow لبقي الترحيب معطّلاً
     * وكل اختبارات الاختيار خضراء. نُبدّل processFlow وحده كي لا يخرج
     * الاختبار إلى الشبكة.
     */
    public function test_execute_flow_registers_the_contact_in_the_first_message_flow(): void
    {
        $flowId = $this->flow('first_message');
        $chat = $this->inbound();

        $service = $this->getMockBuilder(FlowExecutionService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['processFlow'])
            ->getMock();

        $service->expects($this->once())->method('processFlow')->willReturn(true);

        $service->executeFlow($chat, false, 'مرحبا');

        $registered = FlowUserData::where('contact_id', $this->contact->id)->first();

        $this->assertNotNull($registered, 'لم يُسجَّل العميل في أي flow');
        $this->assertSame($flowId, (int) $registered->flow_id);
        $this->assertSame(1, (int) $registered->current_step);
    }

    /** ورسالته الثانية لا تُدخله الـ flow من جديد. */
    public function test_execute_flow_ignores_the_second_message(): void
    {
        $this->flow('first_message');
        $this->inbound('السلام عليكم');
        $second = $this->inbound('عندي سؤال');

        $service = $this->getMockBuilder(FlowExecutionService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['processFlow'])
            ->getMock();

        $service->expects($this->never())->method('processFlow');

        $service->executeFlow($second, false, 'عندي سؤال');

        $this->assertNull(FlowUserData::where('contact_id', $this->contact->id)->first());
    }

    // ------------------------------------------------- المهلة: العودة بعد صمت

    /**
     * سيناريو العميل حرفياً.
     *
     * راسلنا الساعة 3 فوصله «كيف نقدر نخدمك؟» وانتهى الـ flow، فحُذفت جلسته.
     * ثم عاد بعد ساعتين — واليوم لا يحدث شيء إطلاقاً. بالمهلة يُستقبل من جديد.
     */
    public function test_a_contact_returning_after_the_timeout_restarts_the_flow(): void
    {
        $flowId = $this->timedFlow('first_message', 60);

        $this->inboundAt('السلام عليكم', now()->subHours(2));
        $back = $this->inboundAt('مرحبا', now());

        $flow = $this->service->resolveTriggeredFlow($back, false, 'مرحبا');

        $this->assertNotNull($flow, 'العائد بعد المهلة يجب أن يستقبل الترحيب');
        $this->assertSame($flowId, (int) $flow->id);
    }

    /** وقبل انقضائها لا شيء — وإلا تكرّر الترحيب في المحادثة الواحدة. */
    public function test_a_reply_within_the_timeout_does_not_restart_it(): void
    {
        $this->timedFlow('first_message', 60);

        $this->inboundAt('السلام عليكم', now()->subMinutes(50));
        $soon = $this->inboundAt('أبغى أستفسر', now());

        $this->assertNull($this->service->resolveTriggeredFlow($soon, false, 'أبغى أستفسر'));
    }

    /** الحدّ نفسه يُعيد التشغيل: 60 دقيقة على مهلة 60. */
    public function test_the_boundary_minute_counts(): void
    {
        $flowId = $this->timedFlow('first_message', 60);

        $this->inboundAt('السلام عليكم', now()->subMinutes(60));
        $back = $this->inboundAt('مرحبا', now());

        $this->assertSame($flowId, (int) $this->service->resolveTriggeredFlow($back, false, 'مرحبا')->id);
    }

    /** بلا مهلة يبقى السلوك القديم: مرّة واحدة في عمر العميل. */
    public function test_without_a_timeout_nothing_changes(): void
    {
        $this->flow('first_message'); // trigger_timeout = null

        $this->inboundAt('السلام عليكم', now()->subDays(30));
        $back = $this->inboundAt('مرحبا', now());

        $this->assertNull($this->service->resolveTriggeredFlow($back, false, 'مرحبا'));
    }

    /** المهلة تعمل مع new_contact أيضاً. */
    public function test_the_timeout_works_for_new_contact_flows(): void
    {
        $flowId = $this->timedFlow('new_contact', 30);

        $this->inboundAt('السلام عليكم', now()->subHours(3));
        $back = $this->inboundAt('مرحبا', now());

        $this->assertSame($flowId, (int) $this->service->resolveTriggeredFlow($back, false, 'مرحبا')->id);
    }

    /** ولا تعمل مع keywords: ذاك يعيد نفسه بالكلمة لا بالوقت. */
    public function test_a_keywords_flow_is_never_restarted_by_the_timeout(): void
    {
        $id = $this->flow('keywords', 'عرض');
        DB::table('flows')->where('id', $id)->update(['trigger_timeout' => 30]);

        $this->inboundAt('السلام عليكم', now()->subHours(5));
        $back = $this->inboundAt('كلام لا يطابق', now());

        $this->assertNull($this->service->resolveTriggeredFlow($back, false, 'كلام لا يطابق'));
    }

    /** الكلمة المفتاحية أسبق من المهلة: من طلب شيئاً بعينه يريده لا الترحيب. */
    public function test_a_keyword_match_wins_over_the_timeout(): void
    {
        $this->timedFlow('first_message', 30);
        $keywordFlow = $this->flow('keywords', 'عرض');

        $this->inboundAt('السلام عليكم', now()->subHours(5));
        $back = $this->inboundAt('عرض', now());

        $this->assertSame($keywordFlow, (int) $this->service->resolveTriggeredFlow($back, false, 'عرض')->id);
    }

    /** المعطّل وflow المنشآت الأخرى لا يُستدعيان بالمهلة. */
    public function test_the_timeout_respects_status_and_organization(): void
    {
        $inactive = $this->flow('first_message', null, 'inactive');
        DB::table('flows')->where('id', $inactive)->update(['trigger_timeout' => 10]);

        DB::table('flows')->insert([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id + 999,
            'name' => 'غريب',
            'trigger' => 'first_message',
            'trigger_timeout' => 10,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->inboundAt('السلام عليكم', now()->subHours(5));
        $back = $this->inboundAt('مرحبا', now());

        $this->assertNull($this->service->resolveTriggeredFlow($back, false, 'مرحبا'));
    }

    /** الأقدم يفوز بين flows انقضت مهلتها. */
    public function test_the_oldest_timed_out_flow_wins(): void
    {
        $first = $this->timedFlow('first_message', 30);
        $this->timedFlow('new_contact', 30);

        $this->inboundAt('السلام عليكم', now()->subHours(5));
        $back = $this->inboundAt('مرحبا', now());

        $this->assertSame($first, (int) $this->service->resolveTriggeredFlow($back, false, 'مرحبا')->id);
    }

    /** أوّل رسالة تبقى أسبق: لا سابقة تُقاس عليها الفجوة أصلاً. */
    public function test_the_first_ever_message_still_uses_the_first_message_branch(): void
    {
        $flowId = $this->timedFlow('first_message', 60);
        $chat = $this->inbound('مرحبا');

        $this->assertSame($flowId, (int) $this->service->resolveTriggeredFlow($chat, false, 'مرحبا')->id);
    }

    // ------------------------------------------------- المهلة: الجلسة الراكدة

    /**
     * العميل سُئل «اختر 1 أو 2» ثم اختفى. جلسته تبقى مفتوحة، فكلمته بعد
     * أيام تُقرأ إجابةً على سؤال قديم. المهلة تُنهيها ويبدأ من جديد.
     */
    public function test_a_stale_session_is_abandoned_and_the_flow_restarts(): void
    {
        $flowId = $this->timedFlow('first_message', 60);

        $this->inboundAt('السلام عليكم', now()->subDays(3));
        FlowUserData::create([
            'contact_id' => $this->contact->id,
            'flow_id' => $flowId,
            'current_step' => 7,
        ]);
        DB::table('flow_user_data')->where('contact_id', $this->contact->id)
            ->update(['updated_at' => now()->subDays(3)]);

        $back = $this->inboundAt('مرحبا', now());

        $service = $this->getMockBuilder(FlowExecutionService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['processFlow'])
            ->getMock();
        $service->expects($this->once())->method('processFlow')->willReturn(true);

        $service->executeFlow($back, false, 'مرحبا');

        $session = FlowUserData::where('contact_id', $this->contact->id)->first();

        $this->assertNotNull($session);
        $this->assertSame(1, (int) $session->current_step, 'الجلسة الراكدة يجب أن تُهجر ويبدأ الـ flow من أوّله');
    }

    /** والجلسة الحيّة تُكمل مكانها ولا تُهجر. */
    public function test_a_fresh_session_is_not_abandoned(): void
    {
        $flowId = $this->timedFlow('first_message', 60);

        $this->inboundAt('السلام عليكم', now()->subMinutes(5));
        FlowUserData::create([
            'contact_id' => $this->contact->id,
            'flow_id' => $flowId,
            'current_step' => 7,
        ]);

        $back = $this->inboundAt('1', now());

        $service = $this->getMockBuilder(FlowExecutionService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['processFlow'])
            ->getMock();
        $service->expects($this->once())->method('processFlow')->willReturn(true);

        $service->executeFlow($back, false, '1');

        $session = FlowUserData::where('contact_id', $this->contact->id)->first();

        $this->assertNotNull($session, 'الجلسة الحيّة لا تُحذف');
        $this->assertSame(7, (int) $session->current_step, 'ولا تعود إلى أوّل خطوة');
    }

    /** flow بلا مهلة: جلسته لا تنتهي مهما ركدت — السلوك القديم لمن لم يضبط شيئاً. */
    public function test_a_session_of_a_flow_without_timeout_never_expires(): void
    {
        $flowId = $this->flow('first_message');

        $this->inboundAt('السلام عليكم', now()->subDays(30));
        FlowUserData::create([
            'contact_id' => $this->contact->id,
            'flow_id' => $flowId,
            'current_step' => 7,
        ]);
        DB::table('flow_user_data')->where('contact_id', $this->contact->id)
            ->update(['updated_at' => now()->subDays(30)]);

        $back = $this->inboundAt('مرحبا', now());

        $service = $this->getMockBuilder(FlowExecutionService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['processFlow'])
            ->getMock();
        $service->expects($this->once())->method('processFlow')->willReturn(true);

        $service->executeFlow($back, false, 'مرحبا');

        $this->assertSame(
            7,
            (int) FlowUserData::where('contact_id', $this->contact->id)->value('current_step')
        );
    }

    // ------------------------------------------------- حفظ المهلة

    public function test_the_canvas_saves_the_timeout(): void
    {
        $flow = Flow::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'name' => 'ترحيب',
            'status' => 'inactive',
        ]);

        (new FlowService())->updateFlow($flow->uuid, [
            'metadata' => $this->canvas('first_message', 90),
        ], 1);

        $saved = Flow::find($flow->id);

        $this->assertSame('first_message', $saved->trigger);
        $this->assertSame(90, (int) $saved->trigger_timeout);
    }

    /** الفراغ غياب لا صفر: مهلة صفرية تعني «معطّلة» فتُحفظ null. */
    public function test_an_empty_timeout_is_stored_as_null(): void
    {
        $flow = Flow::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'name' => 'ترحيب',
            'status' => 'inactive',
        ]);

        (new FlowService())->updateFlow($flow->uuid, [
            'metadata' => $this->canvas('first_message', null),
        ], 1);

        $this->assertNull(Flow::find($flow->id)->trigger_timeout);
    }

    /** metadata كما يبنيها الكانفس. */
    private function canvas(string $trigger, ?int $timeout): string
    {
        return json_encode([
            'nodes' => [
                [
                    'id' => '1',
                    'type' => 'start',
                    'data' => ['metadata' => ['fields' => [
                        'type' => $trigger,
                        'keywords' => null,
                        'timeout' => $timeout,
                    ]]],
                ],
                [
                    'id' => '2',
                    'type' => 'text',
                    'data' => ['metadata' => ['fields' => ['type' => 'text', 'body' => 'كيف نقدر نخدمك؟']]],
                ],
            ],
            'edges' => [['id' => 'e1', 'source' => '1', 'target' => '2']],
        ]);
    }

    // ------------------------------------------------- الاختيار الحاسم

    /**
     * أكثر من flow نشط بنفس المحفّز كان يُنتج اختياراً غير محدّد يتغيّر بين
     * استعلام وآخر. الأقدم يفوز دائماً.
     */
    public function test_the_oldest_flow_wins_when_several_share_a_trigger(): void
    {
        $first = $this->flow('first_message');
        $this->flow('first_message');
        $this->flow('first_message');

        $chat = $this->inbound();

        $this->assertSame($first, (int) $this->service->resolveTriggeredFlow($chat, false, 'مرحبا')->id);
    }

    public function test_the_oldest_keyword_flow_wins(): void
    {
        $first = $this->flow('keywords', 'عرض');
        $this->flow('keywords', 'عرض');

        $this->inbound('السلام عليكم');
        $second = $this->inbound('عرض');

        $this->assertSame($first, (int) $this->service->resolveTriggeredFlow($second, false, 'عرض')->id);
    }

    public function test_the_oldest_new_contact_flow_wins(): void
    {
        $first = $this->flow('new_contact');
        $this->flow('new_contact');

        $chat = $this->inbound();

        $this->assertSame($first, (int) $this->service->resolveTriggeredFlow($chat, true, 'مرحبا')->id);
    }
}
