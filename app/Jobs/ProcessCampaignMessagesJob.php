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

    public function handle()
    {
        try {
			$time = microtime(true);
            // Process logs in chunks to avoid memory issues
            CampaignLog::with(['campaign.organization', 'contact'])
                ->where('status', 'pending')
                ->whereHas('campaign', function ($query) {
                    $query->where('status', 'ongoing');
                })
                ->chunk(1000, function ($logs)  {
                    $jobs = [];
                    $campaignsProcessed = [];

                    // Process logs and collect jobs
                    foreach ($logs as $log) {
                        $jobs[] = new ProcessSingleCampaignLogJob($log);

                        // Track which campaigns have been processed
                        if (!in_array($log->campaign_id, $campaignsProcessed)) {
                            $campaignsProcessed[] = $log->campaign_id;
                        }
                    }

                    // Dispatch jobs in batches
                    if (!empty($jobs)) {
                        // Log::info('ProcessCampaignMessagesJob: dispatching batch', [
                        //     'logsInChunk' => count($logs),
                        //     'jobsDispatched' => count($jobs),
                        // ]);
                        Bus::batch($jobs)
                            ->allowFailures()
                            ->onQueue('campaign-messages')
                            ->dispatch();
                    }

                    // After processing logs, mark campaigns as completed if needed
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
