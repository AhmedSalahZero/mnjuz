<?php

namespace App\Exports;

use App\Models\Campaign;
use App\Models\CampaignLog;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class CampaignDetailsExport implements FromCollection, WithHeadings
{
    protected $uuid;
    protected ?int $organizationId;
    private ?Campaign $campaign = null;
    private bool $loaded = false;

    /**
     * المنشأة لازمة: الحملة تُطلب بالـ uuid من رابط، وبحثٌ به وحده كان يُصدِّر
     * جهات حملة منشأة أخرى لمن عرف رابطها.
     */
    public function __construct($uuid, ?int $organizationId = null)
    {
        $this->uuid = $uuid;
        $this->organizationId = $organizationId;
    }

    /**
    * @return \Illuminate\Support\Collection
    */
    public function collection()
    {
        $campaign = $this->campaign();

        if (!$campaign) {
            return collect();
        }

        $campaignLogs = CampaignLog::with('contact', 'chat.logs')
            ->where('campaign_id', $campaign->id)
            ->orderBy('id')
            ->get();

        // الإعداد نفسه الذي يُخفي الأرقام عن الموظّف يُخفي العناوين.
        $showEmail = !\App\Models\Contact::contactPhoneNumberShouldEncrypted();

        $logs = $campaignLogs->map(function ($log) use ($campaign, $showEmail) {
            $row = [
                'campaign_name' => $campaign->name,
                // حملة تيليجرام بلا قالب واتساب كانت تُسقط التصدير كلّه.
                'template_name' => $campaign->template?->name ?? ($campaign->payloadValue('template')['name'] ?? ''),
                'first_name' => $log->contact?->first_name ?? '',
                'last_name' => $log->contact?->last_name ?? '',
                'phone' => $log->contact?->formatted_phone_number ?? '',
                'updated_at' => $log->updated_at,
                'status' => $log->status == 'success' ? ($log->chat?->status ?? 'sent') : $log->status
            ];

            if ($this->isEmail()) {
                $row['email'] = $showEmail ? (string) ($log->contact?->email ?? '') : '';
            }

            return $row;
        });

        return $logs;
    }

    /** الحملة مرّة واحدة: العناوين تُطلب قبل الصفوف. */
    private function campaign(): ?Campaign
    {
        if (!$this->loaded) {
            $this->loaded = true;
            $this->campaign = Campaign::with('template')
                ->where('uuid', $this->uuid)
                ->when($this->organizationId !== null, fn ($query) => $query->where('organization_id', $this->organizationId))
                ->first();
        }

        return $this->campaign;
    }

    private function isEmail(): bool
    {
        return $this->campaign()?->channel === \App\Services\Messaging\Channel::EMAIL;
    }

    public function headings(): array
    {
        if ($this->isEmail()) {
            return ['Campaign Name', 'Template', 'First Name', 'Last Name', 'Phone Number', 'Last Updated', 'Status', 'Email'];
        }

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
