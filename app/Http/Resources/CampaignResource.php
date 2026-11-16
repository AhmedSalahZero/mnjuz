<?php

namespace App\Http\Resources;

use App\Models\Contact;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CampaignResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $totalContacts = Contact::where('organization_id', $this->organization_id)->whereNull('deleted_at')->count();

        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'name' => $this->name,
            'template' => $this->template,
            // حملة بلا قالب واتساب (تيليجرام وغيرها): القناة لتعرض القائمة
            // أيقونتها، واسم القالب المحفوظ إن أُنشئت منه. إضافة لا تغيير.
            'channel' => $this->channel ?? \App\Services\Messaging\Channel::DEFAULT,
            'message_template_name' => $this->payloadValue('template')['name'] ?? null,
            // قنوات لا تُبلغ عن التسليم (البريد، تيليجرام): ما قبلته المنصّة أو
            // الخادم. null لما يُبلغ (واتساب وغيرها) — نسبة التسليم تكفيها.
            'sent_count' => in_array($this->channel, [\App\Services\Messaging\Channel::EMAIL, \App\Services\Messaging\Channel::TELEGRAM], true)
                ? $this->campaignLogs->where('status', 'success')->count()
                : null,
            'status' => $this->status,
            'contacts_count' => $this->contactsCount(),
            'delivery_count' => $this->deliveryCount(),
            'read_count' => $this->readCount(),
            'contact_group_count' => $this->contact_group_id == 0 ? $totalContacts : $this->contactGroupCount(),
            // Add other attributes as needed...
        ];
    }
}
