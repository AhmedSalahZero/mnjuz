<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * إضافة عمود is_latest لتحديد آخر تذكرة لكل contact.
     * يستخدم في JOIN لتفادي تكرار الـ contact عند وجود أكثر من تذكرة.
     */
    public function up(): void
    {
        Schema::table('chat_tickets', function (Blueprint $table) {
            $table->boolean('is_latest')->default(false)->after('status');
        });

        // تعيين is_latest = true فقط لآخر تذكرة (أعلى id) لكل contact_id
        DB::statement("
            UPDATE chat_tickets t1
            INNER JOIN (
                SELECT contact_id, MAX(id) AS max_id
                FROM chat_tickets
                GROUP BY contact_id
            ) t2 ON t1.contact_id = t2.contact_id AND t1.id = t2.max_id
            SET t1.is_latest = 1
        ");

        Schema::table('chat_tickets', function (Blueprint $table) {
            $table->index(['contact_id', 'is_latest'], 'idx_chat_tickets_contact_is_latest');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('chat_tickets', function (Blueprint $table) {
            $table->dropIndex('idx_chat_tickets_contact_is_latest');
        });
        Schema::table('chat_tickets', function (Blueprint $table) {
            $table->dropColumn('is_latest');
        });
    }
};
