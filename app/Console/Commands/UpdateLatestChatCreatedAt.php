<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Contact;
use App\Models\Chat;

class UpdateLatestChatCreatedAt extends Command
{
    protected $signature = 'contacts:update-latest-chat-created-at';
    protected $description = 'Update latest_chat_created_at for existing contacts';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        Contact::with('chats')
            ->chunkById(100, function ($contacts) {
                foreach ($contacts as $contact) {
                    $latestChat = $contact->chats()->orderBy('created_at', 'desc')->first();
                    if ($latestChat) {
                        $contact->latest_chat_created_at = $latestChat->created_at;
                        $contact->save();
                    }
                }
            });

        $this->info('Latest chat created_at updated successfully for all contacts.');
    }
}

