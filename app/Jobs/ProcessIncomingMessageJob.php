<?php

namespace App\Jobs;

use App\Helpers\WebhookHelper;
use App\Models\Chat;
use App\Models\ChatLog;
use App\Models\Contact;
use App\Services\PhoneService;
use App\Services\SubscriptionService;
use App\Services\ContactPlaceholderService;
use App\Services\WorkingHoursService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use App\Support\JsonText;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ProcessIncomingMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    
    public $timeout = 120;
    public $tries = 3;
    public $backoff = [1, 3, 5];
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

               

              

                $isMessageLimitReached = SubscriptionService::isSubscriptionFeatureLimitReached($this->organizationId, 'message_limit');
                if (!$isMessageLimitReached) {
                    $this->maybeSendWorkingHoursAwayNotice($chat, $contact);
                }

                // ✅ AutoReply في job منفصل (مع التحقق من حد الرسائل)
                if (
                    !$isMessageLimitReached
                    && $this->shouldCheckAutoReply()
                    && !WorkingHoursService::isOutsideConfiguredHours($this->organizationId)
                ) {
                    ProcessAutoReplyJob::dispatch(
                        $chat->id,
                        $this->organizationId,
                        $isNewContact
                    )->onQueue('autoreplies');
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
        // بعض حمولات واتساب تصل بلا "from" — رسائل النظام وبعض الأنواع غير
        // المدعومة. القراءة المباشرة كانت ترمي «Undefined array key» فيفشل
        // الجوب، وتضيع رسالة العميل كاملةً بلا أثر عند العميل ولا عندنا.
        // نرمي استثناءً واضحاً بدل تحذير PHP غامض، ونسجّل الحمولة لتُشخَّص.
        $from = $this->message['from'] ?? null;

        if ($from === null || $from === '') {
            Log::warning('Incoming message has no sender', [
                'organization_id' => $this->organizationId,
                'message_id'      => $this->message['id'] ?? null,
                'message_type'    => $this->message['type'] ?? null,
                'keys'            => array_keys($this->message),
            ]);

            throw new \RuntimeException('Incoming WhatsApp message has no "from" field.');
        }

        $phone = PhoneService::getE164Format('+' . ltrim($from, '+'));

        try {
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
        } catch (\Illuminate\Database\QueryException $e) {
            // Duplicate key — جوب آخر سبق وأنشأ نفس الرقم، نجلب الموجود
            if ($e->errorInfo[1] === 1062) {
                $contact = Contact::where('organization_id', $this->organizationId)
                    ->where('phone', $phone)
                    ->firstOrFail();
            } else {
                throw $e;
            }
        }

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
        $wamId = $this->message['id'];

        // تحقق سريع من الـ cache قبل الوصول للـ DB
        if (Cache::has("wam_processed_{$wamId}")) {
            Log::info('ProcessIncomingMessageJob: duplicate wam_id skipped (cache)', ['wam_id' => $wamId]);
            return null;
        }

        try {
            $chat = Chat::create([
                'organization_id' => $this->organizationId,
                'wam_id' => $wamId,
                'contact_id' => $contact->id,
                'type' => 'inbound',
                'metadata' => JsonText::encode(\App\Helpers\ChatMetadataHelper::minimalPayloadForStorage($this->message)),
                'created_at' => now(),
                'status' => 'delivered',
                'is_read' => 0,
            ]);

            Cache::put("wam_processed_{$wamId}", true, 3600);

            return $chat;
        } catch (\Illuminate\Database\QueryException $e) {
            // Duplicate wam_id — الرسالة معالَجة مسبقاً
            if ($e->errorInfo[1] === 1062) {
                Log::info('ProcessIncomingMessageJob: duplicate wam_id skipped (DB)', ['wam_id' => $wamId]);
                Cache::put("wam_processed_{$wamId}", true, 3600);
                return null;
            }
            throw $e;
        }
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

    private function maybeSendWorkingHoursAwayNotice(Chat $chat, Contact $contact): void
    {
        if (!WorkingHoursService::isOutsideConfiguredHours($this->organizationId)) {
            return;
        }
        $cacheKey = 'working_hours_away_' . $this->organizationId . '_' . $contact->id;
        if (Cache::has($cacheKey)) {
            return;
        }
        $body = WorkingHoursService::resolveAwayNoticeBody($this->organizationId);
        $body = trim(ContactPlaceholderService::replace($this->organizationId, $contact->uuid, $body));
        if ($body === '') {
            return;
        }
        SendTextMessageJob::dispatch(
            $this->organizationId,
            $contact->uuid,
            $body
        )->onQueue('high');
        Cache::put($cacheKey, 1, now()->addHour());
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
