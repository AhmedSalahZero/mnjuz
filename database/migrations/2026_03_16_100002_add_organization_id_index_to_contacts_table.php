<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $indexName = 'contacts_organization_id_index';
        $indexExists = collect(DB::select("SHOW INDEX FROM contacts WHERE Key_name = ?", [$indexName]))->isNotEmpty();
        if (!$indexExists) {
            Schema::table('contacts', function (Blueprint $table) {
                $table->index('organization_id');
            });
        }
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropIndex(['organization_id']);
        });
    }
};
