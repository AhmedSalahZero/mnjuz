<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::table('chat_media', function (Blueprint $table) {
            // Modify the type field to be nullable
            $table->string('type')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('chat_media', function (Blueprint $table) {
            // Revert the type field to be not nullable
            $table->string('type')->nullable(false)->change();
        });
    }
};
