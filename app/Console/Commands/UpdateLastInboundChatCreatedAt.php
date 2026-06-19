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
        $updated = DB::update("
            UPDATE contacts c
            INNER JOIN (
                SELECT contact_id, MAX(created_at) AS last_inbound
                FROM chats
                WHERE type = 'inbound' AND deleted_at IS NULL
                GROUP BY contact_id
            ) sub ON c.id = sub.contact_id
            SET c.last_inbound_chat_created_at = sub.last_inbound
        ");

        $this->info("Updated {$updated} contact(s). last_inbound_chat_created_at synced from inbound chats (UTC).");
        return 0;
    }
}
