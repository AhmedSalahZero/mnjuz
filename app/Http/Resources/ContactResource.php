<?php

namespace App\Http\Resources;

use App\Helpers\DateTimeHelper;
use App\Models\Contact;
use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Propaganistas\LaravelPhone\PhoneNumber;

class ContactResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {

		$organization =$this->organization ;
		$shouldBeEncrypted = Contact::contactPhoneNumberShouldEncrypted($organization);
		$this->encryptPhoneNumber($shouldBeEncrypted);
		 return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'phone' => $this->phone,
            'email' => $this->email,
            'organization_id' => $this->organization_id,
            'latest_chat_created_at' => $this->latest_chat_created_at,
            'last_inbound_chat_created_at' => $this->last_inbound_chat_created_at ?? null,
            'is_blocked' => $this->is_blocked,
            'is_favorite' => $this->is_favorite,
            // 'ticket_status' => $this->ticket_status ?? null,
            // 'ticket_assigned_to' => $this->ticket_assigned_to ?? null,
            'full_name' => $this->full_name,
            'formatted_phone_number' => $this->formatted_phone_number,
            
            'last_chat' => $this->whenLoaded('lastChat', function() {
			
                return [
                 //   'id' => $this->lastChat->id,
                   // 'uuid' => $this->lastChat->uuid,
                 //   'organization_id' => $this->lastChat->organization_id,
                 //   'wam_id' => $this->lastChat->wam_id,
                  //  'contact_id' => $this->lastChat->contact_id,
                   // 'user_id' => $this->lastChat->user_id,
                   // 'type' => $this->lastChat->type,
                    'metadata' => $this->lastChat->metadata,
                   // 'media_id' => $this->lastChat->media_id,
                 //   'status' => $this->lastChat->status,
                  //  'is_read' => $this->lastChat->is_read,
                 //   'deleted_by' => $this->lastChat->deleted_by,
                    'deleted_at' => $this->lastChat->deleted_at,
                    'created_at' => $this->lastChat->created_at,
              //     'media' => $this->lastChat->media,
                ];
            }),
            
            // استخدام العمود last_inbound_chat_created_at بدل تحميل العلاقة (أسرع)
            'last_inbound_chat' => $this->last_inbound_chat_created_at
                ? ['created_at' => $this->last_inbound_chat_created_at]
                : $this->whenLoaded('lastInboundChat', fn () => $this->lastInboundChat ? ['created_at' => $this->lastInboundChat->created_at] : null),
       
            'unread_messages' => $this->unread_messages_count ?? 0,
        ];
		
      
    }
}
