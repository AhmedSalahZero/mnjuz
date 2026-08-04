<?php

namespace App\Services;

use App\Jobs\RetryCampaignLogJob;
use App\Models\Campaign;
use App\Models\CampaignLog;
use App\Models\CampaignMessageAttempt;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class CampaignRetryService
{
    /**
     * أكواد فشل واتساب النهائية على مستوى المستلم: إعادة إرسال نفس القالب لنفس
     * الرقم لن تنجح أبداً، فإعادة المحاولة تهدر رصيد الإرسال وتضرّ تقييم الجودة.
     * أكواد الحساب المؤقتة (131042 مشكلة الدفع، 131048، 131049، 131053) تبقى
     * قابلة لإعادة المحاولة لأنها تُحل مع الوقت — وهي سبب وجود الميزة أصلاً.
     */
    private const NON_RETRYABLE_ERROR_CODES = [
        131026, // Message undeliverable — الرقم ليس على واتساب
        131050, // المستلم اختار عدم استقبال الرسائل من هذا الحساب
        131047, // Re-engagement message — خارج نافذة الـ24 ساعة
        130472, // User's number is part of an experiment
    ];

    /** نافذة نشر إعادة المحاولات (ثوانٍ) لتفادي انطلاقها كلها في نفس اللحظة. */
    private const RETRY_SPREAD_SECONDS = 900;

    public function getCampaignRetrySettings(Campaign $campaign): array
    {
        $campaign = Campaign::with('organization')->find($campaign->id);
        $metadata = json_decode($campaign?->organization?->metadata ?? '{}', true);
        $campaignSettings = $metadata['campaigns'] ?? [];

        $intervals = collect($campaignSettings['resend_intervals'] ?? [])
            ->map(fn ($value) => (int) $value)
            ->filter(fn ($value) => $value > 0)
            ->values()
            ->all();

        return [
            'enabled' => (bool) ($campaignSettings['enable_resend'] ?? false),
            'intervals' => $intervals,
            'max_retries' => count($intervals),
            'move_failed_contacts_to_group' => (bool) ($campaignSettings['move_failed_contacts_to_group'] ?? false),
            'failed_campaign_group' => $campaignSettings['failed_campaign_group'] ?? null,
        ];
    }

    /**
     * A campaign is "live" only when it exists, is not soft-deleted, and is ongoing.
     * Used as a guard before sending any message (initial or retry).
     */
    public function isCampaignLive(?Campaign $campaign): bool
    {
        if (!$campaign) {
            return false;
        }

        if ($campaign->getRawOriginal('deleted_at') !== null) {
            return false;
        }

        return $campaign->status === 'ongoing';
    }

    public function hasRetryableFailures(Campaign $campaign): bool
    {
        $settings = $this->getCampaignRetrySettings($campaign);
        if (!$settings['enabled'] || $settings['max_retries'] <= 0) {
            return false;
        }

        return CampaignLog::where('campaign_id', $campaign->id)
            ->where('status', 'failed')
            ->whereRaw(
                '(SELECT COUNT(*) FROM campaign_log_retries WHERE campaign_log_retries.campaign_log_id = campaign_logs.id) < ?',
                [$settings['max_retries']]
            )
            ->exists();
    }

    /**
     * هل هذا الفشل مؤقت ويستحق إعادة المحاولة؟
     *
     * @param  array<int, array<string, mixed>>  $errors  مصفوفة errors كما تصل من webhook واتساب
     */
    public function isRetryableFailure(array $errors): bool
    {
        foreach ($errors as $error) {
            $code = (int) ($error['code'] ?? 0);
            if (in_array($code, self::NON_RETRYABLE_ERROR_CODES, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * يعالج بلاغ الفشل الذي يصل من webhook واتساب بعد قبول الطلب بنجاح.
     *
     * هذا هو المسار الذي يمثّل ٩٩٪ من فشل الحملات فعلياً: الطلب يُقبل فيُسجَّل
     * campaign_logs.status = success، ثم يصل بلاغ الفشل لاحقاً فيُحدَّث chats.status
     * وحده. بدون هذه الدالة يبقى السجل "success" فلا يراه زر إعادة الإرسال ولا
     * تُجدوَل له إعادة محاولة تلقائية.
     *
     * الفشل النهائي (رقم غير مسجّل، مستلم رافض) لا يُعلَّم كـ failed: يبقى ظاهراً
     * للعميل ضمن عدّاد الفاشل عبر chats.status، لكنه لا يدخل دورة إعادة الإرسال.
     *
     * @param  array<int, array<string, mixed>>  $errors
     */
    public function handleWebhookFailure(int $chatId, array $errors = []): void
    {
        if (!$this->isRetryableFailure($errors)) {
            return;
        }

        $log = CampaignLog::with('campaign.organization')
            ->where('chat_id', $chatId)
            ->first();

        // ليست رسالة حملة، أو عولجت بالفعل، أو الحملة محذوفة.
        // Campaign لا يستخدم SoftDeletes فلا يوجد scope يحجب المحذوفة تلقائياً.
        if (!$log || !$log->campaign || $log->status === 'failed') {
            return;
        }

        if ($log->campaign->getRawOriginal('deleted_at') !== null) {
            return;
        }

        $log->status = 'failed';
        $log->save();

        $settings = $this->getCampaignRetrySettings($log->campaign);
        if (!$settings['enabled'] || $settings['max_retries'] <= 0) {
            return;
        }

        // بلاغ الفشل يصل غالباً بعد أن تُغلق الحملة، و scheduleNextRetry يشترط
        // أن تكون الحملة ongoing — فنعيد فتحها كما يفعل زر إعادة الإرسال اليدوي.
        if ($log->campaign->status === 'completed') {
            $log->campaign->status = 'ongoing';
            $log->campaign->save();
            $log->setRelation('campaign', $log->campaign->fresh());
        }

        if (!$this->scheduleNextRetry($log)) {
            $this->moveContactToFailedGroup($log);
        }
    }

    /**
     * Schedule the next retry attempt for a failed campaign log.
     *
     * Uses ->afterCommit() because the queue connection is non-transactional
     * (e.g. redis/sqs); without this guard the job could execute before the
     * caller's DB transaction commits and read stale state.
     */
    public function scheduleNextRetry(CampaignLog $log): bool
    {
        $log = CampaignLog::with('campaign.organization', 'retries')->find($log->id);
        if (!$log || !$log->campaign || $log->status !== 'failed') {
            return false;
        }

        if (!$this->isCampaignLive($log->campaign)) {
            return false;
        }

        $settings = $this->getCampaignRetrySettings($log->campaign);
        if (!$settings['enabled'] || $settings['max_retries'] <= 0) {
            return false;
        }

        $retryCount = $log->retries->count();
        if ($retryCount >= $settings['max_retries']) {
            return false;
        }

        $delayHours = $settings['intervals'][$retryCount] ?? null;
        if (!$delayHours) {
            return false;
        }

        // حملة فيها آلاف الرسائل الفاشلة تُجدول آلاف الإعادات على نفس اللحظة،
        // فتنطلق دفعة واحدة وتضرّ تقييم جودة الرقم. ننشرها على نافذة قصيرة
        // بمعرّف السجل (موزّع بالتساوي وثابت، فلا يتغيّر عند إعادة الجدولة).
        $spreadSeconds = $log->id % self::RETRY_SPREAD_SECONDS;

        RetryCampaignLogJob::dispatch($log->campaign->organization_id, $log->id, $retryCount)
            ->onQueue('campaign-messages')
            ->delay(now()->addHours($delayHours)->addSeconds($spreadSeconds))
            ->afterCommit();

        return true;
    }

    /**
     * Determine the next attempt slot for a campaign log send.
     * First send uses attempt #1; manual resends and re-queued failures use #2+.
     *
     * @return array{number: int, is_retry: bool}
     */
    public function resolveAttemptNumber(CampaignLog $log, string $channel = 'whatsapp'): array
    {
        $max = CampaignMessageAttempt::where('campaign_log_id', $log->id)
            ->where('channel', $channel)
            ->max('attempt_number');

        $number = ((int) $max) + 1;

        return [
            'number' => $number,
            'is_retry' => $number > 1,
        ];
    }

    /**
     * Atomically reserve an attempt slot before performing the external send.
     *
     * Relies on the unique index on (campaign_log_id, channel, attempt_number)
     * in the campaign_message_attempts table. Returns the freshly created row,
     * or null when another worker already claimed this attempt (i.e. a Laravel
     * job retry of an already-sent message). Callers MUST treat null as
     * "do NOT send again".
     */
    public function claimAttempt(
        CampaignLog $log,
        int $attemptNumber,
        bool $isRetry,
        string $channel = 'whatsapp'
    ): ?CampaignMessageAttempt {
        try {
            return CampaignMessageAttempt::create([
                'campaign_id' => $log->campaign_id,
                'campaign_log_id' => $log->id,
                'contact_id' => $log->contact_id,
                'channel' => $channel,
                'attempt_number' => $attemptNumber,
                'is_retry' => $isRetry,
                'status' => 'ongoing',
                'executed_at' => now(),
            ]);
        } catch (QueryException $e) {
            // 23000 / 1062 == unique constraint violation -> already claimed
            if ($this->isUniqueViolation($e)) {
                return null;
            }
            throw $e;
        }
    }

    /**
     * Persist the final outcome of a previously claimed attempt row.
     */
    public function finalizeAttempt(
        CampaignMessageAttempt $attempt,
        string $status,
        ?string $failureReason,
        $responseObject
    ): void {
        $attempt->status = $status;
        $attempt->failure_reason = $failureReason;
        $attempt->response_metadata = $responseObject ? json_encode($responseObject) : null;
        $attempt->executed_at = now();
        $attempt->save();
    }

    /**
     * Backwards-compatible upsert. Used as a fallback when no claim row exists
     * (e.g. inside the failed() handler after the queue has exhausted retries).
     */
    public function recordAttempt(
        CampaignLog $log,
        int $attemptNumber,
        string $status,
        ?string $failureReason,
        $responseObject,
        bool $isRetry,
        string $channel = 'whatsapp'
    ): void {
        CampaignMessageAttempt::updateOrCreate(
            [
                'campaign_log_id' => $log->id,
                'channel' => $channel,
                'attempt_number' => $attemptNumber,
            ],
            [
                'campaign_id' => $log->campaign_id,
                'contact_id' => $log->contact_id,
                'is_retry' => $isRetry,
                'status' => $status,
                'failure_reason' => $failureReason,
                'response_metadata' => $responseObject ? json_encode($responseObject) : null,
                'executed_at' => now(),
            ]
        );
    }

    public function extractFailureReason($responseObject): string
    {
        if (!$responseObject) {
            return 'Unknown API error';
        }

        $error = $responseObject->data->error ?? $responseObject->error ?? null;
        if (!$error) {
            return 'Unknown API error';
        }

        return $error->error_user_msg
            ?? $error->message
            ?? $error->error_user_title
            ?? 'Unknown API error';
    }

    public function moveContactToFailedGroup(CampaignLog $log): void
    {
        $log = CampaignLog::with('campaign.organization')->find($log->id);
        if (!$log || !$log->campaign) {
            return;
        }

        $settings = $this->getCampaignRetrySettings($log->campaign);
        if (!$settings['move_failed_contacts_to_group'] || empty($settings['failed_campaign_group'])) {
            return;
        }

        $failedGroupId = DB::table('contact_groups')
            ->where('uuid', $settings['failed_campaign_group'])
            ->value('id');

        if (!$failedGroupId) {
            Log::warning('Failed to move contact after campaign retries: group not found.', [
                'campaign_log_id' => $log->id,
                'group_uuid' => $settings['failed_campaign_group'],
            ]);
            return;
        }

        DB::transaction(function () use ($log, $failedGroupId) {
            DB::table('contact_contact_group')
                ->where('contact_id', $log->contact_id)
                ->delete();

            DB::table('contact_contact_group')->insert([
                'contact_id' => $log->contact_id,
                'contact_group_id' => $failedGroupId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    private function isUniqueViolation(Throwable $e): bool
    {
        $sqlState = $e->getCode();
        if ($sqlState === '23000' || $sqlState === 23000) {
            return true;
        }

        $driverCode = $e instanceof QueryException
            ? ($e->errorInfo[1] ?? null)
            : null;

        return in_array($driverCode, [1062, 19], true);
    }
}
