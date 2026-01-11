<?php

namespace App\Jobs;

use App\Jobs\ProcessSingleCampaignLogJob;
use App\Models\Campaign;
use App\Models\CampaignLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Bus;

class ProcessCampaignMessagesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    
    public $timeout = 3600;
    public $tries = 3;

    public function handle()
    {
        try {
            // Process logs in chunks to avoid memory issues
            CampaignLog::with(['campaign.organization', 'contact'])
                ->whereIn('status', ['pending', 'failed'])
                ->whereHas('campaign', function ($query) {
                    $query->where('status', 'ongoing');
                })
                ->chunk(1000, function ($logs) {
                    $jobs = [];
                    $campaignsProcessed = [];

                    // Process logs and collect jobs
                    foreach ($logs as $log) {
                        $orgMetadata = json_decode($log->campaign->organization->metadata ?? '{}', true);
                        $retryEnabled = $orgMetadata['campaigns']['enable_resend'] ?? [];
                        $retryIntervals = $orgMetadata['campaigns']['resend_intervals'] ?? [];
                        $maxRetries = count($retryIntervals);

                        // Filter the logs based on the status and retry logic
                        if ($log->status === 'pending' || ($log->status === 'failed' && $log->retry_count < $maxRetries && $retryEnabled === true)) {
                            $jobs[] = new ProcessSingleCampaignLogJob($log);
                        }

                        // Track which campaigns have been processed
                        if (!in_array($log->campaign_id, $campaignsProcessed)) {
                            $campaignsProcessed[] = $log->campaign_id;
                        }
                    }

                    // Dispatch jobs in batches
                    if (!empty($jobs)) {
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
        $orgMetadata = json_decode($campaign->organization->metadata ?? '{}', true);
        $retryEnabled = $orgMetadata['campaigns']['enable_resend'] ?? [];
        $retryIntervals = $orgMetadata['campaigns']['resend_intervals'] ?? [];
        $maxRetries = count($retryIntervals);

        // Check if there are any pending logs left for this campaign
        $failedLogsExists = false;

        if($retryEnabled){
            // Check if there are any failed logs left for this campaign that have not reached max retry attempts
            $failedLogs = CampaignLog::where('campaign_id', $campaignId)
                ->where('status', 'failed')
                ->where(function ($query) use ($maxRetries) {
                    // Check if the campaign is ongoing
                    $query->whereHas('campaign', function ($subQuery) {
                        $subQuery->where('status', 'ongoing');
                    });
                })
                ->get();

            foreach ($failedLogs as $log) {
                if($log->retries->count() < $maxRetries){
                    $failedLogsExists = true;
                    break;
                }
            }
        }

        if (!$failedLogsExists) {
            // No more pending/failed logs, mark the campaign as completed
            $campaign = Campaign::find($campaignId);
            if ($campaign && $campaign->status === 'ongoing') {
                $campaign->status = 'completed';
                $campaign->save();
            }
        }
    }
}