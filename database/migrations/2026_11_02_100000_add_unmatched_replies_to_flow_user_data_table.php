<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * عدد الردود التي لم تطابق أيّ زرّ أو صفّ في العقدة الحالية.
 *
 * العميل الذي يكتب نصّاً حرّاً أمام أزرار كان يبقى في الـ flow بلا ردّ إلى
 * الأبد، والردود الآلية والمساعد الذكي مغلقة عنه ما دام فيه. الآن يُعاد عليه
 * السؤال مرّة، وفي الثانية يخرج من الـ flow وتُجيبه بقية الردود.
 *
 * ترحيلات وحدة FlowBuilder لا تعمل مع `php artisan migrate`، فالعمود مضاف
 * في ترحيل إنشاء الجدول (للتثبيت الجديد) وهنا (للقواعد القائمة).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('flow_user_data') || Schema::hasColumn('flow_user_data', 'unmatched_replies')) {
            return;
        }

        Schema::table('flow_user_data', function (Blueprint $table) {
            $table->unsignedTinyInteger('unmatched_replies')->default(0)->after('current_step');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('flow_user_data') || !Schema::hasColumn('flow_user_data', 'unmatched_replies')) {
            return;
        }

        Schema::table('flow_user_data', function (Blueprint $table) {
            $table->dropColumn('unmatched_replies');
        });
    }
};
