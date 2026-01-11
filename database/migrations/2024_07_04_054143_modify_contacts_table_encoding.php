<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class ModifyContactsTableEncoding extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // Modify the table to use utf8mb4
        Schema::table('contacts', function (Blueprint $table) {
            DB::statement('ALTER TABLE contacts CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        });

        // Modify the specific column to use utf8mb4
        Schema::table('contacts', function (Blueprint $table) {
            $table->string('first_name', 128)->nullable()->charset('utf8mb4')->collation('utf8mb4_unicode_ci')->change();
            $table->string('last_name', 128)->nullable()->charset('utf8mb4')->collation('utf8mb4_unicode_ci')->change();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        // Revert the table to its original charset and collation
        Schema::table('contacts', function (Blueprint $table) {
            DB::statement('ALTER TABLE contacts CONVERT TO CHARACTER SET utf8 COLLATE utf8_general_ci');
        });

        // Revert the specific columns to their original charset and collation
        Schema::table('contacts', function (Blueprint $table) {
            $table->string('first_name', 128)->nullable(false)->charset('utf8')->collation('utf8_general_ci')->change();
            $table->string('last_name', 128)->nullable(false)->charset('utf8')->collation('utf8_general_ci')->change();
        });
    }
}