<?php

namespace App\Events;

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
    /**
     * Create a new event instance.
     *
     * @param mixed $chat
     * @param int $organizationId
     */
    public function __construct($chat, $organizationId)
    {
        $this->chat = $chat;
        $this->organizationId = $organizationId;
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

    /**
     * Get the data to broadcast (only fields used by the frontend).
     * Shape: [{ type: 'chat', value: {...}, tempMessageId?: string }]
     *
     * @return array
     */
    public function broadcastWith()
    {
        $chat = $this->chat;

        if (is_array($chat) && isset($chat[0])) {
            $item = $chat[0];
            $item = is_array($item) ? $item : (array) $item;
            if (($item['type'] ?? null) === 'chat' && array_key_exists('value', $item)) {
                $item['value'] = $this->minimalChatValue($item['value']);
            }
            $chat = [$item];
        } elseif (is_array($chat) && array_key_exists('value', $chat)) {
            $chat['value'] = $this->minimalChatValue($chat['value']);
        }

        return ['chat' => $chat];
    }

    /** الحقول فقط التي تستخدمها الواجهة من metadata كل log (ChatBubble: status, errors, id) */
    private const LOG_METADATA_KEYS = ['status', 'errors', 'id'];

    protected function minimalChatValue($value): array
    {
        $arr = $value instanceof \Illuminate\Database\Eloquent\Model
            ? $value->toArray()
            : (array) $value;

        $user = null;
        if (!empty($arr['user']) && is_array($arr['user'])) {
            $user = array_intersect_key($arr['user'], array_flip(['first_name', 'last_name']));
        }

        $media = null;
        if (!empty($arr['media']) && is_array($arr['media'])) {
            $media = array_intersect_key($arr['media'], array_flip(['path', 'name', 'type', 'size']));
        }

        $logs = $this->minimalLogs($arr['logs'] ?? []);

        return [
            'contact_id' => $arr['contact_id'] ?? null,
            'created_at' => $arr['created_at'] ?? null,
            'deleted_at' => $arr['deleted_at'] ?? null,
            'metadata' => $arr['metadata'] ?? '{}',
            'type' => $arr['type'] ?? 'outbound',
            'wam_id' => $arr['wam_id'] ?? null,
            'media' => $media,
            'logs' => $logs,
            'user' => $user,
        ];
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
