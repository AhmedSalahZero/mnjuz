<?php

namespace Tests\Feature\Automation;

use App\Models\Chat;
use App\Models\Team;
use App\Models\User;
use App\Services\Chat\ChatReadService;
use App\Support\OrganizationRole;
use Tests\Feature\FlowBuilder\FlowBuilderTestCase;

/**
 * هل يُخرج الردّ الآلي المحادثة من «غير مقروء»؟
 *
 * افتراضياً نعم، كما كان دائماً: ردّ البوت يعلّم رسائل العميل مقروءة. وتاجرٌ
 * يعمل موظفوه من تبويب «غير مقروء» اشتكى أن رسالته التلقائية تُخفي عنهم كل
 * عميل يراسلهم — فله أن يُطفئ «تعليم المحادثة كمقروءة بعد الرد الآلي» من
 * إعدادات التذاكر، فتبقى المحادثة غير مقروءة حتى يفتحها موظف.
 */
class AutoReplyReadStateTest extends FlowBuilderTestCase
{
    // ───────────────────── الافتراضي: السلوك السابق ─────────────────────

    /** الردّ الآلي يعلّم الرسائل الواردة كلها مقروءة، كما كان قبل الخيار. */
    public function test_by_default_an_automated_reply_marks_the_conversation_read(): void
    {
        $this->basicReply('مرحبا', 'أهلاً');

        $this->receive('عندي مشكلة في الطلب');
        $this->receive('مرحبا');

        $this->assertSame(['أهلاً'], $this->sentBodies());
        $this->assertSame(0, $this->unreadCount());
    }

    /** ورسالة لم يُجبها شيء تبقى غير مقروءة. */
    public function test_by_default_an_unanswered_message_stays_unread(): void
    {
        $this->basicReply('مرحبا', 'أهلاً');

        $this->receive('عندي مشكلة في الطلب');

        $this->assertSame([], $this->sentBodies());
        $this->assertSame(1, $this->unreadCount());
    }

    // ───────────────────── الخيار مُطفأ: تبقى غير مقروءة ─────────────────────

    public function test_with_the_option_off_a_keyword_reply_is_sent_and_the_conversation_stays_unread(): void
    {
        $this->setOption(false);
        $this->basicReply('مرحبا', 'أهلاً بك، سيتواصل معك أحد الموظفين');

        $message = $this->receive('مرحبا');

        $this->assertSame(['أهلاً بك، سيتواصل معك أحد الموظفين'], $this->sentBodies());
        $this->assertSame(0, (int) $message->fresh()->is_read);
    }

    /** حالة التاجر الذي اشتكى: ترحيب يصل كل من يراسلهم. */
    public function test_with_the_option_off_a_welcome_flow_leaves_the_conversation_unread(): void
    {
        $this->setOption(false);
        $this->makeFlow([$this->startNode('first_message', null), $this->textNode('2', 'أهلاً بك في متجرنا')], [['1', '2']]);

        $message = $this->receive('السلام عليكم');

        $this->assertSame(['أهلاً بك في متجرنا'], $this->sentBodies());
        $this->assertSame(0, (int) $message->fresh()->is_read);
    }

    /** ولا تُمحى معه رسائل سابقة لم يُجبها أحد. */
    public function test_with_the_option_off_earlier_unanswered_messages_stay_unread_too(): void
    {
        $this->setOption(false);
        $this->basicReply('مرحبا', 'أهلاً');

        $this->receive('عندي مشكلة في الطلب');
        $this->receive('مرحبا');

        $this->assertSame(['أهلاً'], $this->sentBodies());
        $this->assertSame(2, $this->unreadCount());
    }

    /** ما يراه الموظف: المحادثة في تبويب «غير مقروء» بعدّادها. */
    public function test_with_the_option_off_the_conversation_appears_under_the_unread_filter(): void
    {
        $this->setOption(false);
        $this->basicReply('مرحبا', 'أهلاً');
        $this->receive('مرحبا');

        $rows = $this->asOwner()->get('/chats?is_read=0')->assertOk()
            ->viewData('page')['props']['rows']['data'];

        $row = collect($rows)->firstWhere('uuid', $this->contact->uuid);
        $this->assertNotNull($row, 'المحادثة غابت عن «غير مقروء» بعد الردّ الآلي.');
        $this->assertSame(1, (int) $row['unread_messages']);
    }

    // ───────────────────── صفحة إعدادات التذاكر ─────────────────────

    public function test_the_settings_page_shows_the_option_on_by_default(): void
    {
        $this->assertTrue($this->ticketSettingsProps()['markReadAfterAutoReply']);
    }

    /** صاحب المنشأة يُطفئه من الصفحة، فيسري فعلاً على الردّ الآلي. */
    public function test_turning_the_option_off_from_the_settings_page_takes_effect(): void
    {
        $this->asOwner()
            ->postJson('/settings/tickets', $this->ticketForm(['mark_read_after_auto_reply' => false]))
            ->assertOk();

        $this->assertFalse($this->ticketSettingsProps()['markReadAfterAutoReply']);

        $this->basicReply('مرحبا', 'أهلاً');
        $this->receive('مرحبا');
        $this->assertSame(1, $this->unreadCount());
    }

    public function test_turning_the_option_on_again_is_saved(): void
    {
        $this->setOption(false);

        $this->asOwner()
            ->postJson('/settings/tickets', $this->ticketForm(['mark_read_after_auto_reply' => true]))
            ->assertOk();

        $this->assertTrue($this->savedOption());
        $this->assertSame(true, $this->metadata()['automation']['mark_read_after_auto_reply']);
    }

    /** صفحة فُتحت قبل إضافة الخيار لا ترسله، فلا تُعيده للافتراضي. */
    public function test_a_page_opened_before_the_option_existed_keeps_it(): void
    {
        $this->setOption(false);

        $this->asOwner()->postJson('/settings/tickets', $this->ticketForm())->assertOk();

        $this->assertFalse($this->savedOption());
    }

    /** حفظ ترتيب الأتمتة يكتب مفتاحه وحده ولا يمسّ الخيار. */
    public function test_saving_the_automation_order_keeps_the_option(): void
    {
        $this->setOption(false);
        $sequence = ['Automated Flows', 'Basic Replies', 'AI Reply Assistant'];

        $this->asOwner()->postJson('/settings/automation', ['response_sequence' => $sequence])->assertOk();

        $this->assertFalse($this->savedOption());
        $this->assertSame($sequence, $this->metadata()['automation']['response_sequence']);
    }

    /** الموظف (agent) لا يصل إلى إعدادات التذاكر أصلاً (RestrictOrganizationAgent). */
    public function test_an_agent_cannot_change_the_option(): void
    {
        $agent = User::factory()->create(['role' => 'user']);
        Team::factory()->create([
            'user_id' => $agent->id,
            'organization_id' => $this->organization->id,
            'role' => OrganizationRole::AGENT,
            'created_by' => $this->owner->id,
        ]);

        $this->actingAs($agent, 'user')
            ->withSession(['current_organization' => $this->organization->id])
            ->postJson('/settings/tickets', $this->ticketForm(['mark_read_after_auto_reply' => false]))
            ->assertForbidden();

        $this->assertArrayNotHasKey('automation', $this->metadata(), 'كُتب شيء رغم المنع.');
        $this->assertTrue($this->savedOption());
    }

    // ───────────────────── مساعدات ─────────────────────

    private function setOption(bool $on): void
    {
        $metadata = $this->metadata();
        $metadata['automation']['mark_read_after_auto_reply'] = $on;
        $this->organization->update(['metadata' => json_encode($metadata)]);
    }

    /** القيمة كما يقرؤها الردّ الآلي فعلاً. */
    private function savedOption(): bool
    {
        return ChatReadService::autoReplyMarksRead($this->metadata());
    }

    /** @return array<string, mixed> */
    private function metadata(): array
    {
        return json_decode((string) $this->organization->fresh()->metadata, true) ?: [];
    }

    private function unreadCount(): int
    {
        return Chat::where('contact_id', $this->contact->id)
            ->where('type', 'inbound')
            ->where('is_read', 0)
            ->count();
    }

    /** نموذج الصفحة كما ترسله Ticket.vue — بلا الخيار، كصفحة فُتحت قبل إضافته. */
    private function ticketForm(array $overrides = []): array
    {
        return array_merge([
            'active' => false,
            'assignment_mode' => 'manual',
            'auto_assignment' => false,
            'reassign_reopened_chats' => false,
            'allow_agents_to_view_all_chats' => false,
            'encrypt_contacts_for_agents' => false,
            'rating_survey_active' => false,
            'rating_survey_message' => '',
            'rating_survey_button_label' => '',
            'rating_survey_cooldown_days' => 30,
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function ticketSettingsProps(): array
    {
        return $this->asOwner()->get('/settings/tickets')->assertOk()->viewData('page')['props'];
    }

    private function asOwner(): static
    {
        return $this->actingAs($this->owner, 'user')
            ->withSession(['current_organization' => $this->organization->id]);
    }
}
