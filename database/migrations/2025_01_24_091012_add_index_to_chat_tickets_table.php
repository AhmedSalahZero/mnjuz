<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Check if the composite index already exists, and if not, create it
        if (!$this->indexExists('chat_tickets', 'idx_chat_tickets_contact_assigned_to_status')) {
            Schema::table('chat_tickets', function (Blueprint $table) {
                $table->index(['contact_id', 'assigned_to', 'status'], 'idx_chat_tickets_contact_assigned_to_status');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if ($this->indexExists('chat_tickets', 'idx_chat_tickets_contact_assigned_to_statust')) {
            Schema::table('chat_tickets', function (Blueprint $table) {
                $table->dropIndex('idx_chat_tickets_contact_assigned_to_status');
            });
        }
    }

    /**
     * Check if an index exists on a given table.
     *
     * @param string $table
     * @param string $index
     * @return bool
     */
    protected function indexExists(string $table, string $index): bool
    {
        // Check if the index exists using raw SQL query
        $indexes = DB::select("SHOW INDEXES FROM `{$table}` WHERE Key_name = ?", [$index]);

        return count($indexes) > 0;
    }
};
