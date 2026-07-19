<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_media_history', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->char('uuid', 50)->unique();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->enum('media_type', ['IMAGE', 'DOCUMENT', 'VIDEO']);
            $table->string('name');
            $table->string('path', 512);
            $table->enum('location', ['local', 'amazon'])->default('local');
            $table->string('mime_type', 128)->nullable();
            $table->string('size', 64)->nullable();
            $table->unsignedBigInteger('chat_media_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->softDeletes();

            $table->index(['organization_id', 'media_type', 'deleted_at'], 'campaign_media_history_org_type_idx');
            $table->index(['organization_id', 'path'], 'campaign_media_history_org_path_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_media_history');
    }
};
