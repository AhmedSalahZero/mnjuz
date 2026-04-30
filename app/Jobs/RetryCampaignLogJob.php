<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Models\CampaignLog;
use App\Models\CampaignLogRetry;
use App\Models\CampaignMessageAttempt;
use App\Models\Organization;
use App\Services\CampaignRetryService;
use App\Services\WhatsappService;
use App\Traits\TemplateTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class RetryCampaignLogJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TemplateTrait;

    public $timeout = 300;
    public $tries = 3;

    /**
     * TTL (seconds) for the ShouldBeUnique lock. Without this, a worker
     * crashing mid-handle could leak the lock forever and silently block
     * any future retry of this attempt.
     */
    public $uniqueFor = 3600;

    private $organizationId;
    private $campaignLogId;
    protected $retryIndex;
    private $whatsappService;

    public function __construct(int $organizationId, int $campaignLogId, int $retryIndex)
    {
        $this->organizationId = $organizationId;
        $this->campaignLogId = $campaignLogId;
        $this->retryIndex = $retryIndex;
    }

    public function uniqueId()
    {
        return $this->campaignLogId . '-' . $this->retryIndex;
    }

    public function handle(): void
    {
        $retryService = app(CampaignRetryService::class);
        $attemptNumber = $this->retryIndex + 2; // attempt 1 was the initial send

        // Phase 1 (TX): validate state, claim attempt slot, create retry row.
        $claim = DB::transaction(function () use ($retryService, $attemptNumber) {
            $log = CampaignLog::with('campaign', 'contact')
                ->where('id', $this->campaignLogId)
                ->lockForUpdate()
                ->first();

            if (!$log || !$log->contact || $log->status !== 'failed') {
                return null;
            }

            // Campaign deleted or completed mid-flight: stop the chain.
            if (!$log->campaign || !empty($log->campaign->deleted_at)) {
                return null;
            }

            // Campaign currently paused (any non-ongoing, non-terminal state).
            // Reschedule rather than dropping the retry.
            if ($log->campaign->status !== 'ongoing') {
                if (in_array($log->campaign->status, ['scheduled', 'paused'], true)) {
                    return ['reschedule' => true];
                }
                return null;
            }

            $settings = $retryService->getCampaignRetrySettings($log->campaign);

            // Feature toggle was turned OFF mid-flight: stop sending retries
            // for this log. Do not advance attempt counters.
            if (!$settings['enabled']) {
                return ['stop' => true, 'campaign_id' => $log->campaign_id];
            }

            $existingRetries = $log->retries()->count();

            // Idempotency: if this exact retry index already executed, skip.
            if ($existingRetries !== $this->retryIndex) {
                return ['stop' => true, 'campaign_id' => $log->campaign_id];
            }

            if ($existingRetries >= $settings['max_retries']) {
                return ['stop' => true, 'campaign_id' => $log->campaign_id];
            }

            $attempt = $retryService->claimAttempt($log, $attemptNumber, true);
            if (!$attempt) {
                // Already claimed by a previous (possibly half-completed) run.
                return ['stop' => true, 'campaign_id' => $log->campaign_id];
            }

            $retryLog = new CampaignLogRetry();
            $retryLog->campaign_log_id = $this->campaignLogId;
            $retryLog->status = 'ongoing';
            $retryLog->save();

            return [
                'log_id' => $log->id,
                'campaign_id' => $log->campaign_id,
                'organization_id' => $log->campaign->organization_id,
                'contact_uuid' => $log->contact->uuid,
                'created_by' => $log->campaign->created_by,
                'attempt_id' => $attempt->id,
                'retry_log_id' => $retryLog->id,
            ];
        });

        if ($claim === null) {
            return;
        }

        if (!empty($claim['reschedule'])) {
            // Re-queue this exact retry with a small delay so paused campaigns
            // can resume cleanly without losing scheduled retries.
            self::dispatch($this->organizationId, $this->campaignLogId, $this->retryIndex)
                ->onQueue('campaign-messages')
                ->delay(now()->addMinutes(15))
                ->afterCommit();
            return;
        }

        if (!empty($claim['stop'])) {
            $this->markCampaignAsCompletedIfDone($claim['campaign_id'] ?? null, $retryService);
            return;
        }

        // Phase 2 (no TX, no row lock): external WhatsApp call.
        $responseObject = null;
        $sendError = null;

        try {
            $this->organizationId = $claim['organization_id'];
            $this->initializeWhatsappService();

            $log = CampaignLog::with('contact')->find($claim['log_id']);
            $template = $this->buildTemplateRequest(
                $claim['campaign_id'],
                $log->contact
            );

            $responseObject = $this->whatsappService->sendTemplateMessage(
                $log->contact->uuid,
                $template,
                $claim['created_by'],
                $claim['campaign_id']
            );
        } catch (Throwable $e) {
            $sendError = $e;
            Log::error('RetryCampaignLogJob: send threw', [
                'campaign_log_id' => $claim['log_id'],
                'retry_index' => $this->retryIndex,
                'error' => $e->getMessage(),
            ]);
        }

        // Phase 3 (TX): persist outcome (success OR failure).
        $finalStatus = DB::transaction(function () use ($claim, $responseObject, $sendError, $retryService) {
            $log = CampaignLog::lockForUpdate()->find($claim['log_id']);
            $attempt = CampaignMessageAttempt::lockForUpdate()->find($claim['attempt_id']);
            $retryLog = CampaignLogRetry::lockForUpdate()->find($claim['retry_log_id']);

            if (!$log || !$attempt || !$retryLog) {
                return null;
            }

            if ($sendError !== null || $responseObject === null) {
                $reason = $sendError
                    ? 'Send threw: ' . $sendError->getMessage()
                    : 'No response from WhatsApp service';

                $retryLog->status = 'failed';
                $retryLog->metadata = json_encode(['error' => $reason]);
                $retryLog->save();

                $log->retry_count = ($log->retry_count ?? 0) + 1;
                $log->status = 'failed';
                $log->save();

                $retryService->finalizeAttempt($attempt, 'failed', $reason, null);

                return 'failed';
            }

            $status = ($responseObject->success === true) ? 'success' : 'failed';
            $retryLog->chat_id = $responseObject->data->chat->id ?? null;
            $retryLog->status = $status;

            unset($responseObject->success);
            if (property_exists($responseObject, 'data') && property_exists($responseObject->data, 'chat')) {
                unset($responseObject->data->chat);
            }

            $retryLog->metadata = json_encode($responseObject);
            $retryLog->save();

            $log->retry_count = ($log->retry_count ?? 0) + 1;
            $log->status = $status;
            $log->chat_id = $retryLog->chat_id;
            $log->metadata = $retryLog->metadata;
            $log->save();

            $failureReason = $status === 'failed'
                ? $retryService->extractFailureReason($responseObject)
                : null;

            $retryService->finalizeAttempt($attempt, $status, $failureReason, $responseObject);

            return $status;
        });

        // Phase 4 (post-commit): schedule next retry or close out the campaign.
        if ($finalStatus === 'failed') {
            $log = CampaignLog::with('campaign')->find($claim['log_id']);
            if ($log && !$retryService->scheduleNextRetry($log)) {
                $retryService->moveContactToFailedGroup($log);
            }
        }

        $this->markCampaignAsCompletedIfDone($claim['campaign_id'], $retryService);
    }

    public function failed(Throwable $exception): void
    {
        // Reached after Laravel's $tries are exhausted. The atomic claim has
        // already prevented duplicate sends; here we ensure:
        //  1. the failure is recorded for the user
        //  2. the next retry is still scheduled (if eligible) so a transient
        //     infrastructure issue doesn't kill the entire retry chain.
        $log = CampaignLog::with('campaign')->find($this->campaignLogId);
        if (!$log) {
            return;
        }

        $retryService = app(CampaignRetryService::class);

        $retryService->recordAttempt(
            $log,
            $this->retryIndex + 2,
            'failed',
            'Queue job failed: ' . $exception->getMessage(),
            null,
            true
        );

        if ($log->status === 'ongoing') {
            $log->status = 'failed';
            $log->save();
        }

        if ($log->status === 'failed') {
            $retryService->scheduleNextRetry($log);
        }
    }

    private function initializeWhatsappService(): void
    {
        $organization = Organization::find($this->organizationId);
        $config = $organization?->metadata
            ? json_decode($organization->metadata, true)
            : [];

        $accessToken = $config['whatsapp']['access_token'] ?? null;
        $apiVersion = 'v18.0';
        $appId = $config['whatsapp']['app_id'] ?? null;
        $phoneNumberId = $config['whatsapp']['phone_number_id'] ?? null;
        $wabaId = $config['whatsapp']['waba_id'] ?? null;

        $this->whatsappService = new WhatsappService(
            $accessToken,
            $apiVersion,
            $appId,
            $phoneNumberId,
            $wabaId,
            $this->organizationId
        );
    }

    private function markCampaignAsCompletedIfDone(?int $campaignId, CampaignRetryService $retryService): void
    {
        if (!$campaignId) {
            return;
        }

        $campaign = Campaign::find($campaignId);
        if (!$campaign || $campaign->status !== 'ongoing') {
            return;
        }

        $hasPendingOrOngoing = CampaignLog::where('campaign_id', $campaignId)
            ->whereIn('status', ['pending', 'ongoing'])
            ->exists();

        if ($hasPendingOrOngoing || $retryService->hasRetryableFailures($campaign)) {
            return;
        }

        $campaign->status = 'completed';
        $campaign->save();
    }
}
