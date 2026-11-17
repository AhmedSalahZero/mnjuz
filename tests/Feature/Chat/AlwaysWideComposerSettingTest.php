<?php

namespace Tests\Feature\Chat;

use App\Models\Addon;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\SubscriptionPlan;
use App\Models\Team;
use App\Models\User;
use App\Support\OrganizationRole;
use Tests\Feature\Cycle\CycleTestCase;

/**
 * خيار «إظهار صندوق الكتابة الكبير دائماً» في إعدادات التذاكر.
 *
 * الافتراضي هو السلوك القديم: صندوق صغير بلا زرّ اقتراح الرد بالـ AI وكبير
 * معه (simpleForm). المنشأة التي تريد الكبير دائماً تفعّل الخيار، فيصل إلى
 * صفحة المحادثات في alwaysWideComposer، ولا يغيّر simpleForm الذي يقرّر
 * ظهور زرّ المساعد. شكل الصندوق نفسه مُختبَر في tests/js/chat-composer-layout.mjs.
 */
class AlwaysWideComposerSettingTest extends CycleTestCase
{
    // ───────────────────── الافتراضي ─────────────────────

    public function test_by_default_the_option_is_off_and_the_composer_stays_as_it_was(): void
    {
        $props = $this->chatPageProps();

        $this->assertFalse($props['alwaysWideComposer']);
        $this->assertTrue($props['simpleForm'], 'بلا AI يبقى الصندوق الصغير افتراضياً.');
        $this->assertFalse($this->ticketSettingsProps()['alwaysWideComposer']);
    }

    // ───────────────────── التفعيل من صفحة إعدادات التذاكر ─────────────────────

    public function test_turning_the_option_on_from_the_ticket_settings_reaches_the_chat_page(): void
    {
        $this->asOwner()
            ->postJson('/settings/tickets', $this->ticketForm(['always_wide_composer' => true]))
            ->assertOk();

        $this->assertSame(true, $this->metadata()['chat']['always_wide_composer']);
        $this->assertTrue($this->ticketSettingsProps()['alwaysWideComposer']);

        $props = $this->chatPageProps();
        $this->assertTrue($props['alwaysWideComposer']);
        // زرّ اقتراح الرد بالـ AI لا يتبع الخيار: ما زال غير مفعّل.
        $this->assertTrue($props['simpleForm']);
    }

    public function test_turning_the_option_off_again_is_saved(): void
    {
        $this->setOption(true);

        $this->asOwner()
            ->postJson('/settings/tickets', $this->ticketForm(['always_wide_composer' => false]))
            ->assertOk();

        $this->assertSame(false, $this->metadata()['chat']['always_wide_composer']);
        $this->assertFalse($this->chatPageProps()['alwaysWideComposer']);
    }

    /** صفحة فُتحت قبل إضافة الخيار لا ترسله، فلا تُعيده للافتراضي. */
    public function test_a_page_opened_before_the_option_existed_keeps_it(): void
    {
        $this->setOption(true);

        $this->asOwner()->postJson('/settings/tickets', $this->ticketForm())->assertOk();

        $this->assertTrue($this->organization->fresh()->getAlwaysWideChatComposer());
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
            ->postJson('/settings/tickets', $this->ticketForm(['always_wide_composer' => true]))
            ->assertForbidden();

        $this->assertArrayNotHasKey('chat', $this->metadata(), 'كُتب شيء رغم المنع.');
        $this->assertFalse($this->organization->fresh()->getAlwaysWideChatComposer());
    }

    // ───────────────────── مع الـ AI ─────────────────────

    /** زرّ اقتراح الرد مفعّل: الصندوق كبير كما كان، والخيار لا يغيّر simpleForm. */
    public function test_with_the_ai_button_on_simple_form_is_unchanged_by_the_option(): void
    {
        $this->enableAiChatForm();

        $this->assertFalse($this->chatPageProps()['simpleForm']);
        $this->assertFalse($this->chatPageProps()['alwaysWideComposer']);

        $this->setOption(true);

        $props = $this->chatPageProps();
        $this->assertFalse($props['simpleForm']);
        $this->assertTrue($props['alwaysWideComposer']);
    }

    // ───────────────────── قراءة القيمة المحفوظة ─────────────────────

    public function test_the_getter_is_off_for_missing_or_malformed_metadata(): void
    {
        foreach ([
            'null metadata' => null,
            'empty string' => '',
            'invalid json' => '{not json',
            'json scalar' => '"text"',
            'no chat key' => json_encode(['tickets' => ['active' => true]]),
            'chat is not an object' => json_encode(['chat' => 'yes']),
            'truthy but not true' => json_encode(['chat' => ['always_wide_composer' => 1]]),
        ] as $case => $metadata) {
            $organization = new Organization(['metadata' => $metadata]);
            $this->assertFalse($organization->getAlwaysWideChatComposer(), $case);
        }

        $organization = new Organization(['metadata' => json_encode(['chat' => ['always_wide_composer' => true]])]);
        $this->assertTrue($organization->getAlwaysWideChatComposer());
    }

    // ───────────────────── مساعدات ─────────────────────

    private function setOption(bool $on): void
    {
        $metadata = $this->metadata();
        $metadata['chat']['always_wide_composer'] = $on;
        $this->organization->update(['metadata' => json_encode($metadata)]);
    }

    /** الإضافة مفعّلة والباقة تشملها وزرّ اقتراح الرد مفعّل في إعدادات المساعد. */
    private function enableAiChatForm(): void
    {
        Addon::factory()->create(['name' => 'AI Assistant', 'status' => 1, 'is_active' => 1]);
        SubscriptionPlan::firstOrFail()->update([
            'metadata' => json_encode(['message_limit' => -1, 'addons' => ['AI Assistant' => true]]),
        ]);

        $metadata = $this->metadata();
        $metadata['ai'] = ['active' => true, 'ai_chat_form_active' => true];
        $this->organization->update(['metadata' => json_encode($metadata)]);
    }

    /** @return array<string, mixed> */
    private function metadata(): array
    {
        return json_decode((string) $this->organization->fresh()->metadata, true) ?: [];
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

    /** التحميل الكامل لصفحة المحادثات على محادثة مفتوحة. */
    private function chatPageProps(): array
    {
        $contact = Contact::where('organization_id', $this->organization->id)->first() ?? $this->contact();

        return $this->asOwner()->get('/chats/' . $contact->uuid)->assertOk()->viewData('page')['props'];
    }

    private function asOwner(): static
    {
        return $this->actingAs($this->owner, 'user')
            ->withSession(['current_organization' => $this->organization->id]);
    }
}
