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
        // Check if the index already exists before attempting to create it
        if (!$this->indexExists('chats', 'idx_chats_contact_org_deleted_at')) {
            Schema::table('chats', function (Blueprint $table) {
                $table->index(['contact_id', 'organization_id', 'deleted_at'], 'idx_chats_contact_org_deleted_at');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if ($this->indexExists('chats', 'idx_chats_contact_org_deleted_at')) {
            Schema::table('chats', function (Blueprint $table) {
                $table->dropIndex('idx_chats_contact_org_deleted_at');
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
