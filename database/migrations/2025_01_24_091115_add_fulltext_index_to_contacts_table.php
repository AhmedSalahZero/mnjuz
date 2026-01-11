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
        if (!$this->fullTextIndexExists('contacts', 'fulltext_contacts_name_email_phone')) {
            DB::statement('
                CREATE FULLTEXT INDEX fulltext_contacts_name_email_phone 
                ON contacts (first_name, last_name, phone, email)
            ');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop the FULLTEXT index only if it exists
        if ($this->fullTextIndexExists('contacts', 'fulltext_contacts_name_email_phone')) {
            DB::statement('DROP INDEX fulltext_contacts_name_email_phone ON contacts');
        }
    }

    /**
     * Check if a FULLTEXT index exists on a given table.
     *
     * @param string $table
     * @param string $index
     * @return bool
     */
    protected function fullTextIndexExists(string $table, string $index): bool
    {
        // Check for the FULLTEXT index using raw SQL query
        $indexes = DB::select("SHOW INDEXES FROM `{$table}` WHERE Key_name = ?", [$index]);

        return count($indexes) > 0;
    }
};
