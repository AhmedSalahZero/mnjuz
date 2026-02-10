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
                $channel = 'chats.' . 'ch' . $this->organizationId;
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
        }

        return ['chat' => $chat];
    }

   
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

        $logs = [];
        if (!empty($arr['logs']) && is_array($arr['logs'])) {
            foreach ($arr['logs'] as $log) {
                $logArr = is_array($log) ? $log : (array) $log;
                $logs[] = array_intersect_key($logArr, array_flip(['metadata']));
            }
        }

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
}
