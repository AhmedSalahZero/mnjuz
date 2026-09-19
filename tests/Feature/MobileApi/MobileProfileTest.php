<?php

namespace Tests\Feature\MobileApi;

use App\Models\Addon;

use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * الملف الشخصي من التطبيق.
 */
class MobileProfileTest extends MobileApiTestCase
{
    public function test_it_returns_the_current_profile(): void
    {
        $this->getJson('/api/v1/profile')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $this->owner->id)
            ->assertJsonPath('data.email', $this->owner->email)
            // صلاحية المنشأة لا صلاحية المنصّة
            ->assertJsonPath('data.role', 'owner')
            ->assertJsonPath('data.organization.id', $this->organization->id);
    }

    public function test_it_updates_the_profile(): void
    {
        $this->putJson('/api/v1/profile', [
            'first_name' => 'أحمد',
            'last_name' => 'صلاح',
            'email' => 'new@example.com',
            'phone' => '+966500000009',
            'language' => 'ar',
        ])->assertOk()->assertJsonPath('data.first_name', 'أحمد');

        $this->owner->refresh();

        $this->assertSame('أحمد', $this->owner->first_name);
        $this->assertSame('new@example.com', $this->owner->email);
        $this->assertSame('ar', $this->owner->language);
    }

    // ------------------------------------------------- عمليات التحقق

    /**
     * تبويب «عمليات التحقق» في نافذة الملف الشخصي بالويب.
     *
     * إعدادٌ واحد: هل يُطلب من المستخدم تحقّق واحد قبل دخول لوحة التحكّم؟
     * لم يكن في الـ API لا قراءةً ولا كتابة — ويُخلط بينه وبين
     * `/verification/send` و`/verification/confirm`، وتلك تُنفّذ عملية
     * التحقّق نفسها لا تُبدّل هذا الإعداد.
     */
    public function test_the_profile_carries_the_verification_setting(): void
    {
        $this->owner->forceFill(['verification_enabled' => true, 'is_verified' => true])->save();

        $this->getJson('/api/v1/profile')
            ->assertOk()
            ->assertJsonPath('data.verification_enabled', true)
            ->assertJsonPath('data.is_verified', true);
    }

    /** ومعه راية تقول للتطبيق متى يُظهر الخيار أصلاً. */
    public function test_the_profile_says_whether_the_option_is_available(): void
    {
        $this->getJson('/api/v1/profile')
            ->assertOk()
            ->assertJsonPath('data.verification_available', true);

        Addon::where('name', 'Google Authenticator')->update(['is_active' => 0]);

        $this->getJson('/api/v1/profile')
            ->assertOk()
            ->assertJsonPath('data.verification_available', false);
    }

    public function test_it_turns_the_verification_on_and_off(): void
    {
        $this->putJson('/api/v1/profile', $this->profilePayload(['verification_enabled' => true]))
            ->assertOk()
            ->assertJsonPath('data.verification_enabled', true);

        $this->assertTrue((bool) $this->owner->fresh()->verification_enabled);

        $this->putJson('/api/v1/profile', $this->profilePayload(['verification_enabled' => false]))
            ->assertOk()
            ->assertJsonPath('data.verification_enabled', false);

        $this->assertFalse((bool) $this->owner->fresh()->verification_enabled);
    }

    /** حفظ الاسم وحده لا يُطفئ التحقّق: الحقل يسكن تبويباً آخر في الويب. */
    public function test_saving_the_profile_without_it_leaves_it_alone(): void
    {
        Addon::where('name', 'Google Authenticator')->update(['is_active' => 1]);
        $this->owner->forceFill(['verification_enabled' => true])->save();

        $this->putJson('/api/v1/profile', $this->profilePayload())->assertOk();

        $this->assertTrue((bool) $this->owner->fresh()->verification_enabled);
    }

    /** والإضافة مطفأة ⇒ لا يُحفظ إعداد لا أثر له. */
    public function test_it_is_ignored_when_the_addon_is_off(): void
    {
        Addon::where('name', 'Google Authenticator')->update(['is_active' => 0]);

        $this->putJson('/api/v1/profile', $this->profilePayload(['verification_enabled' => true]))->assertOk();

        $this->assertFalse((bool) $this->owner->fresh()->verification_enabled);
    }

    public function test_a_non_boolean_value_is_rejected(): void
    {
        $this->putJson('/api/v1/profile', $this->profilePayload(['verification_enabled' => 'ربما']))
            ->assertStatus(400);
    }

    /** @param array<string, mixed> $extra */
    private function profilePayload(array $extra = []): array
    {
        return array_merge([
            'first_name' => $this->owner->first_name,
            'last_name' => $this->owner->last_name,
            'email' => $this->owner->email,
        ], $extra);
    }

    public function test_a_missing_name_is_rejected(): void
    {
        $response = $this->putJson('/api/v1/profile', ['email' => 'x@example.com']);

        $response->assertStatus(400)->assertJsonPath('success', false);
        $this->assertArrayHasKey('first_name', $response->json('errors'));
    }

    /** بريد عضو آخر مرفوض — وإلّا انتحل أحدهم حساب زميله. */
    public function test_an_email_taken_by_another_user_is_rejected(): void
    {
        $other = User::factory()->create(['email' => 'taken@example.com']);

        $response = $this->putJson('/api/v1/profile', [
            'first_name' => 'أحمد',
            'last_name' => 'صلاح',
            'email' => $other->email,
        ]);

        $response->assertStatus(400);
        $this->assertArrayHasKey('email', $response->json('errors'));
    }

    /** وبريده هو مقبول: التعديل لا يشترط تغييره. */
    public function test_keeping_the_same_email_is_allowed(): void
    {
        $this->putJson('/api/v1/profile', [
            'first_name' => 'أحمد',
            'last_name' => 'صلاح',
            'email' => $this->owner->email,
        ])->assertOk();
    }

    // ------------------------------------------------- كلمة المرور

    public function test_it_changes_the_password(): void
    {
        $this->owner->forceFill(['password' => Hash::make('old-secret')])->save();

        $this->putJson('/api/v1/profile/password', [
            'old_password' => 'old-secret',
            'password' => 'new-secret',
            'password_confirmation' => 'new-secret',
        ])->assertOk()->assertJsonPath('success', true);

        $this->assertTrue(Hash::check('new-secret', $this->owner->fresh()->password));
    }

    /** القديمة تُفحص: بلا ذلك يكفي جهاز مفتوح لسرقة الحساب. */
    public function test_a_wrong_old_password_is_rejected(): void
    {
        $this->owner->forceFill(['password' => Hash::make('old-secret')])->save();

        $response = $this->putJson('/api/v1/profile/password', [
            'old_password' => 'not-the-old-one',
            'password' => 'new-secret',
            'password_confirmation' => 'new-secret',
        ]);

        $response->assertStatus(400);
        $this->assertArrayHasKey('old_password', $response->json('errors'));
        $this->assertTrue(Hash::check('old-secret', $this->owner->fresh()->password));
    }

    public function test_an_unconfirmed_password_is_rejected(): void
    {
        $this->owner->forceFill(['password' => Hash::make('old-secret')])->save();

        $this->putJson('/api/v1/profile/password', [
            'old_password' => 'old-secret',
            'password' => 'new-secret',
            'password_confirmation' => 'different',
        ])->assertStatus(400);
    }

    public function test_it_requires_authentication(): void
    {
        app('auth')->forgetGuards();

        $this->getJson('/api/v1/profile')->assertStatus(401);
    }
}
