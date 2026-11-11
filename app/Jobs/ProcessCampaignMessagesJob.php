<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Models\CampaignLog;
use App\Models\CampaignMessageAttempt;
use App\Models\Chat;
use App\Services\CampaignRetryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ProcessCampaignMessagesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 120;
    public $tries = 1;

    /**
     * Logs left in "ongoing" longer than this are considered orphaned by a
     * crashed worker and are reset to "failed" so the retry pipeline can
     * resume them. Must be larger than ProcessSingleCampaignLogJob::$timeout
     * (300s) plus any reasonable slack for queue latency.
     */
    private const STUCK_ONGOING_MINUTES = 15;

    /** فحص المعلّقة مرّة في الدقيقة لا مع كل دورة: العتبة 15 دقيقة فلا يفوت شيء. */
    private const STUCK_CHECK_SECONDS = 60;

    public const STUCK_CHECK_KEY = 'campaign_logs:stuck_ongoing_check';

    /** حدّ السجلّات العالقة التي تُعالج في الدقيقة (إعادة المحاولة لكلٍّ منها). */
    private const STUCK_BATCH = 1000;

    /** الحملات «الجارية» التي يُنظر في إغلاقها كل دقيقة، ومؤشّر الدورة عليها. */
    private const COMPLETION_SWEEP_BATCH = 100;

    public const COMPLETION_CURSOR_KEY = 'campaigns:completion_sweep_after_id';

    public function handle(): void
    {
        try {
            // مرّة في الدقيقة لا مع كل دورة (كل 5 ثوانٍ).
            if (Cache::add(self::STUCK_CHECK_KEY, 1, self::STUCK_CHECK_SECONDS)) {
                $retryService = app(CampaignRetryService::class);
                $this->resetStuckOngoingLogs($retryService);
                $this->completeFinishedCampaigns($retryService);
            }

            $batchSize = max(1, (int) config('campaigns.send_batch_size', 10));
            $organizationIds = $this->organizationIdsWithPendingLogs();

            if ($organizationIds->isEmpty()) {
                return;
            }

            $campaignsTouched = [];

            foreach ($organizationIds as $organizationId) {
                $logs = $this->pendingLogsForOrganization((int) $organizationId, $batchSize);

                foreach ($logs as $log) {
                    ProcessSingleCampaignLogJob::dispatch($log)
                        ->onQueue('campaign-messages');

                    $campaignsTouched[$log->campaign_id] = true;
                }
            }

            foreach (array_keys($campaignsTouched) as $campaignId) {
                $this->markCampaignAsCompleted($campaignId);
            }
        } catch (\Exception $e) {
            Log::error('Error in ProcessCampaignMessagesJob: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * لا فهرس على status ولا updated_at، فالشرط يمسح الجدول كلّه (945 ألف
     * صفّ، ~0.4s على الإنتاج). وكان UPDATE مباشراً كل 5 ثوانٍ: في REPEATABLE
     * READ يقفل كل صفّ يمرّ عليه حتى ينتهي، فكل إرسال حملة وكل تحديث حالة
     * على campaign_logs ينتظره — ظهر جارياً في خُمس عيّنات الإنتاج.
     *
     * الآن: مرّة في الدقيقة، والبحث قراءة بلا أقفال، والتحديث بالمعرّفات
     * فيقفل الصفوف العالقة وحدها. ويعيد التحقّق من الشرطين فلا يمسّ سجلاً
     * تقدّم بين القراءة والتحديث.
     *
     * ثم ما وعد به التعليق أعلاه ولم يكن يحدث: إعادة المحاولة (إن فعّلها
     * التاجر)، وإغلاق الحملة إن لم يبقَ فيها شيء. كانت السجلّات تُعلَّم فاشلة
     * وتبقى الحملة «جارية» إلى الأبد.
     */
    private function resetStuckOngoingLogs(CampaignRetryService $retryService): void
    {
        $cutoff = now()->subMinutes(self::STUCK_ONGOING_MINUTES);
        $stuckIds = CampaignLog::where('status', 'ongoing')
            ->where('updated_at', '<', $cutoff)
            ->orderBy('id')
            ->limit(self::STUCK_BATCH)
            ->pluck('id');

        if ($stuckIds->isEmpty()) {
            return;
        }

        $resetAt = now();
        $stuckCount = 0;
        foreach ($stuckIds->chunk(1000) as $ids) {
            $stuckCount += CampaignLog::whereIn('id', $ids->all())
                ->where('status', 'ongoing')
                ->where('updated_at', '<', $cutoff)
                ->update([
                    'status' => 'failed',
                    'updated_at' => $resetAt,
                ]);
        }

        if ($stuckCount > 0) {
            Log::warning('ProcessCampaignMessagesJob: reset stuck ongoing logs', [
                'count' => $stuckCount,
            ]);
        }

        $campaignIds = [];
        $reset = CampaignLog::whereIn('id', $stuckIds->all())
            ->where('status', 'failed')
            ->where('updated_at', $resetAt)
            ->get();

        foreach ($reset as $log) {
            $campaignIds[$log->campaign_id] = true;

            // عاملٌ سقط بعد أن قبل Meta الرسالة وقبل أن يحفظ النتيجة: إعادتها
            // تصل العميلَ مرّتين. sendTemplateMessage ينشئ رسالة المحادثة فور
            // قبول Meta، فرسالةٌ صادرة إليه خلال نافذة المحاولة هي هي: يُربط
            // السجلّ بها ناجحاً — كما كانت المرحلة الثالثة ستفعل — بلا إعادة.
            $sentChatId = $this->chatSentDuringAttempt($log);
            if ($sentChatId !== null) {
                CampaignLog::where('id', $log->id)->where('status', 'failed')
                    ->update(['status' => 'success', 'chat_id' => $sentChatId, 'updated_at' => now()]);
                continue;
            }

            try {
                $retryService->scheduleNextRetry($log);
            } catch (\Throwable $e) {
                Log::error('ProcessCampaignMessagesJob: retry for a stuck log failed', ['log_id' => $log->id, 'error' => $e->getMessage()]);
            }
        }

        foreach (array_keys($campaignIds) as $campaignId) {
            $retryService->completeIfDone((int) $campaignId);
        }
    }

    /**
     * الرسالة الصادرة إلى جهة الاتصال خلال آخر محاولة لهذا السجلّ، إن وُجدت.
     * النافذة عشر دقائق: أطول من مهلة المهمّة (300s)، وأقصر من العتبة (15).
     */
    private function chatSentDuringAttempt(CampaignLog $log): ?int
    {
        $startedAt = CampaignMessageAttempt::where('campaign_log_id', $log->id)
            ->orderByDesc('attempt_number')
            ->value('executed_at');

        if ($startedAt === null) {
            return null;
        }

        $startedAt = \Illuminate\Support\Carbon::parse($startedAt);
        $id = Chat::where('contact_id', $log->contact_id)
            ->where('type', 'outbound')
            ->whereBetween('created_at', [$startedAt, $startedAt->copy()->addMinutes(10)])
            ->orderBy('id')
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * الحملات «الجارية» بلا شيء يُرسل ولا إعادة تنتظر تُغلق. هذا ما يُصلح
     * الحملات التي علقت قبل إصلاح المسارات أعلاه (456 في الإنتاج يوم
     * 11/11/2026)، ويلتقط أي مسارٍ لم نعرفه بعد. على دفعات بمؤشّر يدور.
     */
    private function completeFinishedCampaigns(CampaignRetryService $retryService): void
    {
        $after = (int) Cache::get(self::COMPLETION_CURSOR_KEY, 0);

        $ids = Campaign::where('status', 'ongoing')
            ->whereNull('deleted_at')
            ->where('id', '>', $after)
            ->orderBy('id')
            ->limit(self::COMPLETION_SWEEP_BATCH)
            ->pluck('id');

        Cache::forever(
            self::COMPLETION_CURSOR_KEY,
            $ids->count() < self::COMPLETION_SWEEP_BATCH ? 0 : (int) $ids->last()
        );

        foreach ($ids as $id) {
            try {
                $retryService->completeIfDone((int) $id);
            } catch (\Throwable $e) {
                Log::error('ProcessCampaignMessagesJob: completion sweep failed', ['campaign_id' => $id, 'error' => $e->getMessage()]);
            }
        }
    }

    private function organizationIdsWithPendingLogs()
    {
        return CampaignLog::query()
            ->select('campaigns.organization_id')
            ->join('campaigns', 'campaign_logs.campaign_id', '=', 'campaigns.id')
            ->where('campaign_logs.status', 'pending')
            ->where('campaigns.status', 'ongoing')
            ->whereNull('campaigns.deleted_at')
            ->distinct()
            ->orderBy('campaigns.organization_id')
            ->pluck('organization_id');
    }

    private function pendingLogsForOrganization(int $organizationId, int $batchSize)
    {
        return CampaignLog::with(['campaign.organization', 'contact'])
            ->where('status', 'pending')
            ->whereHas('campaign', function ($query) use ($organizationId) {
                $query->where('status', 'ongoing')
                    ->where('organization_id', $organizationId)
                    ->whereNull('deleted_at');
            })
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit($batchSize)
            ->get();
    }

    private function markCampaignAsCompleted(int $campaignId): void
    {
        $campaign = Campaign::with('organization')->find($campaignId);
        if (!$campaign || $campaign->status !== 'ongoing') {
            return;
        }

        $hasPendingOrOngoing = CampaignLog::where('campaign_id', $campaignId)
            ->whereIn('status', ['pending', 'ongoing'])
            ->exists();

        if ($hasPendingOrOngoing) {
            return;
        }

        $retryService = app(CampaignRetryService::class);
        if ($retryService->hasRetryableFailures($campaign)) {
            return;
        }

        $campaign->status = 'completed';
        $campaign->save();
    }
}
