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

		$shouldBeEncrypted = Contact::contactPhoneNumberShouldEncrypted(Organization::find($this->organization_id));  
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
            'is_blocked' => $this->is_blocked,
            'is_favorite' => $this->is_favorite,
            
            // ✅ من الـ JOIN في contactsWithChatsOptimized
            'ticket_status' => $this->ticket_status ?? null,
            'ticket_assigned_to' => $this->ticket_assigned_to ?? null,
            
            // ✅ Accessors البسيطة (لا تستدعي queries)
            'full_name' => $this->full_name,
            'formatted_phone_number' => $this->formatted_phone_number,
            
            // ✅ Relations - محمّلة مسبقاً بـ with()
            'last_chat' => $this->whenLoaded('lastChat', function() {
                return [
                    'id' => $this->lastChat->id,
                    'uuid' => $this->lastChat->uuid,
                    'organization_id' => $this->lastChat->organization_id,
                    'wam_id' => $this->lastChat->wam_id,
                    'contact_id' => $this->lastChat->contact_id,
                    'user_id' => $this->lastChat->user_id,
                    'type' => $this->lastChat->type,
                    'metadata' => $this->lastChat->metadata,
                    'media_id' => $this->lastChat->media_id,
                    'status' => $this->lastChat->status,
                    'is_read' => $this->lastChat->is_read,
                    'deleted_by' => $this->lastChat->deleted_by,
                    'deleted_at' => $this->lastChat->deleted_at,
                    'created_at' => $this->lastChat->created_at,
                    'media' => $this->lastChat->media,
                ];
            }),
            
            'last_inbound_chat' => $this->whenLoaded('lastInboundChat', function() {
                return $this->lastInboundChat ? [
                    'id' => $this->lastInboundChat->id,
                    'uuid' => $this->lastInboundChat->uuid,
                    'organization_id' => $this->lastInboundChat->organization_id,
                    'wam_id' => $this->lastInboundChat->wam_id,
                    'contact_id' => $this->lastInboundChat->contact_id,
                    'user_id' => $this->lastInboundChat->user_id,
                    'type' => $this->lastInboundChat->type,
                    'metadata' => $this->lastInboundChat->metadata,
                    'media_id' => $this->lastInboundChat->media_id,
                    'status' => $this->lastInboundChat->status,
                    'is_read' => $this->lastInboundChat->is_read,
                    'deleted_by' => $this->lastInboundChat->deleted_by,
                    'deleted_at' => $this->lastInboundChat->deleted_at,
                    'created_at' => $this->lastInboundChat->created_at,
                    'media' => $this->lastInboundChat->media,
                ] : null;
            }),
            
            // ✅ عدد الرسائل غير المقروءة - من الـ subquery المحسوب مسبقاً!
            // ❌ لا تستخدم: $this->chats()->where(...)->count()
            // ✅ استخدم: العداد المحسوب في contactsWithChatsOptimized
            'unread_messages' => $this->unread_messages_count ?? 0,
        ];
		
        // $data = parent::toArray($request);

        // $data['unread_messages'] = $this->chats()
        //     ->where('type', 'inbound')
        //     ->whereNull('deleted_at')
        //     ->where('is_read', 0)
        //     ->count();
        
        // return $data;
    }
}
