<?php

namespace Tests\Feature\Campaigns;

use App\Jobs\ProcessCampaignMessagesJob;
use App\Jobs\ProcessSingleCampaignLogJob;
use App\Jobs\RetryCampaignLogJob;
use App\Models\Campaign;
use App\Models\CampaignLog;
use App\Models\CampaignLogRetry;
use App\Models\CampaignMessageAttempt;
use App\Models\Chat;
use App\Models\Organization;
use App\Models\Template;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * ما يحدث حين لا يسير الإرسال كما يجب: أخطاء Meta، انقطاع الشبكة، بلاغ فشلٍ
 * يصل لاحقاً، قالبٌ حُذف، جهة اتصال حُذفت، عاملٌ سقط في منتصف الإرسال.
 *
 * في كل حالة: لا تصل العميلَ رسالةٌ مرّتين، ويُعاد ما يستحق الإعادة إن
 * فعّلها التاجر، ولا تبقى الحملة «جارية» إلى الأبد.
 */
class CampaignFailureAndRetryTest extends CampaignTestCase
{
    // ───────────────────── الدفعات وعدم التكرار ─────────────────────

    public function test_a_large_campaign_goes_out_in_batches_oldest_first(): void
    {
        config(['campaigns.send_batch_size' => 10]);
        $customers = [];
        for ($i = 10; $i < 35; $i++) {
            $customers[] = $this->customer('+9665000000' . $i, 'عميل ' . $i);
        }
        $campaign = $this->ongoingCampaign($this->template(), $customers);

        $this->dispatcherTick();
        $this->assertSame(array_map(fn ($c) => $c->phone, array_slice($customers, 0, 10)), array_column($this->templatesSent(), 'to'));
        $this->assertSame('ongoing', $campaign->fresh()->status);

        $this->dispatcherTick();
        $this->dispatcherTick();

        $this->assertCount(25, $this->templatesSent());
        $this->assertSame(25, CampaignLog::where('status', 'success')->count());
        $this->assertSame('completed', $campaign->fresh()->status);
    }

    /** منشأتان في الدورة نفسها: لكلٍّ دفعتها، فلا تحجب حملةٌ كبيرة حملةَ غيرها. */
    public function test_each_organization_gets_its_own_batch_per_tick(): void
    {
        config(['campaigns.send_batch_size' => 2]);
        $mine = $this->ongoingCampaign($this->template(), [
            $this->customer('+966500000011', 'أ'), $this->customer('+966500000012', 'ب'), $this->customer('+966500000013', 'ج'),
        ]);
        $other = $this->makeOrganization($this->owner);
        $otherTemplate = Template::create(array_merge($this->template('other_offer')->only(['name', 'language', 'category', 'status', 'metadata', 'created_by']), [
            'uuid' => (string) \Illuminate\Support\Str::uuid(), 'organization_id' => $other->id, 'meta_id' => '777',
        ]));
        $this->ongoingCampaign($otherTemplate, [
            $this->customer('+966500000021', 'د', [], $other), $this->customer('+966500000022', 'هـ', [], $other),
        ], ['organization_id' => $other->id]);

        $this->dispatcherTick();

        $phones = array_column($this->templatesSent(), 'to');
        sort($phones);
        $this->assertSame(['+966500000011', '+966500000012', '+966500000021', '+966500000022'], $phones);
        $this->assertSame('ongoing', $mine->fresh()->status);
    }

    /** المهمّة نفسها مرّتين (إعادة تشغيل من الطابور): رسالة واحدة. */
    public function test_the_same_log_processed_twice_is_sent_once(): void
    {
        $campaign = $this->ongoingCampaign($this->template(), [$this->customer('+966500000011', 'سارة')]);
        $log = CampaignLog::where('campaign_id', $campaign->id)->sole();

        (new ProcessSingleCampaignLogJob($log))->handle();
        (new ProcessSingleCampaignLogJob($log))->handle();

        $this->assertCount(1, $this->templatesSent());
        $this->assertSame(1, CampaignMessageAttempt::where('campaign_log_id', $log->id)->count());
    }

    // ───────────────────── أخطاء الإرسال ─────────────────────

    /** خطأ مؤقت من Meta: فاشل الآن، وإعادة بعد الفترة التي اختارها التاجر، ثم تكتمل. */
    public function test_a_temporary_meta_error_is_retried_after_the_merchant_interval(): void
    {
        Bus::fake([RetryCampaignLogJob::class]);
        $this->setRetrySettings(true, [2]);
        $this->fakeGraphSequence([131049]);
        $campaign = $this->ongoingCampaign($this->template(), [$this->customer('+966500000011', 'سارة')]);
        $log = CampaignLog::where('campaign_id', $campaign->id)->sole();

        $this->dispatcherTick();

        $this->assertSame('failed', $log->fresh()->status);
        $this->assertStringContainsString('131049', (string) CampaignMessageAttempt::where('campaign_log_id', $log->id)->value('failure_reason'));
        $this->assertSame('ongoing', $campaign->fresh()->status, 'a retry is pending');
        Bus::assertDispatched(RetryCampaignLogJob::class, function (RetryCampaignLogJob $job) {
            $delay = $job->delay instanceof \DateTimeInterface ? now()->diffInMinutes($job->delay) : 0;

            return $delay >= 120 && $delay <= 135; // ساعتان + نشرٌ حتى 15 دقيقة
        });

        (new RetryCampaignLogJob($this->organization->id, $log->id, 0))->handle();

        $this->assertCount(2, $this->templatesSent());
        $this->assertSame('success', $log->fresh()->status);
        $this->assertNotNull($log->fresh()->chat_id);
        $this->assertSame('completed', $campaign->fresh()->status);
    }

    /** انقطاع الشبكة أو انتهاء المهلة: فشلٌ فوري واضح، لا عاملٌ معلّق. */
    public function test_a_network_timeout_fails_the_log_cleanly(): void
    {
        $this->fakeGraphSequence(['connect']);
        $campaign = $this->ongoingCampaign($this->template(), [$this->customer('+966500000011', 'سارة')]);

        $this->dispatcherTick();

        $log = CampaignLog::where('campaign_id', $campaign->id)->sole();
        $this->assertSame('failed', $log->status);
        $this->assertStringContainsString('timed out', (string) CampaignMessageAttempt::where('campaign_log_id', $log->id)->value('failure_reason'));
        $this->assertSame('completed', $campaign->fresh()->status, 'resend is off: nothing left to do');
        $this->assertSame(1, (int) $campaign->fresh()->getCounts()->total_failed_count);
    }

    /** آخر محاولة فشلت: يُنقل العميل إلى مجموعة الفاشلين ولا إعادة بعدها. */
    public function test_after_the_last_retry_the_customer_moves_to_the_failed_group(): void
    {
        Bus::fake([RetryCampaignLogJob::class]);
        $failed = $this->group([], 'الفاشلون');
        $this->setRetrySettings(true, [1], (string) $failed->uuid);
        $this->fakeGraphSequence([131049, 131049]);
        $sara = $this->customer('+966500000011', 'سارة');
        $campaign = $this->ongoingCampaign($this->template(), [$sara]);
        $log = CampaignLog::where('campaign_id', $campaign->id)->sole();

        $this->dispatcherTick();
        (new RetryCampaignLogJob($this->organization->id, $log->id, 0))->handle();

        $this->assertSame('failed', $log->fresh()->status);
        $this->assertTrue(DB::table('contact_contact_group')->where('contact_id', $sara->id)->where('contact_group_id', $failed->id)->exists());
        $this->assertSame('completed', $campaign->fresh()->status);
        Bus::assertDispatchedTimes(RetryCampaignLogJob::class, 1);
    }

    // ───────────────────── بلاغات الفشل من الـ webhook ─────────────────────

    /** قُبلت ثم فشلت عند Meta بخطأ مؤقت: يُعاد فتح الحملة وتُجدول إعادة. */
    public function test_a_temporary_failure_reported_later_reopens_and_retries(): void
    {
        Bus::fake([RetryCampaignLogJob::class]);
        $this->setRetrySettings(true, [1]);
        $campaign = $this->ongoingCampaign($this->template(), [$this->customer('+966500000011', 'سارة')]);
        $this->dispatcherTick();
        $log = CampaignLog::where('campaign_id', $campaign->id)->sole();
        $this->assertSame('completed', $campaign->fresh()->status);

        $this->whatsappStatus($this->chatOf($log)->wam_id, 'failed', ['errors' => [['code' => 131049, 'title' => 'Per-user marketing limit']]]);

        $this->assertSame('failed', $log->fresh()->status);
        $this->assertSame('ongoing', $campaign->fresh()->status);
        Bus::assertDispatched(RetryCampaignLogJob::class);
    }

    /** فُتحت الحملة لإعادةٍ لم يبقَ لها رصيد: تُغلق ثانيةً لا تبقى جارية. */
    public function test_a_reported_failure_with_no_retries_left_closes_the_campaign_again(): void
    {
        Bus::fake([RetryCampaignLogJob::class]);
        $this->setRetrySettings(true, [1]);
        $campaign = $this->ongoingCampaign($this->template(), [$this->customer('+966500000011', 'سارة')]);
        $this->dispatcherTick();
        $log = CampaignLog::where('campaign_id', $campaign->id)->sole();
        CampaignLogRetry::create(['campaign_log_id' => $log->id, 'status' => 'failed']);

        $this->whatsappStatus($this->chatOf($log)->wam_id, 'failed', ['errors' => [['code' => 131049]]]);

        $this->assertSame('failed', $log->fresh()->status);
        $this->assertSame('completed', $campaign->fresh()->status);
        Bus::assertNotDispatched(RetryCampaignLogJob::class);
    }

    // ───────────────────── حملات لا تبقى «جارية» ─────────────────────

    /** قالبٌ حُذف بعد الجدولة: كل السجلّات تفشل بلا طلب إلى Meta، والحملة تُغلق. */
    public function test_a_campaign_whose_template_was_deleted_still_completes(): void
    {
        $template = $this->template();
        $campaign = $this->ongoingCampaign($template, [$this->customer('+966500000011', 'سارة'), $this->customer('+966500000012', 'عمر')]);
        $template->delete();

        $this->workerProcessesEveryLog($campaign);

        $this->assertSame([], $this->templatesSent());
        $this->assertSame(2, CampaignLog::where('campaign_id', $campaign->id)->where('status', 'failed')->count());
        $this->assertSame('completed', $campaign->fresh()->status);
    }

    /** جهة الاتصال الأخيرة حُذفت قبل دورها: تُتخطّى والحملة تُغلق. */
    public function test_a_deleted_last_contact_does_not_keep_the_campaign_open(): void
    {
        $sara = $this->customer('+966500000011', 'سارة');
        $omar = $this->customer('+966500000012', 'عمر');
        $campaign = $this->ongoingCampaign($this->template(), [$sara, $omar]);
        $omar->delete();

        $this->workerProcessesEveryLog($campaign);

        $this->assertSame(['+966500000011'], array_column($this->templatesSent(), 'to'));
        $this->assertSame('failed', CampaignLog::where('contact_id', $omar->id)->value('status'));
        $this->assertSame('completed', $campaign->fresh()->status);
    }

    /** عاملٌ سقط قبل أن يرسل: السجلّ العالق يُعاد إن فعّل التاجر الإعادة. */
    public function test_a_stuck_log_that_never_reached_meta_is_retried(): void
    {
        Bus::fake([RetryCampaignLogJob::class]);
        $this->setRetrySettings(true, [1]);
        $campaign = $this->ongoingCampaign($this->template(), [$this->customer('+966500000011', 'سارة')]);
        $log = CampaignLog::where('campaign_id', $campaign->id)->sole();
        $this->stuckSince($log, 20);

        $this->dispatcherTick();

        $this->assertSame('failed', $log->fresh()->status);
        Bus::assertDispatched(RetryCampaignLogJob::class);
        $this->assertSame('ongoing', $campaign->fresh()->status, 'the retry is pending');
    }

    /**
     * عاملٌ سقط بعد أن قبل Meta الرسالة: رسالة المحادثة موجودة. يُربط السجلّ
     * بها ناجحاً ولا يُعاد — العميل استلمها، والإعادة تصله مرّتين.
     */
    public function test_a_stuck_log_whose_message_was_already_sent_is_not_sent_again(): void
    {
        Bus::fake([RetryCampaignLogJob::class]);
        $this->setRetrySettings(true, [1]);
        $sara = $this->customer('+966500000011', 'سارة');
        $campaign = $this->ongoingCampaign($this->template(), [$sara]);
        $log = CampaignLog::where('campaign_id', $campaign->id)->sole();
        $this->stuckSince($log, 20);
        $chat = Chat::create([
            'organization_id' => $this->organization->id, 'contact_id' => $sara->id, 'type' => 'outbound',
            'wam_id' => 'wamid.ACCEPTED-BEFORE-CRASH', 'status' => 'sent', 'metadata' => '{}', 'created_at' => now()->subMinutes(20),
        ]);

        $this->dispatcherTick();

        $this->assertSame('success', $log->fresh()->status);
        $this->assertSame($chat->id, (int) $log->fresh()->chat_id);
        Bus::assertNotDispatched(RetryCampaignLogJob::class);
        $this->assertSame([], $this->templatesSent());
        $this->assertSame('completed', $campaign->fresh()->status);
    }

    /**
     * حملات علقت «جارية» قبل الإصلاح (456 في الإنتاج): الفحص الدوري يُغلق ما
     * لم يبقَ فيه شيء، ويترك ما يُرسل الآن أو تنتظره إعادة أو حُذف.
     */
    public function test_the_sweep_closes_only_campaigns_with_nothing_left(): void
    {
        $template = $this->template();
        $this->setRetrySettings(true, [1]);

        $finished = $this->ongoingCampaign($template, [$this->customer('+966500000011', 'أ')]);
        CampaignLog::where('campaign_id', $finished->id)->update(['status' => 'success']);

        $sending = $this->ongoingCampaign($template, [$this->customer('+966500000012', 'ب')]);
        CampaignLog::where('campaign_id', $sending->id)->update(['status' => 'ongoing', 'updated_at' => now()]);

        $retrying = $this->ongoingCampaign($template, [$this->customer('+966500000013', 'ج')]);
        CampaignLog::where('campaign_id', $retrying->id)->update(['status' => 'failed']);

        $deleted = $this->ongoingCampaign($template, [$this->customer('+966500000014', 'د')]);
        CampaignLog::where('campaign_id', $deleted->id)->update(['status' => 'success']);
        Campaign::where('id', $deleted->id)->update(['deleted_at' => now()]);

        $this->dispatcherTick();

        $this->assertSame('completed', $finished->fresh()->status);
        $this->assertSame('ongoing', $sending->fresh()->status, 'still sending');
        $this->assertSame('ongoing', $retrying->fresh()->status, 'a retry is pending');
        $this->assertSame('ongoing', Campaign::where('id', $deleted->id)->value('status'), 'deleted campaigns are left alone');
    }

    /** الفحص الدوري يمرّ على الحملات بدفعات بمؤشّر يدور، فيصل كلّها مع الوقت. */
    public function test_the_sweep_walks_through_all_campaigns_in_batches(): void
    {
        $template = $this->template();
        $ids = [];
        for ($i = 0; $i < 105; $i++) {
            $ids[] = Campaign::create([
                'uuid' => (string) \Illuminate\Support\Str::uuid(), 'organization_id' => $this->organization->id,
                'name' => 'c' . $i, 'status' => 'ongoing', 'template_id' => $template->id, 'contact_group_id' => 0,
                'metadata' => '{}', 'created_by' => $this->owner->id, 'scheduled_at' => now(),
            ])->id;
        }

        $this->dispatcherTick();
        $this->assertSame(100, Campaign::whereIn('id', $ids)->where('status', 'completed')->count());
        $this->assertSame(end($ids) - 5, (int) Cache::get(ProcessCampaignMessagesJob::COMPLETION_CURSOR_KEY));

        Cache::forget(ProcessCampaignMessagesJob::STUCK_CHECK_KEY);
        $this->dispatcherTick();
        $this->assertSame(105, Campaign::whereIn('id', $ids)->where('status', 'completed')->count());
        $this->assertSame(0, (int) Cache::get(ProcessCampaignMessagesJob::COMPLETION_CURSOR_KEY));
    }

    // ───────────────────── لا رسالة مرّتين ─────────────────────

    /**
     * Meta قبلت الرسالة ثم تعذّر حفظها عندنا: كان السجلّ يُعلَّم فاشلاً فيُعاد
     * الإرسال ويصل العميلَ مرّتين. الآن يبقى ناجحاً ولا يُعاد.
     */
    public function test_a_local_save_failure_after_meta_accepted_does_not_resend(): void
    {
        Bus::fake([RetryCampaignLogJob::class]);
        $this->setRetrySettings(true, [1]);
        $campaign = $this->ongoingCampaign($this->template(), [$this->customer('+966500000011', 'سارة')]);
        Chat::creating(function (Chat $chat) {
            if ($chat->type === 'outbound') {
                throw new \RuntimeException('database went away');
            }
        });

        $this->dispatcherTick();

        $log = CampaignLog::where('campaign_id', $campaign->id)->sole();
        $this->assertCount(1, $this->templatesSent());
        $this->assertSame('success', $log->status);
        $this->assertNull($log->chat_id);
        Bus::assertNotDispatched(RetryCampaignLogJob::class);
        $this->assertSame('completed', $campaign->fresh()->status);
    }

    // ───────────────────── قناة أخرى ─────────────────────

    /** حملة تيليجرام تمرّ بالمُجدول والمهامّ نفسها، وتصل بلا قالب ولا Meta. */
    public function test_a_telegram_campaign_goes_through_the_same_jobs(): void
    {
        \Illuminate\Support\Facades\Http::fake([
            'api.telegram.org/*' => \Illuminate\Support\Facades\Http::response(['ok' => true, 'result' => ['message_id' => 901]], 200),
        ]);
        $bot = \App\Models\ChannelAccount::create([
            'organization_id' => $this->organization->id, 'channel' => 'telegram', 'external_id' => 'bot-1',
            'credentials' => ['bot_token' => 'tok', 'webhook_updates' => 'callback_query'],
            'status' => \App\Models\ChannelAccount::STATUS_ACTIVE, 'created_by' => 0,
        ]);
        $ahmed = $this->customer('+966500000031', 'أحمد', ['channel' => 'telegram', 'channel_identifier' => '777', 'channel_account_id' => $bot->id, 'phone' => null]);
        $this->customer('+966500000032', 'واتساب');

        $campaign = new Campaign;
        $campaign->forceFill([
            'uuid' => (string) \Illuminate\Support\Str::uuid(), 'organization_id' => $this->organization->id, 'name' => 'تيليجرام',
            'channel' => 'telegram', 'channel_account_id' => $bot->id, 'template_id' => null, 'contact_group_id' => 0,
            'metadata' => '{}', 'message_payload' => json_encode(['text' => 'عرض اليوم يا {first_name}', 'media' => null], JSON_UNESCAPED_UNICODE),
            'created_by' => $this->owner->id, 'status' => 'scheduled', 'scheduled_at' => now(),
        ])->save();

        $this->schedulerMinute();

        \Illuminate\Support\Facades\Http::assertSent(fn ($request) => str_contains($request->url(), '/sendMessage')
            && $request['chat_id'] == '777' && str_contains($request['text'], 'أحمد'));
        $this->assertSame([], $this->templatesSent(), 'nothing to WhatsApp');
        $this->assertSame(1, CampaignLog::where('campaign_id', $campaign->id)->count(), 'the WhatsApp customer is not in a Telegram campaign');
        $this->assertSame('success', CampaignLog::where('contact_id', $ahmed->id)->value('status'));
        $this->assertSame('completed', $campaign->fresh()->status);
    }

    // ───────────────────── مساعدات ─────────────────────

    /**
     * عامل الطابور يعالج كل سجلّ وحده، كما في الإنتاج. لا dispatcherTick: مع
     * الطابور المتزامن يجد markCampaignAsCompleted بعد التوزيع كل شيء منتهياً
     * فيُغلق الحملة، أمّا على Redis فالسجلّات ما زالت pending حينها — فالإغلاق
     * الذي يعني الإنتاج هو ما تفعله مهمّة السجلّ الأخير نفسها.
     */
    private function workerProcessesEveryLog(Campaign $campaign): void
    {
        foreach (CampaignLog::where('campaign_id', $campaign->id)->orderBy('id')->get() as $log) {
            (new ProcessSingleCampaignLogJob($log))->handle();
        }
    }

    /** سجلٌّ بدأ عامل إرساله قبل $minutes دقيقة ثم سقط. */
    private function stuckSince(CampaignLog $log, int $minutes): void
    {
        CampaignMessageAttempt::create([
            'campaign_id' => $log->campaign_id, 'campaign_log_id' => $log->id, 'contact_id' => $log->contact_id,
            'channel' => 'whatsapp', 'attempt_number' => 1, 'is_retry' => false, 'status' => 'ongoing',
            'executed_at' => now()->subMinutes($minutes),
        ]);
        DB::table('campaign_logs')->where('id', $log->id)->update(['status' => 'ongoing', 'updated_at' => now()->subMinutes($minutes)]);
    }
}
