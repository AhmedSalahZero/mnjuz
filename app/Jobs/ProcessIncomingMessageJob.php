<?php

namespace App\Jobs;

use App\Helpers\DateTimeHelper;
use App\Helpers\WebhookHelper;
use App\Models\Chat;
use App\Models\ChatLog;
use App\Models\Contact;
use App\Services\PhoneService;
use App\Services\SubscriptionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ProcessIncomingMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    
    public $timeout = 120;
    public $tries = 1;
    public $backoff = [10, 30, 60];
    protected $message;
    protected $contactData;
    protected $organizationId;
    public function __construct($message, $contactData, $organizationId)
    {
        $this->message = $message;
     
        $this->contactData = $contactData;
        $this->organizationId = $organizationId;
    }

    public function handle()
    {
        try {
     
            // if ($this->isDuplicate()) {
            //     return;
            // }

            // ✅ الحصول على/إنشاء contact
            [$contact, $isNewContact] = $this->getOrCreateContact();
            $this->updateContactNameIfNull($contact);

            $chat = $this->createChat($contact);
            if ($chat) {
                $contact->update(['last_inbound_chat_created_at' => DateTimeHelper::convertToOrganizationTimezone(now(), null)]);
				
				 // ✅ ChatLog
				 $this->createChatLog($contact->id, $chat->id);
				 
				  // ✅ Ticket في job منفصل
				  $assignedTo = ProcessTicketAssignmentJob::dispatchSync(
                    $contact->id,
                    $this->organizationId,
                    true // isNewChat
                );
				
                // ✅ Media في job منفصل (لا ينتظر)
                $hasMedia = $this->hasMedia();
                if ($hasMedia) {
                    ProcessMediaDownloadJob::dispatch(
                        $chat->id,
                        $this->message,
                        $this->organizationId,
                        $isNewContact,
						$contact->uuid
                    )->onQueue('media');
                }

               

              

                // ✅ AutoReply في job منفصل (مع التحقق من حد الرسائل)
                $isMessageLimitReached = SubscriptionService::isSubscriptionFeatureLimitReached($this->organizationId, 'message_limit');
                if (!$isMessageLimitReached && $this->shouldCheckAutoReply()) {
                    ProcessAutoReplyJob::dispatch(
                        $chat->id,
                        $this->organizationId,
                        $isNewContact
                    )->onQueue('autoreplies')->delay(now()->addSeconds(5));
                }

                if (!$hasMedia) {
                    event(new \App\Events\NewChatEvent(
                        $this->formatChatForEvent($chat, $isNewContact, $contact->uuid),
                        $this->organizationId,
                        $isNewContact,
						false ,
						true
                    ));
					/**
					 * @var Contact $contact
					 */
					

                    WebhookHelper::triggerWebhookEvent(
                        'message.received',
                        ['data' => $this->message],
                        $this->organizationId
                    );
                }
            }
        } catch (\Exception $e) {
            Log::error('ProcessIncomingMessageJob failed', [
                'organization_id' => $this->organizationId,
                'message_id' => $this->message['id'] ?? null,
                'error' => $e->getMessage()
            ]);
            
            throw $e; // للـ retry
        }
    }

    // private function isDuplicate()
    // {
    //     $wamId = $this->message['id'];
        
    //     // ✅ تحقق سريع من cache أولاً
    //     if (Cache::has("msg_processed_{$wamId}")) {
    //         return true;
    //     }
    //     $exists = Chat::where('wam_id', $wamId)
    //         ->where('organization_id', $this->organizationId)
    //         ->exists();

    //     if ($exists) {
    //         Cache::put("msg_processed_{$wamId}", true, 3600);
    //         return true;
    //     }

    //     return false;
    // }

    private function getOrCreateContact(): array
    {
        $phone = PhoneService::getE164Format(
            '+' . ltrim($this->message['from'], '+')
        );
        $contact = Contact::firstOrCreate(
            [
                'organization_id' => $this->organizationId,
                'phone' => $phone,
            ],
            [
                'first_name' => $this->contactData['profile']['name'] ?? null,
                'last_name' => null,
                'email' => null,
                'created_by' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
        return [$contact, $contact->wasRecentlyCreated];
    }

    private function updateContactNameIfNull(Contact $contact): void
    {
        if ($contact->first_name === null && isset($this->contactData['profile']['name'])) {
            $contact->update(['first_name' => $this->contactData['profile']['name']]);
        }
    }

    private function createChat($contact)
    {
        return Chat::create([
            'organization_id' => $this->organizationId,
            'wam_id' => $this->message['id'],
            'contact_id' => $contact->id,
            'type' => 'inbound',
            'metadata' => json_encode(\App\Helpers\ChatMetadataHelper::minimalPayloadForStorage($this->message)),
            'created_at' =>  now(),
            'status' => 'delivered',
            'is_read' => 0,
        ]);
    }

    private function hasMedia()
    {
        
        return in_array($this->message['type'], [
            'image', 'video', 'audio', 'document', 'sticker'
        ]);
    }

    private function shouldCheckAutoReply()
    {
        return in_array($this->message['type'], [
            'text', 'button', 'audio', 'interactive'
        ]);
    }

    private function createChatLog($contactId, $chatId)
    {
        $chatlogId = ChatLog::insertGetId([
            'contact_id' => $contactId,
            'entity_type' => 'chat',
            'entity_id' => $chatId,
            'created_at' =>  now()
        ]);
    }

    private function formatChatForEvent($chat, bool $isNewContact = false, $contactUuid = null)
    {
        $chatLog = ChatLog::where('entity_id', $chat->id)
            ->where('entity_type', 'chat')
            ->first();
		
		
		
		
		
		
		
		
		/// 
        return [[
            'is_new_contact' => $isNewContact,
			'contact_uuid' => $contactUuid,
            'type' => 'chat',
            'value' => $chatLog->relatedEntities ?? $chat,
        ]];
    }
}
