<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Index لتحسين subquery عد الرسائل غير المقروءة في contactsWithChatsOptimized
     */
    public function up(): void
    {
        if (!$this->indexExists('chats', 'idx_chats_contact_type_read_deleted')) {
            Schema::table('chats', function (Blueprint $table) {
                $table->index(
                    ['contact_id', 'type', 'is_read', 'deleted_at'],
                    'idx_chats_contact_type_read_deleted'
                );
            });
        }
    }

    public function down(): void
    {
        if ($this->indexExists('chats', 'idx_chats_contact_type_read_deleted')) {
            Schema::table('chats', function (Blueprint $table) {
                $table->dropIndex('idx_chats_contact_type_read_deleted');
            });
        }
    }

    protected function indexExists(string $table, string $index): bool
    {
        $indexes = DB::select("SHOW INDEXES FROM `{$table}` WHERE Key_name = ?", [$index]);
        return count($indexes) > 0;
    }
};
