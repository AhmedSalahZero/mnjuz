<?php

namespace App\Jobs;

use App\Helpers\DateTimeHelper;
use App\Helpers\WebhookHelper;
use App\Models\Chat;
use App\Models\ChatLog;
use App\Models\Contact;
use App\Services\PhoneService;
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
            // ✅ تحقق من duplicate
            if ($this->isDuplicate()) {
                //	logger('is dup');
                return;
            }

            // ✅ الحصول على/إنشاء contact
            $contact = $this->getOrCreateContact();
            // logger('create new chat');
            // ✅ إنشاء chat
            $chat = $this->createChat($contact);
            // logger('chat created with id '.$chat->id);
            // logger('chat created with uuid '.$chat->uuid);
            if ($chat) {
                
                
                // ✅ Media في job منفصل (لا ينتظر)
                $hasMedia = $this->hasMedia();
                if ($hasMedia) {
                    ProcessMediaDownloadJob::dispatch(
                        $chat->id,
                        $this->message,
                        $this->organizationId
                    )->onQueue('media');
                }

                // ✅ ChatLog
                $this->createChatLog($contact->id, $chat->id);

                // ✅ Ticket في job منفصل
                ProcessTicketAssignmentJob::dispatch(
                    $contact->id,
                    $this->organizationId,
                    true // isNewChat
                )->onQueue('tickets');

                // ✅ AutoReply في job منفصل
                if ($this->shouldCheckAutoReply()) {
                    ProcessAutoReplyJob::dispatch(
                        $chat->id,
                        $this->organizationId
                    )->onQueue('autoreplies')->delay(DateTimeHelper::convertToOrganizationTimezone(now(), $this->organizationId)->addSeconds(5));
                }
                
                if (!$hasMedia) {
                    event(new \App\Events\NewChatEvent(
                        $this->formatChatForEvent($chat),
                        $this->organizationId
                    ));

                    // ✅ Webhook
                    WebhookHelper::triggerWebhookEvent(
                        'message.received',
                        ['data' => $this->message],
                        $this->organizationId
                    );
                }
                // ✅ Event
              
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

    private function isDuplicate()
    {
        //	logger('check deup');
        $wamId = $this->message['id'];
        
        // ✅ تحقق سريع من cache أولاً
        if (Cache::has("msg_processed_{$wamId}")) {
            //	logger('already dup');
            return true;
        }
        // logger('no dup');
        // ✅ تحقق من DB
        $exists = Chat::where('wam_id', $wamId)
            ->where('organization_id', $this->organizationId)
            ->exists();

        if ($exists) {
            Cache::put("msg_processed_{$wamId}", true, 3600);
            return true;
        }

        return false;
    }

    private function getOrCreateContact()
    {
        $phone = PhoneService::getE164Format(
            '+' . ltrim($this->message['from'], '+')
        );
        //	logger('get or create new contact');
        return Contact::firstOrCreate(
            [
                'organization_id' => $this->organizationId,
                'phone' => $phone,
            ],
            [
                'first_name' => $this->contactData['profile']['name'] ?? null,
                'last_name' => null,
                'email' => null,
                'created_by' => 0,
                'created_at' =>  DateTimeHelper::convertToOrganizationTimezone(now(), $this->organizationId),
                'updated_at' =>  DateTimeHelper::convertToOrganizationTimezone(now(), $this->organizationId),
            ]
        );
    }

    private function createChat($contact)
    {
        //	logger('crete chat method');
        return Chat::create([
            'organization_id' => $this->organizationId,
            'wam_id' => $this->message['id'],
            'contact_id' => $contact->id,
            'type' => 'inbound',
            'metadata' => json_encode($this->message),
            'created_at' =>  DateTimeHelper::convertToOrganizationTimezone(now(), $this->organizationId),
            'status' => 'delivered',
            'is_read' => 0,
        ]);
    }

    private function hasMedia()
    {
        
      //  logger('has media?'.$this->message['type']);
        return in_array($this->message['type'], [
            'image', 'video', 'audio', 'document', 'sticker'
        ]);
    }

    private function shouldCheckAutoReply()
    {
        //	logger('should auto replay');
        return in_array($this->message['type'], [
            'text', 'button', 'audio', 'interactive'
        ]);
    }

    private function createChatLog($contactId, $chatId)
    {
        //	logger('create log');
        $chatlogId = ChatLog::insertGetId([
            'contact_id' => $contactId,
            'entity_type' => 'chat',
            'entity_id' => $chatId,
            'created_at' =>  DateTimeHelper::convertToOrganizationTimezone(now(), $this->organizationId)
        ]);
    }

    private function formatChatForEvent($chat)
    {
        //	logger('format chat for event');
        $chatLog = ChatLog::where('entity_id', $chat->id)
            ->where('entity_type', 'chat')
            ->first();

        return [[
            'type' => 'chat',
            'value' => $chatLog->relatedEntities ?? $chat
        ]];
    }
}
