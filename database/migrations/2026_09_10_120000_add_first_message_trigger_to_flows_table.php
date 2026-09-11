<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * محفّز ثالث للـ flow: أوّل رسالة يكتبها العميل.
 *
 * `new_contact` يسأل «هل صفّ جهة الاتصال أُنشئ الآن؟» لا «هل هذه أوّل مرة
 * يكلّمنا فيها؟». والرقم يدخل النظام من الاستيراد والحملات والإضافة اليدوية
 * قبل أن يكتب صاحبه حرفاً، فتفوت رسالة الترحيب على أكثر العملاء.
 *
 * ALTER خام لا Schema: تعديل enum يحتاج doctrine/dbal وهي غير مثبّتة.
 */
return new class extends Migration
{
    public function up(): void
    {
        // التثبيت الجديد يُنشئ العمود بالقيمة أصلاً (ترحيل الوحدة)، وهذا
        // الترحيل للقواعد القائمة وحدها.
        if (!Schema::hasTable('flows') || $this->hasFirstMessage()) {
            return;
        }

        DB::statement(
            'ALTER TABLE `' . $this->table() . '` MODIFY COLUMN `trigger` '
            . "ENUM('new_contact', 'keywords', 'first_message') NULL"
        );
    }

    public function down(): void
    {
        if (!Schema::hasTable('flows')) {
            return;
        }

        // القيمة الجديدة تُعطَّل قبل تضييق العمود، وإلا رفض MySQL الصفوف
        // الحاملة لها أو حوّلها إلى فراغ صامتاً.
        DB::table($this->table())->where('trigger', 'first_message')->update([
            'trigger' => null,
            'status'  => 'inactive',
        ]);

        DB::statement(
            'ALTER TABLE `' . $this->table() . '` MODIFY COLUMN `trigger` '
            . "ENUM('new_contact', 'keywords') NULL"
        );
    }

    private function hasFirstMessage(): bool
    {
        $column = DB::selectOne('SHOW COLUMNS FROM `' . $this->table() . '` LIKE ?', ['trigger']);

        return $column !== null && str_contains((string) $column->Type, 'first_message');
    }

    private function table(): string
    {
        return DB::getTablePrefix() . 'flows';
    }
};
