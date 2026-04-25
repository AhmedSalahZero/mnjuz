<?php

namespace App\Services;

use App\Jobs\RetryCampaignLogJob;
use App\Models\Campaign;
use App\Models\CampaignLog;
use App\Models\CampaignMessageAttempt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CampaignRetryService
{
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

    public function scheduleNextRetry(CampaignLog $log): bool
    {
        $log = CampaignLog::with('campaign.organization', 'retries')->find($log->id);
        if (!$log || !$log->campaign || $log->status !== 'failed') {
            return false;
        }

        if ($log->campaign->status !== 'ongoing') {
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

        RetryCampaignLogJob::dispatch($log->campaign->organization_id, $log->id, $retryCount)
            ->onQueue('campaign-messages')
            ->delay(now()->addHours($delayHours));

        return true;
    }

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

        DB::table('contact_contact_group')
            ->where('contact_id', $log->contact_id)
            ->delete();

        DB::table('contact_contact_group')->insert([
            'contact_id' => $log->contact_id,
            'contact_group_id' => $failedGroupId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
