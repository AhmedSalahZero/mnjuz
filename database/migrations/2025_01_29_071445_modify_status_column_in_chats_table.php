<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('chats', function (Blueprint $table) {
            $table->string('status')->nullable()->change(); // Make 'status' nullable
        });
    }

    public function down()
    {
        Schema::table('chats', function (Blueprint $table) {
            $table->string('status')->nullable(false)->change(); // Revert to NOT NULL if needed
        });
    }
};
