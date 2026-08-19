<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * سبب إبطال رمز الوصول، ليعرف تطبيق الجوال لماذا خرج.
 *
 * حين يُحذف الرمف لا يبقى ما يُربط به الطلب التالي بصاحبه، فيصل التطبيق 401
 * مجرّداً لا يميّز «انتهت جلستك» من «دخلتَ من جهاز آخر». لذلك عند الطرد نُبقي
 * الصفّ ونضع له expires_at ماضياً — وSanctum يرفض المنتهي (Guard.php سطر 160)
 * فالأمان محفوظ — ونسجّل السبب هنا ليُقرأ عند أول طلب تالٍ.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->string('revoked_reason', 32)->nullable()->after('expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropColumn('revoked_reason');
        });
    }
};
