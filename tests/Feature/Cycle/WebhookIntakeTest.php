<?php

namespace Tests\Feature\Cycle;

use App\Jobs\ProcessAccountUpdateJob;
use App\Jobs\ProcessContactSyncJob;
use App\Jobs\ProcessIncomingMessageJob;
use App\Jobs\ProcessMessageEchoJob;
use App\Jobs\ProcessMessageStatusJob;
use App\Jobs\ProcessTemplateStatusJob;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;

/**
 * باب الدورة: ما يصل من Meta.
 *
 * كل رسالة واردة وكل بلاغ حالة يدخل من هنا، ولم يكن على هذه الطبقة اختبار
 * واحد. وهي التي تقرّر أيّ وظيفة تُشغَّل — فخطأ في التفريع يعني رسائل لا
 * تُعالَج أو حالات لا تُحدَّث، بلا أثر ظاهر.
 */
class WebhookIntakeTest extends CycleTestCase
{
    // ------------------------------------------------- التحقّق (GET)

    /** Meta تتحقّق من العنوان قبل أن ترسل إليه شيئاً. */
    public function test_verification_returns_the_challenge(): void
    {
        $this->get($this->webhookUrl() . '?hub_mode=subscribe&hub_verify_token=' . $this->identifier . '&hub_challenge=12345')
            ->assertOk()
            ->assertSee('12345', false);
    }

    public function test_verification_with_a_wrong_token_is_refused(): void
    {
        $this->get($this->webhookUrl() . '?hub_mode=subscribe&hub_verify_token=خطأ&hub_challenge=12345')
            ->assertStatus(404);
    }

    public function test_verification_without_subscribe_mode_is_refused(): void
    {
        $this->get($this->webhookUrl() . '?hub_mode=unsubscribe&hub_verify_token=' . $this->identifier . '&hub_challenge=12345')
            ->assertStatus(404);
    }

    /** والعنوان العام له رمزه من الإعدادات. */
    public function test_the_shared_webhook_verifies_with_the_global_token(): void
    {
        $this->get('/webhook/waba?hub_mode=subscribe&hub_verify_token=global-verify-token&hub_challenge=99')
            ->assertOk()
            ->assertSee('99', false);
    }

    public function test_the_shared_webhook_refuses_a_wrong_global_token(): void
    {
        $this->get('/webhook/waba?hub_mode=subscribe&hub_verify_token=خطأ&hub_challenge=99')
            ->assertOk()
            ->assertJsonPath('error', 'Forbidden');
    }

    // ------------------------------------------------- الهويّة

    public function test_an_unknown_identifier_is_forbidden(): void
    {
        Bus::fake();

        $this->postJson('/webhook/whatsapp/' . Str::uuid(), $this->payload('messages', [
            'messages' => [$this->textMessage()],
        ]))->assertStatus(403);

        Bus::assertNothingDispatched();
    }

    /** منشأة بلا ربط واتساب لا تُعالَج حمولتها. */
    public function test_an_organization_without_metadata_is_forbidden(): void
    {
        Bus::fake();
        Organization::where('id', $this->organization->id)->update(['metadata' => null]);

        $this->postJson($this->webhookUrl(), $this->payload('messages', [
            'messages' => [$this->textMessage()],
        ]))->assertStatus(403);

        Bus::assertNothingDispatched();
    }

    public function test_an_unsupported_method_is_refused(): void
    {
        $this->putJson($this->webhookUrl(), [])->assertStatus(405);
    }

    // ------------------------------------------------- حمولات لا عمل فيها

    /** حمولة بلا changes تُقبل بهدوء — Meta تُعيد المحاولة على غير 200. */
    public function test_a_payload_without_changes_is_accepted_quietly(): void
    {
        Bus::fake();

        $this->postJson($this->webhookUrl(), ['entry' => [['id' => '1']]])
            ->assertOk()
            ->assertJsonPath('status', 'success');

        Bus::assertNothingDispatched();
    }

    public function test_an_empty_body_is_accepted_quietly(): void
    {
        Bus::fake();

        $this->postJson($this->webhookUrl(), [])->assertOk();

        Bus::assertNothingDispatched();
    }

    // ------------------------------------------------- الرسائل الواردة

    public function test_an_incoming_message_is_dispatched(): void
    {
        Bus::fake();
        $message = $this->textMessage();

        $this->postJson($this->webhookUrl(), $this->payload('messages', [
            'contacts' => [['profile' => ['name' => 'أحمد'], 'wa_id' => '966502486051']],
            'messages' => [$message],
        ]))->assertOk();

        Bus::assertDispatched(ProcessIncomingMessageJob::class, 1);
    }

    /** وكل رسالة في الحمولة وظيفةٌ مستقلّة — لا تُبتلع الثانية بفشل الأولى. */
    public function test_every_message_in_one_payload_gets_its_own_job(): void
    {
        Bus::fake();

        $this->postJson($this->webhookUrl(), $this->payload('messages', [
            'messages' => [$this->textMessage(), $this->textMessage(), $this->textMessage()],
        ]))->assertOk();

        Bus::assertDispatched(ProcessIncomingMessageJob::class, 3);
    }

    public function test_incoming_messages_go_to_the_high_queue(): void
    {
        Bus::fake();

        $this->postJson($this->webhookUrl(), $this->payload('messages', [
            'messages' => [$this->textMessage()],
        ]))->assertOk();

        Bus::assertDispatched(
            ProcessIncomingMessageJob::class,
            fn ($job) => $job->queue === 'high'
        );
    }

    // ------------------------------------------------- بلاغات الحالة

    public function test_a_status_update_is_dispatched(): void
    {
        Bus::fake();

        $this->postJson($this->webhookUrl(), $this->payload('messages', [
            'statuses' => [['id' => 'wamid.x', 'status' => 'delivered', 'timestamp' => '1']],
        ]))->assertOk();

        Bus::assertDispatched(ProcessMessageStatusJob::class, 1);
        Bus::assertDispatched(ProcessMessageStatusJob::class, fn ($job) => $job->queue === 'messageStatus');
    }

    /** حمولة فيها الاثنان تُشغّل الاثنين. */
    public function test_messages_and_statuses_in_one_payload_both_run(): void
    {
        Bus::fake();

        $this->postJson($this->webhookUrl(), $this->payload('messages', [
            'messages' => [$this->textMessage()],
            'statuses' => [['id' => 'wamid.x', 'status' => 'read']],
        ]))->assertOk();

        Bus::assertDispatched(ProcessIncomingMessageJob::class, 1);
        Bus::assertDispatched(ProcessMessageStatusJob::class, 1);
    }

    // ------------------------------------------------- حدّ الرسائل

    /**
     * الاشتراك المنتهي يوقف الواردَ وحده.
     *
     * وهذا ما يقيسه isLimitReached فعلاً — انتهاء الاشتراك لا عدد الرسائل.
     *
     * وبلاغات الحالة تمضي: هي تخصّ رسائل خرجت فعلاً، وإيقافها يُجمّد عدّادات
     * الحملات ويُبقي رسائل مُرسَلة معلّقة على «sent» إلى الأبد.
     */
    public function test_an_expired_subscription_stops_messages_but_not_statuses(): void
    {
        Subscription::where('organization_id', $this->organization->id)
            ->update(['valid_until' => now()->subDay()]);
        Bus::fake();

        $this->postJson($this->webhookUrl(), $this->payload('messages', [
            'messages' => [$this->textMessage()],
            'statuses' => [['id' => 'wamid.x', 'status' => 'delivered']],
        ]))->assertOk();

        Bus::assertNotDispatched(ProcessIncomingMessageJob::class);
        Bus::assertDispatched(ProcessMessageStatusJob::class, 1);
    }

    /** وباقة تسمح بالاستقبال بعد الانتهاء لا يوقفها شيء. */
    public function test_a_plan_that_allows_receiving_after_expiry_keeps_working(): void
    {
        $plan = SubscriptionPlan::create([
            'name' => 'Grace', 'price' => 0, 'period' => 'monthly',
            'metadata' => json_encode(['receive_messages_after_expiration' => true]),
        ]);

        Subscription::where('organization_id', $this->organization->id)
            ->update(['plan_id' => $plan->id, 'valid_until' => now()->subDay()]);
        Bus::fake();

        $this->postJson($this->webhookUrl(), $this->payload('messages', [
            'messages' => [$this->textMessage()],
        ]))->assertOk();

        Bus::assertDispatched(ProcessIncomingMessageJob::class, 1);
    }

    /** ومنشأة بلا اشتراك لا يُعالَج واردها إطلاقاً. */
    public function test_an_organization_without_a_subscription_receives_nothing(): void
    {
        Bus::fake();
        $other = Organization::factory()->create([
            'created_by' => $this->owner->id,
            'identifier' => (string) Str::uuid(),
            'metadata' => json_encode(['whatsapp' => ['access_token' => 't']]),
        ]);

        $this->postJson('/webhook/whatsapp/' . $other->identifier, $this->payload('messages', [
            'messages' => [$this->textMessage()],
        ]))->assertOk();

        Bus::assertNotDispatched(ProcessIncomingMessageJob::class);
    }

    /** منشأة ثانية كاملة الشروط — للتمييز بين المنشآت. */
    private function secondOrganization(): Organization
    {
        $other = Organization::factory()->create([
            'created_by' => $this->owner->id,
            'identifier' => (string) Str::uuid(),
            'metadata' => json_encode(['whatsapp' => ['access_token' => 't']]),
        ]);

        $plan = SubscriptionPlan::create([
            'name' => 'Second', 'price' => 0, 'period' => 'monthly',
            'metadata' => json_encode(['message_limit' => -1]),
        ]);

        Subscription::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $other->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'valid_until' => now()->addYear(),
        ]);

        return $other;
    }

    // ------------------------------------------------- بقيّة الأنواع

    public function test_a_template_status_update_is_dispatched(): void
    {
        Bus::fake();

        $this->postJson($this->webhookUrl(), $this->payload('message_template_status_update', [
            'message_template_id' => '123',
            'event' => 'APPROVED',
        ]))->assertOk();

        Bus::assertDispatched(ProcessTemplateStatusJob::class, 1);
    }

    /** رسائل أرسلها التاجر من تطبيق واتساب نفسه. */
    public function test_message_echoes_are_dispatched_one_per_echo(): void
    {
        Bus::fake();

        $this->postJson($this->webhookUrl(), $this->payload('smb_message_echoes', [
            'message_echoes' => [
                ['id' => 'wamid.a', 'to' => '966502486051', 'type' => 'text', 'text' => ['body' => 'أهلاً']],
                ['id' => 'wamid.b', 'to' => '966502486052', 'type' => 'text', 'text' => ['body' => 'أهلاً']],
            ],
        ]))->assertOk();

        Bus::assertDispatched(ProcessMessageEchoJob::class, 2);
    }

    public function test_an_empty_echo_list_dispatches_nothing(): void
    {
        Bus::fake();

        $this->postJson($this->webhookUrl(), $this->payload('smb_message_echoes', []))->assertOk();

        Bus::assertNotDispatched(ProcessMessageEchoJob::class);
    }

    public function test_a_contact_sync_is_dispatched(): void
    {
        Bus::fake();

        $this->postJson($this->webhookUrl(), $this->payload('smb_app_state_sync', [
            'state_sync' => [['type' => 'contact', 'contact' => ['phone_number' => '966502486051']]],
        ]))->assertOk();

        Bus::assertDispatched(ProcessContactSyncJob::class, 1);
    }

    /** سجلّ المحادثات القديم: يُستقبَل ولا يُعالَج بعد. */
    public function test_history_is_acknowledged_without_a_job(): void
    {
        Bus::fake();

        $this->postJson($this->webhookUrl(), $this->payload('history', ['threads' => []]))->assertOk();

        Bus::assertNothingDispatched();
    }

    /** وأي حقل آخر يمضي إلى وظيفة تحديث الحساب. */
    public function test_an_unknown_field_goes_to_the_account_update_job(): void
    {
        Bus::fake();

        $this->postJson($this->webhookUrl(), $this->payload('phone_number_quality_update', [
            'display_phone_number' => '966500000000',
            'event' => 'FLAGGED',
        ]))->assertOk();

        Bus::assertDispatched(ProcessAccountUpdateJob::class, 1);
    }

    /** ولا تختلط منشأة بأخرى: الحمولة تحمل معرّف المنشأة صاحبة العنوان. */
    public function test_the_job_carries_the_organization_of_the_url(): void
    {
        Bus::fake();
        $other = $this->secondOrganization();

        $this->postJson('/webhook/whatsapp/' . $other->identifier, $this->payload('messages', [
            'messages' => [$this->textMessage()],
        ]))->assertOk();

        Bus::assertDispatched(
            ProcessIncomingMessageJob::class,
            function ($job) use ($other) {
                $reflection = new \ReflectionProperty($job, 'organizationId');
                $reflection->setAccessible(true);

                return (int) $reflection->getValue($job) === (int) $other->id;
            }
        );
    }
}
