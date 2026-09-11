<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * مهلة إعادة تشغيل الـ flow بالدقائق.
 *
 * الـ flow يُحذف سجلّ جلسته حين يبلغ عقدته الأخيرة، فمن راسلنا مرّة لا يرى
 * الردّ الآلي بعدها أبداً — لا بعد ساعة ولا بعد سنة. المهلة تفصل محادثةً عن
 * أخرى: من عاد بعدها فقد بدأ محادثة جديدة، والترحيب يستحقّه من جديد.
 *
 * ترحيلات وحدة FlowBuilder لا تعمل مع `php artisan migrate`، فالعمود مضاف
 * في ترحيل إنشاء الجدول (للتثبيت الجديد) وهنا (للقواعد القائمة).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('flows') || Schema::hasColumn('flows', 'trigger_timeout')) {
            return;
        }

        Schema::table('flows', function (Blueprint $table) {
            $table->unsignedInteger('trigger_timeout')->nullable()->after('keywords');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('flows') || !Schema::hasColumn('flows', 'trigger_timeout')) {
            return;
        }

        Schema::table('flows', function (Blueprint $table) {
            $table->dropColumn('trigger_timeout');
        });
    }
};
