<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateFlowLogsTable extends Migration
{
    public function up()
    {
        Schema::create('flow_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flow_id')->constrained('flows')->onDelete('cascade');
            $table->unsignedBigInteger('chat_id');
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('flow_logs');
    }
}