<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * انسحاب جهة الاتصال من الرسائل التسويقية.
 *
 * إزالة العميل من مجموعة لم تكن تمنع وصول الحملات إليه: الحملة الموجّهة
 * «للكل» تختار كل جهات اتصال المنشأة، بمجموعة أو بلا مجموعة. صار الانسحاب
 * علامةً مستقلّة عن العضوية، فتُستثنى في كل مسارات الحملات.
 *
 * عمود لا جدول منفصل: الاستثناء يجري داخل استعلام يختار عشرات الآلاف من
 * جهات الاتصال، وربطه بجدول آخر يُضيف وصلةً في أسخن استعلام في النظام.
 * والطابع الزمني يُبقي «متى انسحب» للامتثال، وهو ما لا يعطيه عمود منطقي.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->timestamp('marketing_opted_out_at')->nullable()->after('is_blocked');
            $table->index(['organization_id', 'marketing_opted_out_at'], 'idx_contacts_org_marketing_opt_out');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropIndex('idx_contacts_org_marketing_opt_out');
            $table->dropColumn('marketing_opted_out_at');
        });
    }
};
