<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * تحويل `channel_accounts.avatar` من VARCHAR(255) إلى TEXT.
 *
 * رابط صورة الصفحة الذي تُرجعه Meta (وإنستغرام وتيك توك) رابط CDN موقَّع
 * يتجاوز ٢٥٥ حرفاً كثيراً، فكان ربط الحساب يسقط بعد نجاح التفويض:
 *
 *     SQLSTATE[22001] 1406 Data too long for column 'avatar'
 *
 * ولا نقصّ الرابط: التوقيع جزء منه، والمقصوص لا يفتح.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        if (!Schema::hasTable('channel_accounts')) {
            return;
        }

        DB::statement('ALTER TABLE channel_accounts MODIFY avatar TEXT NULL');
    }

    /**
     * لا نُضيّق العمود في التراجع: تضييقه يقصّ الروابط المحفوظة بعده.
     */
    public function down(): void
    {
        // متروك عمداً.
    }
};
