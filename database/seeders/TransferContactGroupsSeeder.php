<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TransferContactGroupSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            // Step 1: Insert into pivot table only if the pair does not already exist
            DB::statement("
                INSERT INTO contact_contact_group (contact_id, contact_group_id)
                SELECT c.id, c.contact_group_id
                FROM contacts c
                WHERE c.contact_group_id IS NOT NULL
                AND NOT EXISTS (
                    SELECT 1
                    FROM contact_contact_group ccg
                    WHERE ccg.contact_id = c.id AND ccg.contact_group_id = c.contact_group_id
                )
            ");

            // Step 2: Nullify contact_group_id in contacts table
            DB::table('contacts')->whereNotNull('contact_group_id')->update(['contact_group_id' => null]);

            // Step 3: Drop the column only if it exists and contains only NULLs
            if (
                Schema::hasColumn('contacts', 'contact_group_id') &&
                DB::table('contacts')->whereNotNull('contact_group_id')->doesntExist()
            ) {
                Schema::table('contacts', function ($table) {
                    $table->dropColumn('contact_group_id');
                });
            }
        });
    }
}