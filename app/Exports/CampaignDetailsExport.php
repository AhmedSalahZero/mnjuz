<?php

namespace App\Exports;

use App\Models\Campaign;
use App\Models\CampaignLog;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class CampaignDetailsExport implements FromCollection, WithHeadings
{
    protected $uuid;

    public function __construct($uuid)
    {
        $this->uuid = $uuid;
    }

    /**
    * @return \Illuminate\Support\Collection
    */
    public function collection()
    {
        $campaign = Campaign::with('template')->where('uuid', $this->uuid)->first();
        $campaignLogs = CampaignLog::with('contact', 'chat.logs')
            ->where('campaign_id', $campaign->id)
            ->orderBy('id')
            ->get();

        $logs = $campaignLogs->map(function ($log) use ($campaign) {
            return [
                'campaign_name' => $campaign->name,
                'template_name' => $campaign->template->name,
                'first_name' => $log->contact?->first_name ?? '',
                'last_name' => $log->contact?->last_name ?? '',
                'phone' => $log->contact?->formatted_phone_number ?? '',
                'updated_at' => $log->updated_at,
                'status' => $log->status == 'success' ? $log->chat->status : $log->status
            ];
        });

        return $logs;
    }

    public function headings(): array
    {
        // Define your headers here
        return [
            'Campaign Name',
            'Template',
            'First Name',
            'Last Name',
            'Phone Number',
            'Last Updated',
            'Status'
        ];
    }
}
