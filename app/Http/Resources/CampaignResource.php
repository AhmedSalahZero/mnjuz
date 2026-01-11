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
            'status' => $this->status,
            'contacts_count' => $this->contactsCount(),
            'delivery_count' => $this->deliveryCount(),
            'read_count' => $this->readCount(),
            'contact_group_count' => $this->contact_group_id == 0 ? $totalContacts : $this->contactGroupCount(),
            // Add other attributes as needed...
        ];
    }
}
