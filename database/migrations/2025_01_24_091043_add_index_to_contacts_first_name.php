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
        // Check and add index for first_name if it doesn't exist
        if (!$this->indexExists('contacts', 'idx_contacts_first_name')) {
            Schema::table('contacts', function (Blueprint $table) {
                $table->index('first_name', 'idx_contacts_first_name');
            });
        }

        // Check and add index for last_name if it doesn't exist
        if (!$this->indexExists('contacts', 'idx_contacts_last_name')) {
            Schema::table('contacts', function (Blueprint $table) {
                $table->index('last_name', 'idx_contacts_last_name');
            });
        }

        // Check and add index for email if it doesn't exist
        if (!$this->indexExists('contacts', 'idx_contacts_email')) {
            Schema::table('contacts', function (Blueprint $table) {
                $table->index('email', 'idx_contacts_email');
            });
        }

        // Check and add index for phone if it doesn't exist
        if (!$this->indexExists('contacts', 'idx_contacts_phone')) {
            Schema::table('contacts', function (Blueprint $table) {
                $table->index('phone', 'idx_contacts_phone');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if ($this->indexExists('contacts', 'idx_contacts_first_name')) {
            Schema::table('contacts', function (Blueprint $table) {
                $table->dropIndex('idx_contacts_first_name');
            });
        }

        if ($this->indexExists('contacts', 'idx_contacts_last_name')) {
            Schema::table('contacts', function (Blueprint $table) {
                $table->dropIndex('idx_contacts_last_name');
            });
        }

        if ($this->indexExists('contacts', 'idx_contacts_email')) {
            Schema::table('contacts', function (Blueprint $table) {
                $table->dropIndex('idx_contacts_email');
            });
        }

        if ($this->indexExists('contacts', 'idx_contacts_phone')) {
            Schema::table('contacts', function (Blueprint $table) {
                $table->dropIndex('idx_contacts_phone');
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
