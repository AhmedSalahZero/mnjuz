<?php

namespace Tests\Feature\FlowBuilder;

use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * خطوة «تحويل إلى موظف» في المحرّر: تظهر في اللوحة، وتختار من أعضاء الفريق
 * النشطين بالمعرّف والاسم وحدهما، وتنبّه حين تكون التذاكر مطفأة.
 */
class FlowTransferEditorTest extends FlowBuilderTestCase
{
    private function asOwner(): static
    {
        return $this->actingAs($this->owner, 'user')
            ->withSession(['current_organization' => $this->organization->id]);
    }

    private function member(string $first, string $status = 'active', ?Organization $organization = null): User
    {
        $user = User::factory()->create(['role' => 'user', 'first_name' => $first, 'last_name' => 'س']);
        Team::factory()->create([
            'user_id' => $user->id,
            'organization_id' => ($organization ?? $this->organization)->id,
            'role' => 'agent',
            'status' => $status,
            'created_by' => $this->owner->id,
        ]);

        return $user;
    }

    public function test_the_palette_offers_the_transfer_action(): void
    {
        $flow = $this->makeFlow([$this->startNode()], []);

        $this->asOwner()->get('/automation/flows/' . $flow->uuid)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('availableActions', fn ($actions) => collect($actions)->contains('assign_agent'))
            );
    }

    /**
     * الأعضاء النشطون في هذه المنشأة فقط، بمفتاحين لا غير: لا بريد ولا كلمة
     * مرور — نقطة /team تُرجع صفوف users كاملة، فلم تُستعمل هنا.
     */
    public function test_the_editor_lists_active_members_by_id_and_name_only(): void
    {
        $ahmed = $this->member('أحمد');
        $suspended = $this->member('معلّق', 'suspended');
        $removed = $this->member('محذوف');
        Team::where('user_id', $removed->id)->delete();
        $other = Organization::factory()->create(['created_by' => $this->owner->id]);
        $stranger = $this->member('غريب', 'active', $other);

        $flow = $this->makeFlow([$this->startNode()], []);
        $response = $this->asOwner()->get('/automation/flows/' . $flow->uuid)->assertOk();

        $members = collect($response->viewData('page')['props']['teamMembers']);
        $ids = $members->pluck('value')->all();

        $this->assertContains($ahmed->id, $ids);
        $this->assertContains($this->owner->id, $ids);
        $this->assertNotContains($suspended->id, $ids);
        $this->assertNotContains($removed->id, $ids);
        $this->assertNotContains($stranger->id, $ids);

        foreach ($members as $member) {
            $this->assertSame(['value', 'label'], array_keys($member));
        }

        $json = json_encode($members, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString($ahmed->email, $json);
        $this->assertStringNotContainsString('password', $json);
        $this->assertStringNotContainsString('remember_token', $json);
        $this->assertStringNotContainsString('tfa_secret', $json);
    }

    public function test_the_editor_says_when_ticketing_is_off(): void
    {
        $flow = $this->makeFlow([$this->startNode()], []);

        $this->asOwner()->get('/automation/flows/' . $flow->uuid)
            ->assertInertia(fn (Assert $page) => $page->where('ticketingActive', false));

        $this->organization->forceFill(['metadata' => json_encode(['tickets' => ['active' => true]])])->save();

        $this->asOwner()->get('/automation/flows/' . $flow->uuid)
            ->assertInertia(fn (Assert $page) => $page->where('ticketingActive', true));
    }

    public function test_an_agent_cannot_save_a_transfer_step(): void
    {
        $agent = $this->member('موظف');
        $flow = $this->makeFlow([$this->startNode('keywords', 'start'), $this->actionNode('2', 'assign_agent', ['user_ids' => [$agent->id]])], [['1', '2']]);

        $this->actingAs($agent, 'user')
            ->withSession(['current_organization' => $this->organization->id])
            ->putJson('/automation/flows/' . $flow->uuid, ['metadata' => $flow->metadata])
            ->assertForbidden();
    }
}
