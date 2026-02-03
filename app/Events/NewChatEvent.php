<?php

namespace App\Events;

use App\Http\Resources\ChatBroadcastValueResource;
use Exception;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
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
                return new Channel($channel);
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
     *
     * @return array
     */
    public function broadcastWith()
    {
        $chat = $this->chat;
logger('chat');
logger(json_encode($chat));
        if (is_array($chat) && isset($chat[0])) {
			logger('chat is an array');
            $item = $chat[0];
            $item = is_array($item) ? $item : (array) $item;
            if (($item['type'] ?? null) === 'chat' && array_key_exists('value', $item)) {
                $item['value'] = (new ChatBroadcastValueResource($item['value']))->toArray(new \Illuminate\Http\Request());
            }
            $chat = [$item];
        }else{
			logger('chat is not an array');
		}

        return ['chat' => $chat];
    }
}
