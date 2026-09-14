<?php

namespace Tests\Feature\MobileApi;

use App\Models\Organization;

/**
 * الإعدادات العامّة وأوقات العمل من التطبيق.
 *
 * كلّها تسكن عمود metadata، فأخطر ما يُختبر هنا ألّا تمحو الكتابة ما لم
 * تُرسله الشاشة.
 */
class MobileSettingTest extends MobileApiTestCase
{
    private function metadata(): array
    {
        return json_decode(Organization::find($this->organization->id)->metadata, true) ?: [];
    }

    private function setMetadata(array $metadata): void
    {
        Organization::where('id', $this->organization->id)
            ->update(['metadata' => json_encode($metadata)]);
    }

    // ------------------------------------------------- العامّة

    public function test_it_returns_the_general_settings(): void
    {
        $this->setMetadata([
            'timezone' => 'Asia/Riyadh',
            'notifications' => ['enable_sound' => true, 'tone' => 'bell', 'volume' => 0.5],
            'campaigns' => ['enable_resend' => true, 'resend_intervals' => [5, 10]],
            'support' => ['ticket_form_url' => 'https://example.com/form'],
        ]);

        $this->getJson('/api/v1/settings/general')
            ->assertOk()
            ->assertJsonPath('data.timezone', 'Asia/Riyadh')
            ->assertJsonPath('data.notifications.enable_sound', true)
            ->assertJsonPath('data.notifications.tone', 'bell')
            ->assertJsonPath('data.campaigns.resend_intervals', [5, 10])
            ->assertJsonPath('data.support.ticket_form_url', 'https://example.com/form')
            ->assertJsonPath('data.organization.id', $this->organization->id);
    }

    public function test_it_updates_the_general_settings(): void
    {
        $this->postJson('/api/v1/settings/general', [
            'timezone' => 'Asia/Dubai',
            'notifications' => ['enable_sound' => false, 'tone' => 'chime'],
        ])->assertOk()->assertJsonPath('data.timezone', 'Asia/Dubai');

        $metadata = $this->metadata();

        $this->assertSame('Asia/Dubai', $metadata['timezone']);
        $this->assertSame('chime', $metadata['notifications']['tone']);
    }

    /**
     * ما لم تُرسله الشاشة يبقى.
     *
     * شاشات الجوال تُرسل جزءاً من الإعدادات، فكتابة العمود من الصفر تُطفئ
     * إعدادات لم يمسّها أحد — كالـ whatsapp وأوقات العمل.
     */
    public function test_it_never_wipes_settings_it_was_not_given(): void
    {
        $this->setMetadata([
            'timezone' => 'Asia/Riyadh',
            'whatsapp' => ['access_token' => 'secret-token'],
            'working_hours' => [['day' => 1, 'open' => '09:00', 'close' => '17:00']],
            'notifications' => ['enable_sound' => true, 'tone' => 'bell'],
        ]);

        $this->postJson('/api/v1/settings/general', ['timezone' => 'Asia/Dubai'])->assertOk();

        $metadata = $this->metadata();

        $this->assertSame('secret-token', $metadata['whatsapp']['access_token'], 'ربط واتساب ضاع');
        $this->assertCount(1, $metadata['working_hours'], 'أوقات العمل ضاعت');
        $this->assertSame('bell', $metadata['notifications']['tone'], 'الإشعارات ضاعت');
        $this->assertSame('Asia/Dubai', $metadata['timezone']);
    }

    /** والدمج داخل القسم نفسه جزئي أيضاً. */
    public function test_a_section_is_merged_not_replaced(): void
    {
        $this->setMetadata(['notifications' => ['enable_sound' => true, 'tone' => 'bell', 'volume' => 0.7]]);

        $this->postJson('/api/v1/settings/general', [
            'notifications' => ['tone' => 'chime'],
        ])->assertOk();

        $notifications = $this->metadata()['notifications'];

        $this->assertSame('chime', $notifications['tone']);
        $this->assertSame(0.7, $notifications['volume'], 'ما لم يُرسل في القسم ضاع');
    }

    public function test_an_invalid_support_url_is_rejected(): void
    {
        $response = $this->postJson('/api/v1/settings/general', [
            'support' => ['ticket_form_url' => 'ليس رابطاً'],
        ]);

        $response->assertStatus(400);
        $this->assertArrayHasKey('support.ticket_form_url', $response->json('errors'));
    }

    /** الموظّف لا يغيّر إعدادات المنشأة. */
    public function test_an_agent_cannot_update_settings(): void
    {
        $this->actAs($this->member('agent'));

        $this->postJson('/api/v1/settings/general', ['timezone' => 'Asia/Dubai'])
            ->assertStatus(403)
            ->assertJsonPath('success', false);
    }

    /** لكنه يقرأها: الشاشة تعرض المنطقة الزمنية ونغمة الإشعار. */
    public function test_an_agent_can_read_settings(): void
    {
        $this->actAs($this->member('agent'));

        $this->getJson('/api/v1/settings/general')->assertOk();
    }

    // ------------------------------------------------- أوقات العمل

    public function test_it_returns_working_hours(): void
    {
        $this->setMetadata([
            'working_hours' => [['day' => 0, 'open' => '09:00', 'close' => '17:00']],
            'working_hours_outside_message' => 'نعتذر، خارج الدوام',
        ]);

        $this->getJson('/api/v1/settings/working-hours')
            ->assertOk()
            ->assertJsonPath('data.working_hours.0.day', 0)
            ->assertJsonPath('data.working_hours.0.open', '09:00')
            ->assertJsonPath('data.working_hours_outside_message', 'نعتذر، خارج الدوام');
    }

    public function test_it_saves_working_hours(): void
    {
        $this->postJson('/api/v1/settings/working-hours', [
            'slots' => [
                ['day' => 0, 'open' => '09:00', 'close' => '17:00'],
                ['day' => 1, 'open' => '10:00', 'close' => '18:00'],
            ],
            'working_hours_outside_message' => 'نعود غداً',
        ])->assertOk()->assertJsonCount(2, 'data.working_hours');

        $metadata = $this->metadata();

        $this->assertSame('نعود غداً', $metadata['working_hours_outside_message']);
        $this->assertSame(1, $metadata['working_hours'][1]['day']);
    }

    /** نهاية قبل بداية تعني فترة لا تُغلق أبداً — نفس فحص الويب. */
    public function test_an_end_before_the_start_is_rejected(): void
    {
        $this->postJson('/api/v1/settings/working-hours', [
            'slots' => [['day' => 0, 'open' => '17:00', 'close' => '09:00']],
        ])->assertStatus(400)->assertJsonPath('success', false);

        $this->assertArrayNotHasKey('working_hours', $this->metadata());
    }

    public function test_an_invalid_day_is_rejected(): void
    {
        $this->postJson('/api/v1/settings/working-hours', [
            'slots' => [['day' => 9, 'open' => '09:00', 'close' => '17:00']],
        ])->assertStatus(400);
    }

    public function test_an_invalid_time_format_is_rejected(): void
    {
        $this->postJson('/api/v1/settings/working-hours', [
            'slots' => [['day' => 0, 'open' => '9am', 'close' => '17:00']],
        ])->assertStatus(400);
    }

    /** إرسال قائمة فارغة يُلغي الدوام كلّه — وهو تصرّف مقصود. */
    public function test_empty_slots_clear_the_schedule(): void
    {
        $this->setMetadata(['working_hours' => [['day' => 0, 'open' => '09:00', 'close' => '17:00']]]);

        $this->postJson('/api/v1/settings/working-hours', ['slots' => []])->assertOk();

        $this->assertSame([], $this->metadata()['working_hours']);
    }

    public function test_an_agent_cannot_change_working_hours(): void
    {
        $this->actAs($this->member('agent'));

        $this->postJson('/api/v1/settings/working-hours', ['slots' => []])->assertStatus(403);
    }

    /** الإضافة معطّلة في الباقة ⇒ الشاشة لا تُفتح أصلاً. */
    public function test_working_hours_requires_the_module(): void
    {
        $this->plan->forceFill([
            'metadata' => json_encode(['message_limit' => -1, 'addons' => ['Working Hours' => false]]),
        ])->save();

        $this->getJson('/api/v1/settings/working-hours')->assertStatus(403);
    }
}
