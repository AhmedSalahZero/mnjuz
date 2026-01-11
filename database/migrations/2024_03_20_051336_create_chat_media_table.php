<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateChatMediaTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('chat_media', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name');
            $table->string('path')->nullable();
            $table->enum('location', ['local', 'amazon'])->default('local');
            $table->string('type', 128);
            $table->string('size', 128);
            $table->timestamp('created_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('chat_media');
    }
}

