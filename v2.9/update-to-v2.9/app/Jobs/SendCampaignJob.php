<?php

namespace App\Jobs;

use Carbon\Carbon;
use App\Models\Campaign;
use App\Models\CampaignLog;
use App\Models\CampaignLogRetry;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Setting;
use App\Services\WhatsappService;
use App\Traits\HasUuid;
use App\Traits\TemplateTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SendCampaignJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, TemplateTrait, SerializesModels;

    private $organizationId;
    private $whatsappService;

    public function handle()
    {
        try {
            /*$timezoneQuery = Setting::where('key', 'timezone')->first();
            $timezone = $timezoneQuery ? $timezoneQuery->value : 'UTC';*/

            $campaigns = Campaign::whereIn('status', ['scheduled', 'ongoing'])
                ->with('organization') // Eager load the organization relationship
                ->whereNull('deleted_at')
                ->cursor();

            $campaigns->each(function ($campaign) {
                $organization = $campaign->organization;
                $timezone = 'UTC';

                if ($organization) {
                    $metadata = $organization->metadata;
                    $metadata = isset($metadata) ? json_decode($metadata, true) : null;

                    if ($metadata && isset($metadata['timezone'])) {
                        $timezone = $metadata['timezone'];
                    }
                }

                $scheduledAt = Carbon::parse($campaign->scheduled_at, 'UTC')->timezone($timezone);

                // Compare the scheduled_at time with the current time in the organization's timezone
                if ($scheduledAt->lte(Carbon::now($timezone))) {
                    $this->processCampaign($campaign);
                }
            });
        } catch (\Exception $e) {
            // Handle the exception, log it, or take other actions
            Log::error('Error in campaign job: ' . $e->getMessage());

            // Optionally, rethrow the exception if you want the job to be retried
            throw $e;
        }
    }

    protected function processCampaign(Campaign $campaign)
    {
        if ($campaign->status === 'scheduled') {
            $this->processPendingCampaign($campaign);
        } elseif ($campaign->status === 'ongoing') {
            $this->sendOngoingCampaignMessages($campaign);
        }
    }

    protected function processPendingCampaign(Campaign $campaign)
    {
        $contacts = $this->getContactsForCampaign($campaign);

        if($this->createCampaignLogs($campaign, $contacts)){
            if($this->updateCampaignStatus($campaign, 'ongoing')){
                $campaign = Campaign::find($campaign->id);
                $this->processCampaign($campaign);
            }
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
        $campaignLogs = [];
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
                'created_at' => now(),
            ];
        })->toArray();

        // Insert new logs if any
        if (!empty($campaignLogs)) {
            return CampaignLog::insert($campaignLogs);
        }

        return false;
    }

    protected function updateCampaignStatus(Campaign $campaign, $status)
    {
        return Campaign::where('uuid', $campaign->uuid)->update(['status' => $status]);
    }

    protected function sendOngoingCampaignMessages(Campaign $campaign)
    {
        $this->processPendingOrRetryableLogs($campaign);

        // Check if there are no more pending campaign logs
        if (!$this->hasPendingOrRetryableLogs($campaign)) {
            $this->updateCampaignStatus($campaign, 'completed');
        }
    }

    protected function processPendingOrRetryableLogs(Campaign $campaign)
    {
        $campaign = Campaign::with('organization')->find($campaign->id);
        $orgMetadata = json_decode($campaign->organization->metadata ?? '{}', true);
        $retryEnabled = $orgMetadata['campaigns']['enable_resend'] ?? false;
        $retryIntervals = $orgMetadata['campaigns']['resend_intervals'] ?? [];
        $maxRetries = count($retryIntervals);

        // Process pending logs
        CampaignLog::with('campaign', 'contact')
            ->where('campaign_id', $campaign->id)
            ->where('status', '=', 'pending')
            ->chunk(500, function ($pendingCampaignLogs) {
                foreach ($pendingCampaignLogs as $campaignLog) {
                    // Skip if the log is already being processed or processed
                    if ($campaignLog->status === 'ongoing' || $campaignLog->status === 'success') {
                        continue;
                    }
                    $this->sendTemplateMessage($campaignLog);
                }
            });

        // If retry is enabled, process eligible failed logs
        if ($retryEnabled && $maxRetries > 0) {
            CampaignLog::with(['campaign', 'contact', 'retries'])
                ->where('campaign_id', $campaign->id)
                ->where('status', 'failed')
                ->chunk(500, function ($logs) use ($retryIntervals, $maxRetries) {
                    foreach ($logs as $log) {
                        $retryCount = $log->retries->count();

                        // Skip if max retries have been reached
                        if ($retryCount >= $maxRetries) {
                            continue;
                        }

                        $requiredDelay = $retryIntervals[$retryCount] ?? null;

                        // Skip if there's a retry dispatch and it's not time yet
                        $lastRetryLog = $log->retries()->latest()->first(); // Assuming the relationship is defined

                        if ($lastRetryLog) {
                            $nextEligibleTime = \Carbon\Carbon::parse($lastRetryLog->created_at)->addHours($requiredDelay);
                            if (now()->lt($nextEligibleTime)) {
                                continue; // Retry time not reached yet
                            }
                        }

                        // If the last retry or dispatched time is valid, proceed to send
                        $this->sendRetryLogTemplateMessage($log);
                    }
                });
        }
    }

    protected function hasPendingOrRetryableLogs(Campaign $campaign)
    {
        $campaign = Campaign::with('organization')->find($campaign->id);
        $orgMetadata = json_decode($campaign->organization->metadata ?? '{}', true);
        $retryEnabled = $orgMetadata['campaigns']['enable_resend'] ?? false;
        $retryIntervals = $orgMetadata['campaigns']['resend_intervals'] ?? [];
        $maxRetries = count($retryIntervals);
        
        // Check for pending logs first
        $hasPending = CampaignLog::where('status', 'pending')
            ->where('campaign_id', $campaign->id)
            ->exists();

        if ($hasPending) {
            return true;
        }

        // If retry is not enabled, return early
        if (!$retryEnabled || empty($retryIntervals)) {
            return false;
        }

        // Now check for retryable failed logs
        $hasRetryable = CampaignLog::where('campaign_id', $campaign->id)
            ->where('status', 'failed')
            ->where(function ($query) use ($retryIntervals, $maxRetries) {
                $query->whereExists(function ($sub) use ($retryIntervals, $maxRetries) {
                    $sub->select(DB::raw(1))
                        ->from('campaign_log_retries as clr')
                        ->whereColumn('clr.campaign_log_id', 'campaign_logs.id')
                        ->groupBy('clr.campaign_log_id')
                        ->havingRaw('COUNT(*) < ?', [$maxRetries]);
                });
            })
            ->exists();

        return $hasRetryable;
    }

    protected function sendTemplateMessage(CampaignLog $campaignLog)
    {
        DB::transaction(function() use ($campaignLog) {
            //Update log to ongoing, prevents this message from being sent out again
            $log = CampaignLog::where('id', $campaignLog->id)
                              ->where('status', 'pending') // Make sure the log is still pending
                              ->lockForUpdate()
                              ->first();
                   
            if ($log) {  
                if (!$campaignLog->contact) {
                    $log->status = 'failed';
                    $log->save();
                    
                    /*Log::error("Skipping message sending: Contact is either missing or deleted.", [
                        'campaign_log_id' => $campaignLog->id,
                        'contact_id' => $campaignLog->contact->id ?? null
                    ]);*/
                } else {   
                    $campaign_user_id = Campaign::find($log->campaign_id)?->created_by;    
                    $log->status = 'ongoing';
                    $log->save();
            
                    //Set Organization Id & initialize whatsapp service
                    $this->organizationId = $campaignLog->campaign->organization_id;
                    $this->initializeWhatsappService();
            
                    $template = $this->buildTemplateRequest($campaignLog->campaign_id, $campaignLog->contact);
                    $responseObject = $this->whatsappService->sendTemplateMessage($campaignLog->contact->uuid, $template, $campaign_user_id, $campaignLog->campaign_id);
                    //Log::info(json_encode($responseObject));
                    $this->updateCampaignLogStatus($campaignLog, $responseObject);
                }
            }
        });
    }

    protected function sendRetryLogTemplateMessage(CampaignLog $campaignLog)
    {
        DB::transaction(function() use ($campaignLog) {
            //Update log to ongoing, prevents this message from being sent out again
            $log = CampaignLog::where('id', $campaignLog->id)
                              ->where('status', 'failed')
                              ->lockForUpdate()
                              ->first();
                   
            if ($log) {  
                if (!$campaignLog->contact) {
                    $log->status = 'failed';
                    $log->save();
                    
                    /*Log::error("Skipping message sending: Contact is either missing or deleted.", [
                        'campaign_log_id' => $campaignLog->id,
                        'contact_id' => $campaignLog->contact->id ?? null
                    ]);*/
                } else {   
                    $campaign_user_id = Campaign::find($log->campaign_id)?->created_by;  
                    $retryLog = new CampaignLogRetry();
                    $retryLog->campaign_log_id = $campaignLog->id;
                    $retryLog->status = 'ongoing';
                    $retryLog->save();
            
                    //Set Organization Id & initialize whatsapp service
                    $this->organizationId = $campaignLog->campaign->organization_id;
                    $this->initializeWhatsappService();
            
                    $template = $this->buildTemplateRequest($campaignLog->campaign_id, $campaignLog->contact);
                    $responseObject = $this->whatsappService->sendTemplateMessage($campaignLog->contact->uuid, $template, $campaign_user_id, $campaignLog->campaign_id);
                    $successStatus = ($responseObject->success === true) ? 'success' : 'failed';
                    //Log::info(json_encode($responseObject));

                    $retryLog->chat_id = $responseObject->data->chat->id ?? null;
                    $retryLog->status = $successStatus;

                    // Clean metadata
                    unset($responseObject->success);
                    if (property_exists($responseObject, 'data') && property_exists($responseObject->data, 'chat')) {
                        unset($responseObject->data->chat);
                    }

                    $retryLog->metadata = json_encode($responseObject);
                    $retryLog->save();

                    // Update the retry_count on the original campaign log
                    $log = CampaignLog::find($campaignLog->id);
                    $log->retry_count += 1;
                    $log->status = $successStatus;
                    $log->save(); 

                    //If this is the last retry send contact to failed group
                    $orgMetadata = json_decode($campaignLog->campaign->organization->metadata ?? '{}', true);
                    $retryIntervals = $orgMetadata['campaigns']['resend_intervals'] ?? [];
                    $maxRetries = count($retryIntervals);

                    if ($log->status === 'failed' && $log->retry_count >= $maxRetries) {
                        $this->addContactToFailedGroup($campaignLog);
                    }
                }
            }
        });
    }

    protected function updateCampaignLogStatus(CampaignLog $campaignLog, $responseObject)
    {
        $log = CampaignLog::find($campaignLog->id);

        // Update campaign log status based on the response object
        $log->chat_id = $responseObject->data->chat->id ?? null;
        $log->status = ($responseObject->success === true) ? 'success' : 'failed';
        unset($responseObject->success);
        if (property_exists($responseObject, 'data') && property_exists($responseObject->data, 'chat')) {
            unset($responseObject->data->chat);
        }
        $log->metadata = json_encode($responseObject);
        $log->updated_at = now();
        $log->save();
    }

    private function initializeWhatsappService()
    {
        $config = Organization::where('id', $this->organizationId)->first()->metadata;
        $config = $config ? json_decode($config, true) : [];

        $accessToken = $config['whatsapp']['access_token'] ?? null;
        $apiVersion = 'v18.0';
        $appId = $config['whatsapp']['app_id'] ?? null;
        $phoneNumberId = $config['whatsapp']['phone_number_id'] ?? null;
        $wabaId = $config['whatsapp']['waba_id'] ?? null;

        $this->whatsappService = new WhatsappService($accessToken, $apiVersion, $appId, $phoneNumberId, $wabaId, $this->organizationId);
    }

    protected function addContactToFailedGroup($campaignLog)
    {
        $campaignSettings = json_decode($campaignLog->campaign->organization->metadata, true)['campaigns'] ?? [];
        $retryIntervals = $campaignSettings['resend_intervals'];

        if (!empty($campaignSettings['move_failed_contacts_to_group'])) {
            $groupUuid = $campaignSettings['failed_campaign_group'];
            $failedGroupId = DB::table('contact_groups')->where('uuid', $groupUuid)->value('id');

            // Check if the group exists in the contact_groups table by UUID
            if (!$failedGroupId) {
                Log::warning('Failed to move contact: Group with UUID ' . $groupUuid . ' does not exist.');
            }

            // Remove all groups for the contact
            DB::table('contact_contact_group')
                ->where('contact_id', $campaignLog->contact_id)
                ->delete();

             // Add contact to the failed group
             DB::table('contact_contact_group')->insert([
                'contact_id' => $campaignLog->contact_id,
                'contact_group_id' => $failedGroupId, // Use the group ID here
            ]);
        }
    }
}
