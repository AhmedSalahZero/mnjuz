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

   
    public function broadcastOn()
    {
                $channel = 'contact.chat.deleted.' . $this->organizationId;
                return new Channel($channel);
    }

    
    public function broadcastWith()
    {
		return [
			'contact_id' => $this->contactId,
		];
    }
	
}
