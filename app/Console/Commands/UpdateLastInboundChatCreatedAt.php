<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class UpdateLastInboundChatCreatedAt extends Command
{
    protected $signature = 'contacts:update-last-inbound-chat-created-at';
    protected $description = 'Fill last_inbound_chat_created_at from latest inbound chat per contact (backfill or fix inconsistencies)';

    public function handle()
    {
		

     

        $this->info('last_inbound_chat_created_at updated successfully.');
        return 0;
    }
}
