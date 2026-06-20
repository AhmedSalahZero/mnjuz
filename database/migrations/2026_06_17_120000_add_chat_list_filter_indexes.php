<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Indexes لتسريع /chats?status=open|closed|unassigned و pagination.
     */
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        if (!$this->indexExists('chat_tickets', 'idx_chat_tickets_status_is_latest_contact')) {
            Schema::table('chat_tickets', function (Blueprint $table) {
                $table->index(
                    ['status', 'is_latest', 'contact_id'],
                    'idx_chat_tickets_status_is_latest_contact'
                );
            });
        }

        if (!$this->indexExists('contacts', 'idx_contacts_org_deleted_latest_chat')) {
            Schema::table('contacts', function (Blueprint $table) {
                $table->index(
                    ['organization_id', 'deleted_at', 'latest_chat_created_at'],
                    'idx_contacts_org_deleted_latest_chat'
                );
            });
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        if ($this->indexExists('chat_tickets', 'idx_chat_tickets_status_is_latest_contact')) {
            Schema::table('chat_tickets', function (Blueprint $table) {
                $table->dropIndex('idx_chat_tickets_status_is_latest_contact');
            });
        }

        if ($this->indexExists('contacts', 'idx_contacts_org_deleted_latest_chat')) {
            Schema::table('contacts', function (Blueprint $table) {
                $table->dropIndex('idx_contacts_org_deleted_latest_chat');
            });
        }
    }

    protected function indexExists(string $table, string $index): bool
    {
        $indexes = DB::select("SHOW INDEXES FROM `{$table}` WHERE Key_name = ?", [$index]);

        return count($indexes) > 0;
    }
};
