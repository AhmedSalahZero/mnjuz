<?php

namespace Tests\Feature\MobileApi;

use App\Models\Addon;
use App\Models\Organization;
use App\Models\Referral;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\User;
use App\Services\ReferralService;
use App\Services\WazBusinessService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;

/**
 * «ادعُ صديقاً»: الرابط من التطبيق، والتسجيل به من الويب، والقائمة.
 */
class MobileReferralTest extends MobileApiTestCase
{
    private function friend(string $email = 'friend@example.com'): User
    {
        return User::factory()->create(['role' => 'user', 'email' => $email, 'first_name' => 'Omar', 'last_name' => 'Ali']);
    }

    private function refer(User $referrer, User $friend): void
    {
        DB::table('referrals')->insert([
            'referrer_id' => $referrer->id,
            'referred_user_id' => $friend->id,
            'code' => app(ReferralService::class)->codeFor($referrer),
            'created_at' => now(),
        ]);
    }

    /** منشأة يملكها المستخدم باشتراك. */
    private function ownedOrganization(User $user, string $status, ?int $planId, $validUntil): void
    {
        $organization = Organization::factory()->create(['created_by' => $user->id]);
        Team::factory()->create(['user_id' => $user->id, 'organization_id' => $organization->id, 'role' => 'owner', 'created_by' => $user->id]);
        Subscription::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $organization->id,
            'plan_id' => $planId,
            'status' => $status,
            'valid_until' => $validUntil,
        ]);
    }

    // ------------------------------------------------------------ the link

    public function test_the_link_is_created_once_and_stays_the_same(): void
    {
        $first = $this->getJson('/api/v1/referral')->assertOk();

        $code = $first->json('data.code');
        $this->assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{8}$/', $code, 'no look-alike characters (0/O, 1/I/L)');
        $first->assertJsonPath('data.link', url('/r/' . $code))
            ->assertJsonPath('data.stats', ['total' => 0, 'subscribed' => 0]);
        $this->assertStringContainsString(url('/r/' . $code), $first->json('data.share_text'));

        $this->getJson('/api/v1/referral')->assertJsonPath('data.code', $code);
        $this->assertSame($code, $this->owner->fresh()->referral_code);
    }

    public function test_every_role_can_invite_a_friend(): void
    {
        $agent = $this->member('agent');
        $this->actAs($agent);

        $code = $this->getJson('/api/v1/referral')->assertOk()->json('data.code');

        $this->assertNotSame($code, app(ReferralService::class)->codeFor($this->owner), 'the code is personal');
    }

    public function test_the_list_shows_who_joined_with_masked_emails(): void
    {
        $trial = $this->friend('trial.user@example.com');
        $paying = $this->friend('paying@example.com');
        $this->ownedOrganization($trial, 'trial', null, now()->addDays(10));
        $this->ownedOrganization($paying, 'active', $this->plan->id, now()->addMonth());
        $this->refer($this->owner, $trial);
        $this->refer($this->owner, $paying);

        // إحالة مستخدم آخر لا تظهر هنا.
        $someoneElse = $this->member('agent');
        $this->refer($someoneElse, $this->friend('other@example.com'));

        $this->getJson('/api/v1/referral')->assertJsonPath('data.stats', ['total' => 2, 'subscribed' => 1]);

        $items = $this->getJson('/api/v1/referral/referrals')->assertOk()
            ->assertJsonPath('data.pagination.total', 2)
            ->json('data.items');

        $this->assertSame(['subscribed', 'registered'], array_column($items, 'status'));
        $this->assertSame('Omar Ali', $items[0]['name']);
        $this->assertSame('p•••••@example.com', $items[0]['email']);
        $this->assertStringNotContainsString('paying', json_encode($items));
    }

    // ------------------------------------------------------------ web capture

    public function test_the_referral_link_remembers_the_code_and_opens_signup(): void
    {
        $code = app(ReferralService::class)->codeFor($this->owner);
        Auth::guard('sanctum')->forgetUser();

        $response = $this->get('/r/' . strtolower($code));

        $response->assertRedirect('/signup')
            ->assertSessionHas(ReferralService::SESSION_KEY, $code)
            ->assertCookie(ReferralService::COOKIE, $code);
    }

    public function test_an_unknown_code_still_opens_signup_without_remembering(): void
    {
        $this->get('/r/NOPE1234')
            ->assertRedirect('/signup')
            ->assertSessionMissing(ReferralService::SESSION_KEY);
    }

    public function test_signing_up_after_the_link_credits_the_referrer(): void
    {
        $code = app(ReferralService::class)->codeFor($this->owner);
        $this->fakeWaz();

        $this->withSession([ReferralService::SESSION_KEY => $code])
            ->post('/signup', $this->signupPayload('new.owner@example.com'))
            ->assertRedirect('/dashboard');

        $newUser = User::where('email', 'new.owner@example.com')->firstOrFail();
        $referral = Referral::where('referred_user_id', $newUser->id)->firstOrFail();
        $this->assertSame($this->owner->id, (int) $referral->referrer_id);
        $this->assertSame($code, $referral->code);
        $this->assertFalse(session()->has(ReferralService::SESSION_KEY), 'the code is used once');
    }

    public function test_the_cookie_credits_a_friend_who_comes_back_later(): void
    {
        $code = app(ReferralService::class)->codeFor($this->owner);
        $this->fakeWaz();

        $this->withCookie(ReferralService::COOKIE, $code)
            ->post('/signup', $this->signupPayload('later@example.com'))
            ->assertRedirect('/dashboard');

        $this->assertDatabaseHas('referrals', [
            'referrer_id' => $this->owner->id,
            'referred_user_id' => User::where('email', 'later@example.com')->value('id'),
        ]);
    }

    public function test_signup_query_parameter_works_like_the_link(): void
    {
        $code = app(ReferralService::class)->codeFor($this->owner);
        Addon::factory()->create(['name' => 'Google Recaptcha', 'is_active' => 0]);

        $this->get('/signup?ref=' . $code)->assertOk()->assertSessionHas(ReferralService::SESSION_KEY, $code);
    }

    public function test_signing_up_without_a_code_records_nothing(): void
    {
        $this->fakeWaz();

        $this->post('/signup', $this->signupPayload('plain@example.com'))->assertRedirect('/dashboard');

        $this->assertSame(0, Referral::count());
    }

    public function test_self_referral_and_second_referral_are_ignored(): void
    {
        $service = app(ReferralService::class);
        $code = $service->codeFor($this->owner);

        session([ReferralService::SESSION_KEY => $code]);
        $this->assertNull($service->recordSignup($this->owner));

        $friend = $this->friend();
        session([ReferralService::SESSION_KEY => $code]);
        $this->assertNotNull($service->recordSignup($friend));

        $other = $this->member('agent');
        session([ReferralService::SESSION_KEY => $service->codeFor($other)]);
        $service->recordSignup($friend);

        $this->assertSame(1, Referral::where('referred_user_id', $friend->id)->count());
        $this->assertSame($this->owner->id, (int) Referral::where('referred_user_id', $friend->id)->value('referrer_id'));
    }

    public function test_helpers(): void
    {
        $this->assertSame('ABCD1234', ReferralService::normalize(' abcd1234 '));
        $this->assertNull(ReferralService::normalize('../etc'));
        $this->assertNull(ReferralService::normalize(''));
        $this->assertSame('a••@x.com', ReferralService::maskEmail('ab@x.com'));
        $this->assertSame('o••••••@example.com', ReferralService::maskEmail('omar.ali.long@example.com'));
    }

    // ------------------------------------------------------------

    private function fakeWaz(): void
    {
        Addon::factory()->create(['name' => 'Google Recaptcha', 'is_active' => 0]);
        \App\Models\Setting::create(['key' => 'smtp_email_active', 'value' => 0]);
        Auth::guard('sanctum')->forgetUser();

        $waz = Mockery::mock(WazBusinessService::class);
        $waz->shouldReceive('createCompany')->andReturn(901);
        $waz->shouldReceive('createContact')->andReturn(902);
        $waz->shouldReceive('countryOptions')->andReturn([]);
        $waz->shouldReceive('deleteCompany')->andReturn(true);
        $this->app->instance(WazBusinessService::class, $waz);
    }

    private function signupPayload(string $email): array
    {
        return [
            'first_name' => 'Nora',
            'last_name' => 'Saleh',
            'email' => $email,
            'password' => 'Secret#12345',
            'password_confirmation' => 'Secret#12345',
            'organization_name' => 'Nora Store',
            'street' => 'King Fahd Rd',
            'city' => 'Riyadh',
            'state' => 'Riyadh',
            'zip' => '12345',
            'country' => (string) array_values(config('waz_countries'))[0],
        ];
    }
}
