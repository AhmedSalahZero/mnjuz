<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * assigned_seen يشير إلى ما إذا كان الوكيل المُسند إليه قد فتح المحادثة
     * بعد إسنادها له. القيمة 0 تعني "إسناد جديد لم يُشاهد بعد" فتظهر إشارة
     * في قائمة المحادثات حتى لو كان عدد الرسائل غير المقروءة صفر.
     * التذاكر الحالية تُعتبر مُشاهَدة (1) حتى لا تظهر إشارات رجعية.
     */
    public function up(): void
    {
        if (!Schema::hasColumn('chat_tickets', 'assigned_seen')) {
            Schema::table('chat_tickets', function (Blueprint $table) {
                $table->boolean('assigned_seen')->default(true)->after('is_latest');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('chat_tickets', 'assigned_seen')) {
            Schema::table('chat_tickets', function (Blueprint $table) {
                $table->dropColumn('assigned_seen');
            });
        }
    }
};
