<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ميتاداتا المسار LONGTEXT.
 *
 * ترحيل الإنشاء جعلها TEXT (64 KB)، والمحرّر يحفظ كل حافّة مع نسختي عقدتيها،
 * فمسار قائمة متوسّط يتجاوزها ويسقط الحفظ بـ «Data too long» دون أن يرى
 * المستخدم السبب. بعض القواعد عُدّلت يدوياً إلى MEDIUMTEXT، وفيها مسارات
 * تبلغ 365 KB.
 *
 * ترحيلات وحدة FlowBuilder لا تعمل مع `php artisan migrate`، فالعمود مُعدَّل
 * في ترحيل إنشاء الجدول (للتثبيت الجديد) وهنا (للقواعد القائمة).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('flows') || DB::getDriverName() !== 'mysql' || $this->columnType() === 'longtext') {
            return;
        }

        DB::statement('ALTER TABLE `flows` MODIFY `metadata` LONGTEXT NULL');
    }

    public function down(): void
    {
        // لا تضييق: إعادتها TEXT تقصّ المسارات الكبيرة القائمة.
    }

    private function columnType(): ?string
    {
        $column = DB::selectOne(
            'SELECT DATA_TYPE AS type FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            ['flows', 'metadata']
        );

        return $column ? strtolower($column->type) : null;
    }
};
