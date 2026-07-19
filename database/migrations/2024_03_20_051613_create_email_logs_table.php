<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateEmailLogsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('email_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->char('uuid', 50)->unique();
            $table->integer('user_id')->nullable();
            $table->string('recipient');
            $table->string('subject');
            $table->text('message');
            $table->enum('status', ['queued', 'sent', 'failed'])->default('queued');
            $table->integer('attempts')->default(0);
            $table->text('metadata')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('email_logs');
    }
}
