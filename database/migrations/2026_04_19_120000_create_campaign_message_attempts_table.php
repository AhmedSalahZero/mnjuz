<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_message_attempts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('campaign_id');
            $table->unsignedBigInteger('campaign_log_id');
            $table->unsignedBigInteger('contact_id')->nullable();
            $table->string('channel', 30)->default('whatsapp');
            $table->unsignedSmallInteger('attempt_number');
            $table->boolean('is_retry')->default(false);
            $table->string('status', 20);
            $table->text('failure_reason')->nullable();
            $table->longText('response_metadata')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamps();

            $table->unique(['campaign_log_id', 'channel', 'attempt_number'], 'cmp_attempt_unique');
            $table->index(['campaign_id', 'status', 'executed_at'], 'cmp_campaign_status_exec_idx');
            $table->index(['contact_id', 'channel'], 'cmp_contact_channel_idx');
            $table->index(['campaign_log_id', 'executed_at'], 'cmp_log_exec_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_message_attempts');
    }
};
