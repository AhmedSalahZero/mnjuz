<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('campaign_log_retries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('campaign_log_id'); // foreign key to campaign_logs.id
            $table->unsignedBigInteger('chat_id')->nullable(); // each retry creates a new chat
            $table->text('metadata')->nullable();
            $table->string('status')->nullable(); // success, failed, etc.
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('campaign_log_retries');
    }
};
