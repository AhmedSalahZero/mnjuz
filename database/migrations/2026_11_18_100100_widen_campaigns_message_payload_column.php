<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * توسيع `campaigns.message_payload` من TEXT إلى MEDIUMTEXT.
 *
 * حملة البريد من قالب مصمَّم تنسخ تصميمه كلّه (design) مع نسخته النصّية،
 * وTEXT حدّه 64KB: قالب طويل بالعربية كان سيسقط الإنشاء بـ
 * «Data too long for column».
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        if (!Schema::hasTable('campaigns')) {
            return;
        }

        DB::statement('ALTER TABLE campaigns MODIFY message_payload MEDIUMTEXT NULL');
    }

    /**
     * لا نُضيّق العمود في التراجع: تضييقه يقصّ الحمولات المحفوظة بعده.
     */
    public function down(): void
    {
        // متروك عمداً.
    }
};
