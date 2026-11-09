<?php

namespace Tests\Feature\MobileApi;

use App\Mail\CustomEmail;
use App\Models\EmailTemplate;
use App\Models\Organization;
use App\Models\Setting;
use App\Models\Team;
use App\Models\TeamInvite;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

/**
 * إدارة الفريق من تطبيق الجوال: للمالك والمدير، بحدّ الباقة، وبنطاق المنشأة.
 */
class MobileTeamTest extends MobileApiTestCase
{
    protected function planMetadata(): array
    {
        return parent::planMetadata() + ['team_limit' => 3];
    }

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Setting::create(['key' => 'smtp_email_active', 'value' => 1]);
        EmailTemplate::create([
            'name' => 'Invite',
            'subject' => 'Join {{CompanyName}}',
            'body' => 'Hi, {{FirstName}} invited you: {{Link}}',
            'updated_by' => $this->owner->id,
        ]);
    }

    private function teamOf(User $user): Team
    {
        return Team::where('user_id', $user->id)->where('organization_id', $this->organization->id)->withTrashed()->firstOrFail();
    }

    // ------------------------------------------------------------ list

    public function test_the_owner_sees_members_limits_and_pending_invites(): void
    {
        $manager = $this->member('manager');
        $removed = $this->member('agent');
        $this->teamOf($removed)->delete();
        TeamInvite::create([
            'organization_id' => $this->organization->id, 'email' => 'new@example.com', 'code' => 'c1',
            'role' => 'agent', 'invited_by' => $this->owner->id, 'expire_at' => now()->addDay(),
        ]);

        $response = $this->getJson('/api/v1/team')->assertOk();

        $response->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.0.role', 'owner')
            ->assertJsonPath('data.items.0.is_owner', true)
            ->assertJsonPath('data.items.0.is_me', true)
            ->assertJsonPath('data.items.0.can_delete', false)
            ->assertJsonPath('data.items.1.user.email', $manager->email)
            ->assertJsonPath('data.items.1.can_edit', true)
            ->assertJsonPath('data.limits', ['team_limit' => 3, 'used' => 2, 'remaining' => 1])
            ->assertJsonPath('data.roles', ['manager', 'agent'])
            ->assertJsonPath('data.pending_invites.0.email', 'new@example.com')
            ->assertJsonPath('data.pending_invites.0.expired', false)
            ->assertJsonMissingPath('data.items.0.user.password');

        $this->getJson('/api/v1/team?trashed=1')
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.deleted', true)
            ->assertJsonPath('data.items.0.can_restore', true);

        $this->getJson('/api/v1/team?search=' . urlencode($manager->email))->assertJsonCount(1, 'data.items');
    }

    public function test_agents_cannot_manage_the_team(): void
    {
        $member = $this->member('manager');
        $this->actAs($this->member('agent'));

        $this->getJson('/api/v1/team')->assertForbidden();
        $this->postJson('/api/v1/team/invite', ['email' => 'x@example.com', 'role' => 'agent'])->assertForbidden();
        $this->putJson('/api/v1/team/' . $this->teamOf($member)->uuid, ['role' => 'agent'])->assertForbidden();
        $this->deleteJson('/api/v1/team/' . $this->teamOf($member)->uuid)->assertForbidden();
        $this->assertSame(0, TeamInvite::count());
    }

    public function test_managers_can_manage_the_team(): void
    {
        $this->actAs($this->member('manager'));

        $this->getJson('/api/v1/team')->assertOk();
        $this->postJson('/api/v1/team/invite', ['email' => 'x@example.com', 'role' => 'agent'])->assertOk();
    }

    // ------------------------------------------------------------ invite

    public function test_inviting_creates_an_invite_and_emails_the_link(): void
    {
        $this->postJson('/api/v1/team/invite', ['email' => '  New.Agent@Example.com ', 'role' => 'agent'])
            ->assertOk()
            ->assertJsonPath('data.invite.email', 'new.agent@example.com')
            ->assertJsonPath('data.invite.role', 'agent')
            ->assertJsonPath('data.email_sent', true)
            ->assertJsonMissingPath('data.invite.code');

        $invite = TeamInvite::firstOrFail();
        $this->assertSame($this->organization->id, (int) $invite->organization_id);
        $this->assertSame($this->owner->id, (int) $invite->invited_by);

        Mail::assertQueued(CustomEmail::class, fn ($mail) => $mail->hasTo('new.agent@example.com'));
        $this->assertDatabaseHas('activity_logs', ['event' => 'team_member_invited', 'organization_id' => $this->organization->id]);
    }

    public function test_invite_validation(): void
    {
        $existing = $this->member('agent');

        $this->postJson('/api/v1/team/invite', ['email' => 'not-an-email', 'role' => 'owner'])
            ->assertStatus(400)
            ->assertJsonValidationErrors(['email', 'role'], 'errors');

        $this->postJson('/api/v1/team/invite', ['email' => $existing->email, 'role' => 'agent'])
            ->assertStatus(400)
            ->assertJsonPath('errors.email.0', __('User with this email already exists in your team.'));
    }

    public function test_the_team_limit_is_enforced_without_a_session(): void
    {
        $this->member('agent');
        $this->postJson('/api/v1/team/invite', ['email' => 'third@example.com', 'role' => 'agent'])->assertOk();

        $this->member('agent');
        $this->postJson('/api/v1/team/invite', ['email' => 'fourth@example.com', 'role' => 'agent'])
            ->assertStatus(400)
            ->assertJsonValidationErrors(['email'], 'errors');
    }

    public function test_an_invite_from_another_organization_is_not_reused(): void
    {
        // كان البحث بالبريد وحده: دعوة منشأة أخرى لنفس البريد تُعاد إليه
        // برابطها هي، فينضمّ إلى غير من دعاه.
        $other = Organization::factory()->create(['created_by' => $this->owner->id]);
        TeamInvite::create([
            'organization_id' => $other->id, 'email' => 'shared@example.com', 'code' => 'other-org-code',
            'role' => 'manager', 'invited_by' => $this->owner->id, 'expire_at' => now()->addDay(),
        ]);

        $this->postJson('/api/v1/team/invite', ['email' => 'shared@example.com', 'role' => 'agent'])->assertOk();

        $mine = TeamInvite::where('organization_id', $this->organization->id)->firstOrFail();
        $this->assertNotSame('other-org-code', $mine->code);
        $this->assertSame('agent', $mine->role);
        $this->assertSame('other-org-code', TeamInvite::where('organization_id', $other->id)->value('code'));
        Mail::assertQueued(CustomEmail::class, fn ($mail) => str_contains((string) $mail->body, '/invite/' . $mine->code));
    }

    public function test_reinviting_updates_the_role_and_renews_an_expired_code(): void
    {
        $this->postJson('/api/v1/team/invite', ['email' => 'again@example.com', 'role' => 'agent'])->assertOk();
        $invite = TeamInvite::firstOrFail();
        $invite->update(['expire_at' => now()->subHour()]);

        $this->postJson('/api/v1/team/invites/' . $invite->id . '/resend')->assertOk()->assertJsonPath('data.invite.expired', false);
        $this->assertNotSame($invite->code, $invite->fresh()->code);

        $this->postJson('/api/v1/team/invite', ['email' => 'again@example.com', 'role' => 'manager'])->assertOk();
        $this->assertSame(1, TeamInvite::count());
        $this->assertSame('manager', TeamInvite::first()->role);
    }

    public function test_an_invite_can_be_cancelled_only_in_its_organization(): void
    {
        $other = Organization::factory()->create(['created_by' => $this->owner->id]);
        $foreign = TeamInvite::create([
            'organization_id' => $other->id, 'email' => 'f@example.com', 'code' => 'f',
            'role' => 'agent', 'invited_by' => $this->owner->id, 'expire_at' => now()->addDay(),
        ]);
        $this->postJson('/api/v1/team/invite', ['email' => 'mine@example.com', 'role' => 'agent'])->assertOk();
        $mine = TeamInvite::where('organization_id', $this->organization->id)->firstOrFail();

        $this->deleteJson('/api/v1/team/invites/' . $foreign->id)->assertNotFound();
        $this->postJson('/api/v1/team/invites/' . $foreign->id . '/resend')->assertNotFound();
        $this->deleteJson('/api/v1/team/invites/' . $mine->id)->assertOk();

        $this->assertNull($mine->fresh());
        $this->assertNotNull($foreign->fresh());
    }

    public function test_inviting_works_without_smtp_and_says_so(): void
    {
        Setting::where('key', 'smtp_email_active')->update(['value' => 0]);

        $this->postJson('/api/v1/team/invite', ['email' => 'quiet@example.com', 'role' => 'agent'])
            ->assertOk()
            ->assertJsonPath('data.email_sent', false);

        Mail::assertNothingQueued();
        $this->assertSame(1, TeamInvite::count());
    }

    public function test_inviting_does_not_crash_when_the_smtp_setting_is_missing(): void
    {
        Setting::where('key', 'smtp_email_active')->delete();

        $this->postJson('/api/v1/team/invite', ['email' => 'nosetting@example.com', 'role' => 'agent'])
            ->assertOk()
            ->assertJsonPath('data.email_sent', false);
    }

    // ------------------------------------------------------------ update / delete / restore

    public function test_changing_a_members_role(): void
    {
        $agent = $this->member('agent');
        $team = $this->teamOf($agent);

        $this->putJson('/api/v1/team/' . $team->uuid, ['role' => 'manager'])
            ->assertOk()
            ->assertJsonPath('data.role', 'manager');
        $this->assertSame('manager', $team->fresh()->role);

        $this->putJson('/api/v1/team/' . $team->uuid, ['role' => 'owner'])->assertStatus(400);
        $this->putJson('/api/v1/team/' . $this->teamOf($this->owner)->uuid, ['role' => 'agent'])->assertStatus(422);
        $this->assertSame('owner', $this->teamOf($this->owner)->role);
    }

    public function test_a_manager_cannot_demote_themselves(): void
    {
        $manager = $this->member('manager');
        $this->actAs($manager);

        $this->putJson('/api/v1/team/' . $this->teamOf($manager)->uuid, ['role' => 'agent'])->assertStatus(422);
        $this->deleteJson('/api/v1/team/' . $this->teamOf($manager)->uuid)->assertStatus(422);
        $this->assertSame('manager', $this->teamOf($manager)->role);
    }

    public function test_removing_and_restoring_a_member(): void
    {
        $agent = $this->member('agent');
        $team = $this->teamOf($agent);

        $this->deleteJson('/api/v1/team/' . $team->uuid)->assertOk();
        $this->assertSoftDeleted('teams', ['id' => $team->id]);
        $this->assertSame($this->owner->id, (int) $team->fresh()->deleted_by);
        $this->assertDatabaseHas('activity_logs', ['event' => 'team_member_removed']);

        $this->postJson('/api/v1/team/' . $team->uuid . '/restore')
            ->assertOk()
            ->assertJsonPath('data.deleted', false);
        $this->assertNull($team->fresh()->deleted_at);

        $this->postJson('/api/v1/team/' . $team->uuid . '/restore')->assertStatus(422);
        $this->deleteJson('/api/v1/team/' . $this->teamOf($this->owner)->uuid)->assertStatus(422);
    }

    public function test_restoring_respects_the_team_limit(): void
    {
        $removed = $this->member('agent');
        $this->teamOf($removed)->delete();
        $this->member('agent');
        $this->member('agent'); // 3 من 3

        $this->postJson('/api/v1/team/' . $this->teamOf($removed)->uuid . '/restore')->assertStatus(422);
        $this->assertNotNull($this->teamOf($removed)->deleted_at);
    }

    public function test_members_of_another_organization_are_not_found(): void
    {
        $other = Organization::factory()->create(['created_by' => $this->owner->id]);
        $outsider = User::factory()->create(['role' => 'user']);
        $foreign = Team::factory()->create(['user_id' => $outsider->id, 'organization_id' => $other->id, 'role' => 'agent', 'created_by' => $this->owner->id]);

        $this->putJson('/api/v1/team/' . $foreign->uuid, ['role' => 'manager'])->assertNotFound();
        $this->deleteJson('/api/v1/team/' . $foreign->uuid)->assertNotFound();
        $this->postJson('/api/v1/team/' . $foreign->uuid . '/restore')->assertNotFound();
        $this->assertSame('agent', $foreign->fresh()->role);
        $this->assertNull($foreign->fresh()->deleted_at);
    }
}
