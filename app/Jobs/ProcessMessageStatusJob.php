<?php

namespace App\Jobs;

use App\Events\NewChatEvent;
use App\Helpers\DateTimeHelper;
use App\Helpers\WebhookHelper;
use App\Models\Chat;
use App\Models\ChatStatusLog;
use App\Services\CampaignRetryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessMessageStatusJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Keep below the queue retry_after (360s) so the job is never re-queued mid-run.
    public $timeout = 60;
    // One retry with backoff helps ride out short table locks without stacking status jobs.
    public $tries = 2;
    public $backoff = [5, 10, 20];

    /**
     * ترتيب دورة حياة الرسالة في واتساب. الـ webhooks لا تصل مرتّبة والوظائف
     * تُعالَج بالتوازي، فبدون حارس قد يكتب بلاغ delivered متأخّر فوق read
     * ويُنقص عدّاد «تمت القراءة» في الحملات.
     */
    private const STATUS_LADDER = ['accepted', 'sent', 'delivered', 'read', 'played'];

    private const LADDER_SQL = "FIELD(status, 'accepted', 'sent', 'delivered', 'read', 'played')";

    /** حالات تعني أن الرسالة وصلت فعلاً، فلا يصحّ أن يكتب فوقها بلاغ فشل متأخّر. */
    private const DELIVERED_SQL = "FIELD(status, 'delivered', 'read', 'played')";

    protected $statuses;
    protected $organizationId;

    public function __construct($statuses, $organizationId)
    {
        $this->statuses = $statuses;
        $this->organizationId = $organizationId;
    }

    public function handle()
    {
        try {
            $now = DateTimeHelper::convertToOrganizationTimezone(now(), null);

            foreach ($this->statuses as $status) {
                $chatWamId = $status['id'];
                $statusValue = $status['status'];

                // Avoid loading the full row (metadata can be large) unless failed status
                // needs type/media_id/metadata for transcode retry.
                $columns = $statusValue === 'failed'
                    ? ['id', 'type', 'media_id', 'metadata']
                    : ['id'];

                $chat = Chat::where('wam_id', $chatWamId)
                    ->where('organization_id', $this->organizationId)
                    ->first($columns);

                if ($chat) {
                    $applied = $this->applyStatus($chat->id, $statusValue);

                    // نسجّل كل بلاغ يصل حتى لو لم نطبّقه، فالسجل هو مرجع التدقيق.
                    ChatStatusLog::create([
                        'chat_id' => $chat->id,
                        'metadata' => json_encode($status),
                        'created_at' => $now,
                    ]);

                    // لا نبثّ حالة أقدم من المخزّنة حتى لا تتراجع الواجهة أيضاً.
                    $chatLog = $applied ? $chat->chatLog : null;
                    if ($chatLog) {
                        $chatArray = [[
                            'type' => 'chat',
                            'value' => $chatLog->relatedEntities,
                            'tempMessageId' => $status['id'],
                        ]];
                        event(new NewChatEvent($chatArray, $this->organizationId, false, true));
                    }

                    if ($applied && $statusValue === 'failed') {
                        // بلاغ الفشل المتأخّر هو مصدر ٩٩٪ من فشل الحملات؛ بدون هذا
                        // النداء يبقى campaign_logs على "success" فلا يراه زر إعادة
                        // الإرسال ولا تُجدوَل له إعادة محاولة تلقائية.
                        app(CampaignRetryService::class)
                            ->handleWebhookFailure($chat->id, $status['errors'] ?? []);
                    }

                    if (
                        $statusValue === 'failed'
                        && RetryMediaWithTranscodeJob::shouldRetryForChat($chat, $status['errors'] ?? [])
                    ) {
                        RetryMediaWithTranscodeJob::dispatch($chat->id, $this->organizationId)
                            ->onQueue('high');
                    }
                }
            }

            WebhookHelper::triggerWebhookEvent(
                'message.status.update',
                ['data' => $this->statuses],
                $this->organizationId
            );

        } catch (\Exception $e) {
            Log::error('ProcessMessageStatusJob failed', [
                'organization_id' => $this->organizationId,
                'error' => $e->getMessage()
            ]);

            throw $e;
        }
    }

    /**
     * تحديث حالة الرسالة دون التراجع للخلف. الشرط داخل جملة UPDATE نفسها
     * ليكون ذرّياً بين العمّال المتوازين لا مجرد فحص قبل الكتابة.
     *
     * @return bool هل تغيّرت الحالة فعلاً
     */
    private function applyStatus(int $chatId, string $statusValue): bool
    {
        $query = Chat::whereKey($chatId);

        $rank = array_search($statusValue, self::STATUS_LADDER, true);
        if ($rank !== false) {
            // نتقدّم للأمام فقط. الرسالة الفاشلة تُستثنى (FIELD ترجع 0) لأن
            // إعادة إرسال الوسائط تعيد استخدام نفس الصف وتستحق حالة جديدة.
            $query->whereRaw(self::LADDER_SQL . ' < ?', [$rank + 1]);
        } elseif ($statusValue === 'failed') {
            $query->whereRaw(self::DELIVERED_SQL . ' = 0');
        }

        return $query->update(['status' => $statusValue]) > 0;
    }
}
