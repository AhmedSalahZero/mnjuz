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

class ContactChatDeletedEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $organizationId;
    public $queue = 'high';
	public $contactId;
 
    public function __construct($organizationId, $contactId)
    {
        $this->organizationId = $organizationId;
		$this->contactId = $contactId;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return \Illuminate\Broadcasting\Channel
     */
    public function broadcastOn()
    {
        try {
                $channel = 'contact.chat.deleted.' . $this->organizationId;
                return new PresenceChannel($channel);
           
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
		return [
			'contact_id' => $this->contactId,
		];
    }
	
}
