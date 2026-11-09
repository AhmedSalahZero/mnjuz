<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * رسائل الاستيراد تُعرَف بعمود لا ببادئة في wam_id.
     *
     * به يتراجع المستخدم عن استيراد بعينه، وتستثنيه المقاييس التي تقيس نشاط
     * الفريق الحيّ (زمن الردّ الأوّل، رسوم اللوحة اليومية) — فالتاريخ المستورد
     * لم يحدث على هذه المنصّة.
     *
     * جدول chats ضخم (ملايين الصفوف)، فالإضافة INSTANT والفهرس INPLACE بلا
     * قفل، ومهلة انتظار القفل قصيرة: إن كان الجدول مشغولاً يفشل الترحيل فوراً
     * بدل أن يُجمّد كتابة الرسائل الواردة خلفه.
     */
    public function up(): void
    {
        if (!Schema::hasColumn('chats', 'chat_import_id')) {
            $this->online(
                'ALTER TABLE chats ADD COLUMN chat_import_id BIGINT UNSIGNED NULL, ALGORITHM=INSTANT',
                fn () => Schema::table('chats', fn (Blueprint $table) => $table->unsignedBigInteger('chat_import_id')->nullable())
            );
        }

        if (!$this->hasIndex('chats', 'idx_chats_chat_import_id')) {
            $this->online(
                'ALTER TABLE chats ADD INDEX idx_chats_chat_import_id (chat_import_id), ALGORITHM=INPLACE, LOCK=NONE',
                fn () => Schema::table('chats', fn (Blueprint $table) => $table->index('chat_import_id', 'idx_chats_chat_import_id'))
            );
        }
    }

    public function down(): void
    {
        if ($this->hasIndex('chats', 'idx_chats_chat_import_id')) {
            Schema::table('chats', fn (Blueprint $table) => $table->dropIndex('idx_chats_chat_import_id'));
        }

        if (Schema::hasColumn('chats', 'chat_import_id')) {
            Schema::table('chats', fn (Blueprint $table) => $table->dropColumn('chat_import_id'));
        }
    }

    /** الصيغة المباشرة على MySQL، والبديل العادي لما سواه. */
    private function online(string $sql, callable $fallback): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $fallback();

            return;
        }

        DB::statement('SET SESSION lock_wait_timeout = 5');
        DB::statement($sql);
    }

    /**
     * من information_schema مباشرةً لا Schema::hasIndex: تلك أُضيفت في إصدار
     * متأخّر من Laravel 10، وخادمٌ بـ vendor أقدم يسقط عندها الترحيل في
     * منتصفه — بعد إضافة العمود وقبل الفهرس.
     */
    private function hasIndex(string $table, string $index): bool
    {
        if (DB::getDriverName() === 'mysql') {
            return DB::table('information_schema.statistics')
                ->where('table_schema', DB::getDatabaseName())
                ->where('table_name', $table)
                ->where('index_name', $index)
                ->exists();
        }

        return method_exists(Schema::getFacadeRoot(), 'getIndexes')
            && collect(Schema::getIndexes($table))->contains(fn ($i) => ($i['name'] ?? null) === $index);
    }
};
