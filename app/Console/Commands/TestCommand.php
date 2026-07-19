<?php

namespace App\Console\Commands;

use App\Jobs\ProcessAutoReplyJob;
use App\Jobs\ProcessIncomingMessageJob;
use App\Jobs\ProcessTicketAssignmentJob;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class TestCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'run:test';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
		$contactId = 159377;
		$unreadMessagesCount = 0;
		if ($contactId) {
            $contact = Contact::where('id', $contactId)
            ->first([
                'phone', 'first_name', 'last_name', 'email', 'organization_id',
                'latest_chat_created_at', 'is_blocked', 'is_favorite',
                'uuid',
            ]);
            if ($contact) {
               
                
                $contactOrganizationId = $contact->organization_id;
                $contactLatestChatCreatedAt = $contact->latest_chat_created_at;
                $contactIsBlocked = $contact->is_blocked;
                $contactIsFavorite = $contact->is_favorite;
                $contactFormattedPhoneNumber = $contact->formatted_phone_number;
                $contactUuid = $contact->uuid;
				
				$unreadQuery = DB::table('chats')
					->where('contact_id', $contactId)
					->where('type', 'inbound')
					->where('is_read', 0)
					->whereNull('deleted_at');
					$unreadQuery->where('organization_id', $contactOrganizationId);
				
				$unreadMessagesCount = (int) $unreadQuery->count();
				
            }
	
				
			
        }
        // اختبار incoming message
		/**
		 * @var Contact $contact
		 */
		// $user = User::find(71);
		// $titleEn = __('New message received');
		// $titleAr = __('تم استقبال رسالة جديدة');
		// $messageEn = __('You have a new message from :name', ['name' => 'Test']);
		// $messageAr = __('لديك رسالة جديدة من :name', ['name' => 'Test']);
		// $additionalData = [];
		// $user->sendAppNotification($titleEn, $titleAr, $messageEn, $messageAr, $additionalData);
		
		// $contact = Contact::first();
		// $contact->newMessageReceived();
		
        // ProcessIncomingMessageJob::dispatchSync(['id'=>'test'.time(),'from'=>'01025894984','timestamp'=>time(),'type'=>'text','text'=>['body'=>'test']], ['profile'=>['name'=>'Test']], 1);

        // // اختبار ticket
        // ProcessTicketAssignmentJob::dispatchSync(1, 1, true);

        // // اختبار autoreply
        // ProcessAutoReplyJob::dispatchSync(1, 1, false);
    }
}
