<?php

namespace App\Services;

use App\Jobs\ProcessCampaignMessagesJob;
use App\Models\Campaign;
use App\Models\CampaignLog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * إعادة إرسال الرسائل الفاشلة لحملة واحدة.
 *
 * في خدمة لا في متحكّم الويب: تطبيق الجوال يعيد الإرسال أيضاً، وقاعدة «ما
 * الذي يصحّ إعادته» يجب أن تكون واحدة. إعادة الإرسال إلى فشلٍ نهائي (الرقم
 * ليس على واتساب، أو رفض المستلم التسويق) لا تنجح أبداً، وتكرارها يخفض جودة
 * الرقم حتى الحظر — وهو ما وقع فعلاً حين كانت الإعادة شاملة.
 */
class CampaignResendService
{
    /** الحالات التي يلتقطها ProcessCampaignMessagesJob: المجدولة لا تُستأنف أصلاً. */
    public const RESENDABLE_CAMPAIGN_STATUSES = ['completed', 'ongoing'];

    public function __construct(private readonly CampaignRetryService $retryService)
    {
    }

    public function canResend(Campaign $campaign): bool
    {
        return $campaign->deleted_at === null
            && in_array($campaign->status, self::RESENDABLE_CAMPAIGN_STATUSES, true);
    }

    /**
     * معرّفات السجلات الفاشلة التي يصحّ إعادة إرسالها لهذه الحملة.
     *
     * @return Collection<int, int>
     */
    public function resendableLogIds(int $campaignId): Collection
    {
        $logs = CampaignLog::where('campaign_id', $campaignId)
            ->where('status', 'failed')
            ->pluck('chat_id', 'id');

        if ($logs->isEmpty()) {
            return collect();
        }

        // آخر بلاغ فشل لكل محادثة يحمل كود الخطأ الذي يحدّد قابلية الإعادة.
        $errorsByChat = [];
        $chatIds = $logs->filter()->values();
        if ($chatIds->isNotEmpty()) {
            DB::table('chat_status_logs')
                ->whereIn('chat_id', $chatIds)
                ->where('metadata', 'like', '%"status":"failed"%')
                ->orderBy('id')
                ->select('chat_id', 'metadata')
                ->get()
                ->each(function ($row) use (&$errorsByChat) {
                    $decoded = json_decode($row->metadata, true);
                    if (($decoded['status'] ?? null) === 'failed') {
                        $errorsByChat[$row->chat_id] = $decoded['errors'] ?? [];
                    }
                });
        }

        return $logs->filter(function ($chatId, $logId) use ($errorsByChat) {
            // بلا محادثة = رُفض الطلب قبل الإرسال، فلا كود خطأ نهائي هنا.
            if (!$chatId || !array_key_exists($chatId, $errorsByChat)) {
                return true;
            }

            return $this->retryService->isRetryableFailure($errorsByChat[$chatId]);
        })->keys();
    }

    /**
     * يعيد السجلات القابلة للإعادة إلى الطابور.
     *
     * @return int عدد الرسائل التي أُعيدت (0 = لا شيء يُعاد)
     */
    public function resend(Campaign $campaign): int
    {
        $resendable = $this->resendableLogIds($campaign->id);

        if ($resendable->isEmpty()) {
            return 0;
        }

        DB::transaction(function () use ($campaign, $resendable) {
            CampaignLog::whereIn('id', $resendable)->update([
                'status' => 'pending',
                'metadata' => null,
                'chat_id' => null,
                'updated_at' => now(),
            ]);

            if ($campaign->status === 'completed') {
                $campaign->update(['status' => 'ongoing']);
            }
        });

        ProcessCampaignMessagesJob::dispatch()
            ->onQueue('campaign-messages')
            ->afterCommit();

        return $resendable->count();
    }
}
