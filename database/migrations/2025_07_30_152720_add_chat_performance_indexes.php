<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddChatPerformanceIndexes extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // Add index for chat_status_logs table
        Schema::table('chat_status_logs', function (Blueprint $table) {
            $table->index('chat_id', 'idx_chat_id');
        });

        // Add index for campaign_logs table
        Schema::table('campaign_logs', function (Blueprint $table) {
            $table->index(['id', 'status'], 'idx_campaign_id_status');
        });

        // Add indexes for chats table
        Schema::table('chats', function (Blueprint $table) {
            $table->index(['id', 'status'], 'idx_chats_id_status');
            $table->index(['organization_id', 'type', 'is_read', 'deleted_at'], 'idx_chats_org_type_read_deleted');
            $table->index(['contact_id', 'organization_id', 'deleted_at', 'created_at'], 'idx_chats_contact_org_deleted_created');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        // Remove indexes from chat_status_logs table
        Schema::table('chat_status_logs', function (Blueprint $table) {
            $table->dropIndex('idx_chat_id');
        });

        // Remove indexes from campaign_logs table
        Schema::table('campaign_logs', function (Blueprint $table) {
            $table->dropIndex('idx_campaign_id_status');
        });

        // Remove indexes from chats table
        Schema::table('chats', function (Blueprint $table) {
            $table->dropIndex('idx_chats_id_status');
            $table->dropIndex('idx_chats_org_type_read_deleted');
            $table->dropIndex('idx_chats_contact_org_deleted_created');
        });
    }
} 