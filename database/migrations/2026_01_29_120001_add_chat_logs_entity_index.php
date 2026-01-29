<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Index لتسريع استعلام lastChat (whereHas('chatLog')) في قائمة المحادثات
     * الاستعلام: EXISTS (SELECT * FROM chat_logs WHERE entity_id = chats.id AND entity_type = 'chat')
     */
    public function up(): void
    {
        if (!$this->indexExists('chat_logs', 'idx_chat_logs_entity')) {
            Schema::table('chat_logs', function (Blueprint $table) {
                $table->index(
                    ['entity_id', 'entity_type'],
                    'idx_chat_logs_entity'
                );
            });
        }
    }

    public function down(): void
    {
        if ($this->indexExists('chat_logs', 'idx_chat_logs_entity')) {
            Schema::table('chat_logs', function (Blueprint $table) {
                $table->dropIndex('idx_chat_logs_entity');
            });
        }
    }

    protected function indexExists(string $table, string $index): bool
    {
        $indexes = DB::select("SHOW INDEXES FROM `{$table}` WHERE Key_name = ?", [$index]);
        return count($indexes) > 0;
    }
};
