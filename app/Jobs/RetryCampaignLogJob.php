<?php

namespace App\Jobs;

use App\Models\CampaignLog;
use App\Models\CampaignLogRetry;
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

    public function handle()
    {
        $retryService = app(CampaignRetryService::class);

        try {
            DB::transaction(function () use ($retryService) {
                $log = CampaignLog::with('campaign', 'campaign.organization', 'contact')
                    ->lockForUpdate()
                    ->find($this->campaignLogId);

                if (!$log || !$log->campaign || !$log->contact || $log->status !== 'failed') {
                    return;
                }

                if ($log->campaign->status !== 'ongoing') {
                    return;
                }

                $settings = $retryService->getCampaignRetrySettings($log->campaign);
                if (!$settings['enabled']) {
                    $this->markCampaignAsCompletedIfDone($log->campaign_id, $retryService);
                    return;
                }

                $retryCount = $log->retries()->count();
                if ($retryCount !== $this->retryIndex || $retryCount >= $settings['max_retries']) {
                    $this->markCampaignAsCompletedIfDone($log->campaign_id, $retryService);
                    return;
                }

                $this->initializeWhatsappService();

                $retryLog = new CampaignLogRetry();
                $retryLog->campaign_log_id = $this->campaignLogId;
                $retryLog->status = 'ongoing';
                $retryLog->save();

                $template = $this->buildTemplateRequest($log->campaign_id, $log->contact);
                $campaignUserId = $log->campaign->created_by;

                $response = $this->whatsappService->sendTemplateMessage(
                    $log->contact->uuid,
                    $template,
                    $campaignUserId,
                    $log->campaign_id
                );

                $status = ($response->success === true) ? 'success' : 'failed';
                $retryLog->chat_id = $response->data->chat->id ?? null;
                $retryLog->status = $status;

                unset($response->success);
                if (property_exists($response, 'data') && property_exists($response->data, 'chat')) {
                    unset($response->data->chat);
                }

                $retryLog->metadata = json_encode($response);
                $retryLog->save();

                $log->retry_count = $retryCount + 1;
                $log->status = $status;
                $log->chat_id = $retryLog->chat_id;
                $log->metadata = $retryLog->metadata;
                $log->save();

                $attemptNumber = $this->retryIndex + 2;
                $failureReason = $status === 'failed'
                    ? $retryService->extractFailureReason($response)
                    : null;

                $retryService->recordAttempt(
                    $log,
                    $attemptNumber,
                    $status,
                    $failureReason,
                    $response,
                    true
                );

                if ($status === 'failed') {
                    if (!$retryService->scheduleNextRetry($log)) {
                        $retryService->moveContactToFailedGroup($log);
                    }
                }

                $this->markCampaignAsCompletedIfDone($log->campaign_id, $retryService);
            });
        } catch (Throwable $e) {
            Log::error("Retry failed for campaign_log {$this->campaignLogId}: " . $e->getMessage(), [
                'campaign_log_id' => $this->campaignLogId,
                'retry_index' => $this->retryIndex,
            ]);

            throw $e;
        }
    }

    private function initializeWhatsappService()
    {
        $config = cache()->remember("organization.{$this->organizationId}.metadata", 3600, function() {
            return Organization::find($this->organizationId)->metadata ?? [];
        });

        $config = Organization::where('id', $this->organizationId)->first()->metadata;
        $config = $config ? json_decode($config, true) : [];

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

    public function failed(Throwable $exception): void
    {
        $log = CampaignLog::with('campaign')->find($this->campaignLogId);
        if (!$log) {
            return;
        }

        app(CampaignRetryService::class)->recordAttempt(
            $log,
            $this->retryIndex + 2,
            'failed',
            'Queue job failed: ' . $exception->getMessage(),
            null,
            true
        );
    }

    private function markCampaignAsCompletedIfDone(int $campaignId, CampaignRetryService $retryService): void
    {
        $campaign = \App\Models\Campaign::find($campaignId);
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
