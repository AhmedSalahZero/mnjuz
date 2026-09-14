<?php

namespace Tests\Feature\MobileApi;

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
