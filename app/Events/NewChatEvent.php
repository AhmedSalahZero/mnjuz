<?php

namespace App\Events;

use App\Models\Contact;
use Exception;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class NewChatEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $chat;
    public $organizationId;
    public $queue = 'high';
	public $isNewContact = false;
	public $statusChanged = false;
    /**
     * Create a new event instance.
     * يُخزّن فقط النسخة المُصغّرة من الـ chat (في الـ queue والـ broadcast والـ listeners).
     *
     * @param mixed $chat
     * @param int $organizationId
     */
    public function __construct($chat, $organizationId, $isNewContact = false,$statusChanged = false)
    {
        $this->organizationId = $organizationId;
		$this->isNewContact = $isNewContact;
		$this->statusChanged = $statusChanged;
         $this->chat = $this->buildMinimalChatPayload($chat);
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return \Illuminate\Broadcasting\Channel
     */
    public function broadcastOn()
    {
        try {
	
            // Check if Pusher settings are available
            if (config('broadcasting.connections.pusher.key') && config('broadcasting.connections.pusher.secret')) {
                $channel = 'chats.ch' . $this->organizationId;
                return new PresenceChannel($channel);
            } else {
                // Log an error if Pusher settings are not configured
                Log::error('Pusher settings are not configured.');
                return;
            }
        } catch (Exception $e) {
            // Log the exception and prevent the event from broadcasting
            Log::error('Failed to broadcast event: ' . $e->getMessage());
            return;
        }
    }

    /** حد حجم رسالة Pusher (بايت) */
    private const PUSHER_MAX_PAYLOAD_BYTES = 10240;

    /**
     * Get the data to broadcast. الـ chat مُصغّر مسبقاً في الـ constructor.
     * إذا تجاوز الحجم حد Pusher نُقلّص حقولاً اختيارية حتى نبقى تحت الحد.
     *
     * @return array
     */
    public function broadcastWith()
    {
        $payload = ['chat' => $this->chat];
		if($this->statusChanged){
			$payload['statusChanged'] = $this->statusChanged;
		}
        $encoded = json_encode($payload);
        if ($encoded !== false && strlen($encoded) <= self::PUSHER_MAX_PAYLOAD_BYTES) {
            return $payload;
        }
		// logger('pusher_max_limit_reached');
		// logger('before_shrink');
		// logger(json_encode($payload));
		// logger('after_shrink');
		// logger(json_encode($this->shrinkPayloadToLimit($payload)));
        return $this->shrinkPayloadToLimit($payload);
    }

    /**
     * تقليص الـ payload بإزالة/تقصير حقول اختيارية حتى يصبح تحت حد Pusher.
     */
    private function shrinkPayloadToLimit(array $payload): array
    {
        $chat = &$payload['chat'];
        if (!is_array($chat)) {
            return $payload;
        }
        $value = null;
        if (isset($chat[0]['value'])) {
            $value = &$chat[0]['value'];
        } elseif (isset($chat['value'])) {
            $value = &$chat['value'];
        }
        if (!is_array($value)) {
            return $payload;
        }
        $steps = [
            function (array &$v) {
                $v['contact_full_name'] = null;
            },
            function (array &$v) {
                $v['contact_phone'] = null;
            },
            function (array &$v) {
                $v['metadata'] = '{}';
            },
            function (array &$v) {
                $v['logs'] = array_slice($v['logs'] ?? [], -2, 2);
            },
        ];
        foreach ($steps as $step) {
            $step($value);
            $encoded = json_encode($payload);
            if ($encoded !== false && strlen($encoded) <= self::PUSHER_MAX_PAYLOAD_BYTES) {
                return $payload;
            }
        }
        return $payload;
    }

    /**
     * إرجاع الـ chat بالشكل المُصغّر فقط (للتخزين في الـ event والـ queue والـ broadcast).
     */
    public function buildMinimalChatPayload($chat): array
    {
        if (is_array($chat) && isset($chat[0])) {
            $item = $chat[0];
            $item = is_array($item) ? $item : (array) $item;
            if (($item['type'] ?? null) === 'chat' && array_key_exists('value', $item)) {
                $item['value'] = $this->minimalChatValue($item['value']);
            }
            return [$item];
        }
        if (is_array($chat) && array_key_exists('value', $chat)) {
            $chat['value'] = $this->minimalChatValue($chat['value']);
            return $chat;
        }
        return is_array($chat) ? $chat : [];
    }

    /** الحقول فقط التي تستخدمها الواجهة من metadata كل log (ChatBubble: status, errors, id) */
    private const LOG_METADATA_KEYS = ['status', 'errors', 'id'];

    /** حد Pusher 10240 بايت — ن ate الحقول الكبيرة لضمان عدم تجاوزه */
    private const MAX_METADATA_BYTES = 1800;
    private const MAX_MEDIA_PATH_BYTES = 200;
    private const MAX_LOGS_ENTRIES = 6;

    public function minimalChatValue($value): array
    {
		// return (array) $value;
        $arr = $value instanceof \Illuminate\Database\Eloquent\Model
            ? $value->toArray()
            : (array) $value;
		
        $user = null;
        if (!empty($arr['user']) && is_array($arr['user'])) {
            $user = array_intersect_key($arr['user'], array_flip(['first_name', 'last_name']));
        }

        $media = null;
        if (!empty($arr['media']) && is_array($arr['media'])) {
            $media = [
                'type' => $arr['media']['type'] ?? null,
                'size' => $arr['media']['size'] ?? null,
                'path' => $this->ateToBytes($arr['media']['path'] ?? '', self::MAX_MEDIA_PATH_BYTES),
                'name' => $this->ateToBytes($arr['media']['name'] ?? '', 80),
            ];
        }

        $logs = $this->minimalLogs($arr['logs'] ?? []);

        $contactId = $arr['contact_id'] ?? null;
        $contactPhone = null;
        $contactFullName = null;
        // $contactFirstName = null;
        // $contactLastName = null;
     //   $contactEmail = null;
        $contactOrganizationId = null;
        $contactLatestChatCreatedAt = null;
        $contactIsBlocked = null;
        $contactIsFavorite = null;
        $contactFormattedPhoneNumber = null;
        if ($contactId) {
            $contact = Contact::where('id', $contactId)
                ->first([
                    'phone', 'first_name', 'last_name', 'email', 'organization_id',
                    'latest_chat_created_at', 'is_blocked', 'is_favorite',
                ]);
            if ($contact) {
                $contactPhone = $contact->phone;
                $contactFullName = $this->ateToBytes(
                    trim(($contact->first_name ?? '') . ' ' . ($contact->last_name ?? '')),
                    120
                ) ?: null;
                // $contactFirstName = $contact->first_name;
                // $contactLastName = $contact->last_name;
            //    $contactEmail = $contact->email;
                $contactOrganizationId = $contact->organization_id;
                $contactLatestChatCreatedAt = $contact->latest_chat_created_at;
                $contactIsBlocked = $contact->is_blocked;
                $contactIsFavorite = $contact->is_favorite;
                $contactFormattedPhoneNumber = $contact->formatted_phone_number;
            }
        }

        $metadataRaw = $arr['metadata'] ?? null;
        $metadata = $metadataRaw;
      
		if($metadata){
			$metadata = json_decode($metadata, true);
		}
		$type = $metadata['type'] ?? null;
		if($metadata && isset($metadata['type']) && $type && empty($metadata[$type])  ){
			$metadata[$type] = null;
		}
		if(isset($metadata[$type])){
			unset($metadata[$type]['url']);
		}
		$metadata = is_string($metadata) ? $metadata : json_encode($metadata);
		
        return [
            'id' => $arr['id'] ?? null,
            'uuid' => $arr['uuid'] ?? null,
            'contact_id' => $contactId,
            'is_new_contact' => $this->isNewContact,
            // 'contact_phone' => $contactPhone,
			'phone' => $contactPhone,
			'formatted_phone_number' => $contactFormattedPhoneNumber,
			'organization_id' => $contactOrganizationId,
            'latest_chat_created_at' => $contactLatestChatCreatedAt,
            'is_blocked' => $contactIsBlocked,
            'is_favorite' => $contactIsFavorite,
            // 'ticket_status' => $arr['ticket_status'] ?? null,
            // 'ticket_assigned_to' => $arr['ticket_assigned_to'] ?? null,
            // 'full_name' => $contactFullName ?: null,
           
			// 'email' => $contactEmail,
            'contact_full_name' => $contactFullName ?: null,
            'created_at' => $arr['created_at'] ?? null,
            'deleted_at' => $arr['deleted_at'] ?? null,
            'metadata' => $metadata,
            'type' => $arr['type'] ?? 'outbound',
            'wam_id' => $arr['wam_id'] ?? null,
            'status' => $arr['status'] ?? null,
            'media' => $media,
            'logs' => $logs,
            'user' => $user,
            // حقول الـ contact المطلوبة للواجهة
            // 'first_name' => $contactFirstName,
            // 'last_name' => $contactLastName,
          
           
            
        ];
    }

    /** ate string to max bytes (UTF-8 safe) لضمان عدم تجاوز حد Pusher */
    private function ateToBytes(string $s, int $maxBytes): string
    {
        if (strlen($s) <= $maxBytes) {
            return $s;
        }
        return mb_strcut($s, 0, $maxBytes, 'UTF-8') ?: '';
    }

    /**
     * إرجاع مصفوفة logs بأقل حجم: كل عنصر فقط { "metadata": "{\"status\":\"...\",\"id\":\"...\"}" }
     * لا نرسل: id, chat_id, created_at من الـ log ولا أي حقول داخل metadata غير status, errors, id.
     */
    protected function minimalLogs($rawLogs): array
    {
        if (empty($rawLogs) || !is_array($rawLogs)) {
            return [];
        }
        $rawLogs = array_slice($rawLogs, -self::MAX_LOGS_ENTRIES, self::MAX_LOGS_ENTRIES);
		
        $out = [];
        foreach ($rawLogs as $log) {
            $logArr = is_array($log) ? $log : (array) $log;
            $rawMetadata = $logArr['metadata'] ?? '{}';
            $decoded = is_string($rawMetadata) ? json_decode($rawMetadata, true) : $rawMetadata;
            if (!is_array($decoded)) {
                $decoded = [];
            }
            $minimal = array_intersect_key($decoded, array_flip(self::LOG_METADATA_KEYS));
            $out[] = ['metadata' => json_encode($minimal)];
        }

        return $out;
    }
}
