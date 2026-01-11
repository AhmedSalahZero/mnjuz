<?php

namespace App\Jobs;

use Carbon\Carbon;
use App\Models\Campaign;
use App\Models\CampaignLog;
use App\Models\Contact;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CreateCampaignLogsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 3600;
    public $tries = 3;

    public function handle()
    {
        try {
            $campaigns = Campaign::where('status', 'scheduled')
                ->with('organization')
                ->whereNull('deleted_at')
                ->cursor();

            foreach ($campaigns as $campaign) {
                $timezone = $this->getOrganizationTimezone($campaign->organization);
                $scheduledAt = Carbon::parse($campaign->scheduled_at, 'UTC')->timezone($timezone);

                if ($scheduledAt->lte(Carbon::now($timezone))) {
                    $this->processCampaign($campaign);
                }
            }
        } catch (\Exception $e) {
            Log::error('Error in CreateCampaignLogsJob: ' . $e->getMessage());
            throw $e;
        }
    }

    protected function getOrganizationTimezone($organization)
    {
        if (!$organization) return 'UTC';

        $metadata = $organization->metadata;
        $metadata = isset($metadata) ? json_decode($metadata, true) : null;

        return $metadata['timezone'] ?? 'UTC';
    }

    protected function processCampaign(Campaign $campaign)
    {
        $contacts = $this->getContactsForCampaign($campaign);
        
        if ($this->createCampaignLogs($campaign, $contacts)) {
            Campaign::where('uuid', $campaign->uuid)->update(['status' => 'ongoing']);
        }
    }

    protected function getContactsForCampaign(Campaign $campaign)
    {
        if (empty($campaign->contact_group_id) || $campaign->contact_group_id === '0') {
            return Contact::where('organization_id', $campaign->organization_id)
                ->whereNull('deleted_at')
                ->get();
        }

        return Contact::whereHas('contactGroups', function ($query) use ($campaign) {
            $query->where('contact_groups.id', $campaign->contact_group_id);
        })->whereNull('deleted_at')->get();
    }

    protected function createCampaignLogs(Campaign $campaign, $contacts)
    {
        $contactIds = $contacts->pluck('id');

        // Fetch existing logs
        $existingLogs = CampaignLog::where('campaign_id', $campaign->id)
            ->whereIn('contact_id', $contactIds)
            ->pluck('contact_id')
            ->toArray();

        // Filter out contacts that already have logs
        $newContacts = $contactIds->diff($existingLogs);

        // Prepare new campaign logs
        $campaignLogs = $newContacts->map(function ($contactId) use ($campaign) {
            return [
                'campaign_id' => $campaign->id,
                'contact_id' => $contactId,
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        })->toArray();

        // Insert new logs if any
        if (!empty($campaignLogs)) {
            return CampaignLog::insert($campaignLogs);
        }

        return false;
    }
}