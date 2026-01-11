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
        Schema::table('contacts', function (Blueprint $table) {
            $table->timestamp('latest_chat_created_at')->nullable()->after('email');
            $table->index('organization_id');
            $table->index('deleted_at');
            $table->index('latest_chat_created_at');
        });

        Schema::table('chats', function (Blueprint $table) {
            $table->index('contact_id');
            $table->index('created_at');
        });

        Schema::table('chat_tickets', function (Blueprint $table) {
            $table->index('contact_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn('latest_chat_created_at');
            $table->dropIndex(['organization_id']);
            $table->dropIndex(['deleted_at']);
            $table->dropIndex(['latest_chat_created_at']);
        });

        Schema::table('chats', function (Blueprint $table) {
            $table->dropIndex(['contact_id']);
            $table->dropIndex(['created_at']);
        });

        Schema::table('chat_tickets', function (Blueprint $table) {
            $table->dropIndex(['contact_id']);
        });
    }
};
