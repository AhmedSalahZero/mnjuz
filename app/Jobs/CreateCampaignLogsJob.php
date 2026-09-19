<?php

namespace App\Jobs;

use App\Helpers\DateTimeHelper;
use App\Models\Campaign;
use App\Services\CampaignAudienceService;
use App\Models\CampaignLog;
use App\Models\Contact;
use Carbon\Carbon;
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
    public $tries = 1;

    public function handle()
    {
        try {
			$time = microtime(true);
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

        // حملة بلا جمهور تُعلَن فاشلة بسببٍ مكتوب.
        //
        // كانت تبقى «مجدولة» إلى الأبد: لا سجلّات تُنشأ فلا تتحوّل إلى
        // ongoing، ويُعاد فحصها كل دورة بلا نتيجة، والعميل ينتظر ولا يعرف.
        // رُصد في الإنتاج: منشأة أنشأت سبع حملات على مجموعة فارغة، وبقيت
        // كلّها صامتة حتى سأل العميل.
        if ($contacts->isEmpty()) {
            $this->markAsFailed($campaign, 'no_contacts');

            return;
        }

        if ($this->createCampaignLogs($campaign, $contacts)) {
            Campaign::where('uuid', $campaign->uuid)->update(['status' => 'ongoing']);

            ProcessCampaignMessagesJob::dispatch()
                ->onQueue('campaign-messages')
                ->afterCommit();
        }
    }

    /**
     * سبب الفشل يُكتب في metadata — لا عمود له في الجدول.
     */
    protected function markAsFailed(Campaign $campaign, string $reason): void
    {
        $metadata = $campaign->metadata ? json_decode($campaign->metadata, true) : [];

        if (!is_array($metadata)) {
            $metadata = [];
        }

        $metadata['failure_reason'] = $reason;
        $metadata['failed_at'] = now()->toDateTimeString();

        Campaign::where('uuid', $campaign->uuid)->update([
            'status' => 'failed',
            'metadata' => json_encode($metadata),
        ]);

        Log::warning('Campaign has no audience, marked as failed', [
            'campaign_id' => $campaign->id,
            'organization_id' => $campaign->organization_id,
            'contact_group_id' => $campaign->contact_group_id,
            'reason' => $reason,
        ]);
    }

    /**
     * جهات الاتصال المستهدَفة بالحملة، بلا من انسحب من التسويق.
     *
     * الانسحاب يُحترم في المسارين — «الكل» ومجموعة بعينها — لا في الأول وحده:
     * من انسحب بنفسه لا يعود إليه الإرسال ولو أعاده التاجر إلى مجموعة لاحقاً.
     */
    protected function getContactsForCampaign(Campaign $campaign)
    {
        // الخدمة نفسها التي تسألها شاشة الإنشاء قبل الحفظ، فلا يختلف ما
        // قبِلَته الشاشة عمّا يجده المُرسِل.
        return CampaignAudienceService::query(
            (int) $campaign->organization_id,
            $campaign->contact_group_id
        )->get();
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
                'created_at' =>  now(),
                'updated_at' => now(),
            ];
        })->toArray();

        // Insert new logs if any
        if (!empty($campaignLogs)) {
            foreach (array_chunk($campaignLogs, 500) as $chunk) {
                CampaignLog::insert($chunk);
            }

            return true;
        }

        return false;
    }
}
