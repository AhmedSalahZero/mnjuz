<?php

namespace App\Console\Commands;

use App\Jobs\ProcessAutoReplyJob;
use App\Jobs\ProcessIncomingMessageJob;
use App\Jobs\ProcessTicketAssignmentJob;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Console\Command;

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
