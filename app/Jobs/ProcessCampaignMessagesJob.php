<?php

namespace App\Jobs;

use App\Jobs\ProcessSingleCampaignLogJob;
use App\Models\Campaign;
use App\Models\CampaignLog;
use App\Services\CampaignRetryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

class ProcessCampaignMessagesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 3600;
    public $tries = 1;

    /**
     * Logs left in "ongoing" longer than this are considered orphaned by a
     * crashed worker and are reset to "failed" so the retry pipeline can
     * resume them. Must be larger than ProcessSingleCampaignLogJob::$timeout
     * (300s) plus any reasonable slack for queue latency.
     */
    private const STUCK_ONGOING_MINUTES = 15;

    public function handle()
    {
        try {
            // Self-heal: any log stuck in "ongoing" past the timeout window
            // belongs to a worker that died mid-handle. Mark it failed so the
            // retry chain can pick it up. The atomic claim in
            // ProcessSingleCampaignLogJob guarantees the original send did
            // NOT actually duplicate even if the row state was lost.
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

            CampaignLog::with(['campaign.organization', 'contact'])
                ->where('status', 'pending')
                ->whereHas('campaign', function ($query) {
                    $query->where('status', 'ongoing')
                        ->whereNull('deleted_at');
                })
                ->chunk(1000, function ($logs) {
                    $jobs = [];
                    $campaignsProcessed = [];

                    foreach ($logs as $log) {
                        $jobs[] = new ProcessSingleCampaignLogJob($log);

                        if (!in_array($log->campaign_id, $campaignsProcessed)) {
                            $campaignsProcessed[] = $log->campaign_id;
                        }
                    }

                    if (!empty($jobs)) {
                        Bus::batch($jobs)
                            ->allowFailures()
                            ->onQueue('campaign-messages')
                            ->dispatch();
                    }

                    foreach ($campaignsProcessed as $campaignId) {
                        $this->markCampaignAsCompleted($campaignId);
                    }
                });
        } catch (\Exception $e) {
            Log::error('Error in ProcessCampaignMessagesJob: ' . $e->getMessage());
            throw $e;
        }
    }

    private function markCampaignAsCompleted($campaignId)
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
