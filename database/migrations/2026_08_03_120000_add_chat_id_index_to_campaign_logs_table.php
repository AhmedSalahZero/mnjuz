<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * بلاغ فشل واتساب يصل ومعه chat_id فقط، ونحتاج الوصول إلى campaign_logs عبره.
 * الفهرس الموجود (campaign_id, contact_id, chat_id) لا يخدم البحث بـ chat_id
 * وحده لأنه ليس العمود الأول، فكل بلاغ فشل كان سيمسح الجدول كاملاً.
 */
return new class extends Migration
{
    public function up(): void
    {
        if ($this->indexExists('campaign_logs', 'idx_campaign_logs_chat_id')) {
            return;
        }

        Schema::table('campaign_logs', function (Blueprint $table) {
            $table->index('chat_id', 'idx_campaign_logs_chat_id');
        });
    }

    public function down(): void
    {
        if (!$this->indexExists('campaign_logs', 'idx_campaign_logs_chat_id')) {
            return;
        }

        Schema::table('campaign_logs', function (Blueprint $table) {
            $table->dropIndex('idx_campaign_logs_chat_id');
        });
    }

    private function indexExists(string $table, string $index): bool
    {
        return count(DB::select(
            'SHOW INDEX FROM ' . $table . ' WHERE Key_name = ?',
            [$index]
        )) > 0;
    }
};
