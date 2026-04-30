<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Models\CampaignLog;
use App\Models\CampaignMessageAttempt;
use App\Models\Organization;
use App\Services\CampaignRetryService;
use App\Services\WhatsappService;
use App\Traits\TemplateTrait;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessSingleCampaignLogJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TemplateTrait, Batchable;

    private $campaignLog;
    private $organizationId;
    private $whatsappService;

    public $timeout = 300;
    public $tries = 3;

    public function __construct(CampaignLog $campaignLog)
    {
        $this->campaignLog = $campaignLog;
    }

    public function handle()
    {
        $retryService = app(CampaignRetryService::class);

        // Phase 1 (TX): re-fetch with row lock, validate state, and atomically
        // claim the attempt slot. The unique index on
        // (campaign_log_id, channel, attempt_number) makes the claim safe
        // against concurrent workers AND Laravel's automatic job retries.
        $claim = DB::transaction(function () use ($retryService) {
            $log = CampaignLog::with('campaign')
                ->where('id', $this->campaignLog->id)
                ->lockForUpdate()
                ->first();

            if (!$log || $log->status !== 'pending') {
                return null;
            }

            if (!$retryService->isCampaignLive($log->campaign)) {
                return null;
            }

            // Refresh contact relation safely (it might have been deleted).
            $contact = $log->contact;
            if (!$contact) {
                $log->status = 'failed';
                $log->save();

                $retryService->recordAttempt(
                    $log,
                    1,
                    'failed',
                    'Contact missing or deleted',
                    null,
                    false
                );

                return null;
            }

            $attempt = $retryService->claimAttempt($log, 1, false);
            if (!$attempt) {
                // Another worker / a previous run of this job already claimed
                // attempt #1. Do not re-send.
                return null;
            }

            $log->status = 'ongoing';
            $log->save();

            return [
                'log_id' => $log->id,
                'attempt_id' => $attempt->id,
                'campaign_id' => $log->campaign_id,
                'organization_id' => $log->campaign->organization_id,
                'contact_uuid' => $contact->uuid,
                'created_by' => $log->campaign->created_by,
            ];
        });

        if ($claim === null) {
            return;
        }

        // Phase 2 (no TX, no row lock): perform the external WhatsApp call.
        // Holding row locks during multi-second HTTP requests would block
        // every other worker and risks partial commits on timeout.
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
            $this->markAttemptFailed(
                $claim['attempt_id'],
                $claim['log_id'],
                'Send failed: ' . $e->getMessage()
            );

            Log::error('ProcessSingleCampaignLogJob: send threw', [
                'campaign_log_id' => $claim['log_id'],
                'error' => $e->getMessage(),
            ]);

            // Do NOT rethrow: a thrown exception here would tell Laravel to
            // re-run the job, which would now be blocked by the unique claim
            // and would not recover. Schedule a business retry instead.
            $this->scheduleRetryAfterFailure($claim['log_id'], $retryService);
            return;
        }

        // Phase 3 (TX): persist outcome.
        DB::transaction(function () use ($claim, $responseObject, $retryService) {
            $log = CampaignLog::lockForUpdate()->find($claim['log_id']);
            $attempt = CampaignMessageAttempt::lockForUpdate()->find($claim['attempt_id']);
            if (!$log || !$attempt) {
                return;
            }

            $status = ($responseObject->success === true) ? 'success' : 'failed';
            $log->chat_id = $responseObject->data->chat->id ?? null;
            $log->status = $status;

            // Strip transient response fields before persisting.
            unset($responseObject->success);
            if (property_exists($responseObject, 'data') && property_exists($responseObject->data, 'chat')) {
                unset($responseObject->data->chat);
            }

            $log->metadata = json_encode($responseObject);
            $log->updated_at = now();
            $log->save();

            $failureReason = $status === 'failed'
                ? $retryService->extractFailureReason($responseObject)
                : null;

            $retryService->finalizeAttempt($attempt, $status, $failureReason, $responseObject);
        });

        // Phase 4 (post-commit): schedule next retry / mark campaign complete.
        $finalLog = CampaignLog::with('campaign')->find($claim['log_id']);
        if ($finalLog && $finalLog->status === 'failed') {
            $retryService->scheduleNextRetry($finalLog);
        }

        $this->checkAndUpdateCampaignStatus($claim['campaign_id'], $retryService);
    }

    private function markAttemptFailed(int $attemptId, int $logId, string $reason): void
    {
        try {
            DB::transaction(function () use ($attemptId, $logId, $reason) {
                $attempt = CampaignMessageAttempt::lockForUpdate()->find($attemptId);
                if ($attempt) {
                    $attempt->status = 'failed';
                    $attempt->failure_reason = $reason;
                    $attempt->executed_at = now();
                    $attempt->save();
                }

                $log = CampaignLog::lockForUpdate()->find($logId);
                if ($log) {
                    $log->status = 'failed';
                    $log->save();
                }
            });
        } catch (Throwable $e) {
            Log::error('ProcessSingleCampaignLogJob: failed to mark attempt failed', [
                'attempt_id' => $attemptId,
                'log_id' => $logId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function scheduleRetryAfterFailure(int $logId, CampaignRetryService $retryService): void
    {
        try {
            $log = CampaignLog::with('campaign')->find($logId);
            if ($log && $log->status === 'failed') {
                $retryService->scheduleNextRetry($log);
            }
        } catch (Throwable $e) {
            Log::error('ProcessSingleCampaignLogJob: failed to schedule retry', [
                'log_id' => $logId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function failed(Throwable $exception): void
    {
        // Last-resort safety net for any unexpected throw that escapes handle().
        $log = CampaignLog::with('campaign')->find($this->campaignLog->id);
        if (!$log) {
            return;
        }

        $retryService = app(CampaignRetryService::class);
        $retryService->recordAttempt(
            $log,
            1,
            'failed',
            'Queue job failed: ' . $exception->getMessage(),
            null,
            false
        );

        if ($log->status === 'ongoing') {
            $log->status = 'failed';
            $log->save();
        }

        if ($log->status === 'failed') {
            $retryService->scheduleNextRetry($log);
        }
    }

    protected function checkAndUpdateCampaignStatus(int $campaignId, CampaignRetryService $retryService): void
    {
        $campaign = Campaign::find($campaignId);
        if (!$campaign || $campaign->status !== 'ongoing') {
            return;
        }

        $pendingOrOngoing = CampaignLog::where('campaign_id', $campaignId)
            ->whereIn('status', ['pending', 'ongoing'])
            ->exists();

        if ($pendingOrOngoing) {
            return;
        }

        if ($retryService->hasRetryableFailures($campaign)) {
            return;
        }

        $campaign->update(['status' => 'completed']);
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
}
