<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Models\CampaignLog;
use App\Services\CampaignRetryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
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

    public function handle(): void
    {
        try {
            $this->resetStuckOngoingLogs();

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

    private function resetStuckOngoingLogs(): void
    {
        $stuckCount = CampaignLog::where('status', 'ongoing')
            ->where('updated_at', '<', now()->subMinutes(self::STUCK_ONGOING_MINUTES))
            ->update([
                'status' => 'failed',
                'updated_at' => now(),
            ]);

        if ($stuckCount > 0) {
            Log::warning('ProcessCampaignMessagesJob: reset stuck ongoing logs', [
                'count' => $stuckCount,
            ]);
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
