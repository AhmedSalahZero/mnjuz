<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * جدول chat_logs لم يكن عليه index على contact_id إطلاقاً — المفتاح
     * الأساسي و(entity_id, entity_type) فقط. ومزامنة التطبيق تستعلمه بـ
     * contact_id مرّتين:
     *
     *   EXISTS (SELECT 1 FROM chat_logs WHERE contact_id = contacts.id ...)
     *   SELECT * FROM chat_logs WHERE contact_id IN (...) ORDER BY created_at
     *
     * فكلاهما مسحٌ كامل للجدول، والأول يتكرّر بعدد جهات الاتصال. هذا سبب
     * تجاوز مهلة الـ 120 ثانية في /api/v1/list-messages-from-uuid-to-end.
     *
     * ترتيب الأعمدة يتبع الاستعلام: مساواة على contact_id ثم deleted_at،
     * ثم مدى على created_at.
     *
     * قواعد بعض البيئات أُضيف إليها index يبدأ بـ contact_id يدوياً خارج
     * الترحيلات، فنتخطّى حينها بدل بناء نسخة ثانية على جدول من الملايين.
     */
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        if (!$this->hasIndexLeadingWith('chat_logs', 'contact_id')) {
            Schema::table('chat_logs', function (Blueprint $table) {
                $table->index(
                    ['contact_id', 'deleted_at', 'created_at'],
                    'idx_chat_logs_contact_deleted_created'
                );
            });
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        if ($this->indexExists('chat_logs', 'idx_chat_logs_contact_deleted_created')) {
            Schema::table('chat_logs', function (Blueprint $table) {
                $table->dropIndex('idx_chat_logs_contact_deleted_created');
            });
        }
    }

    protected function indexExists(string $table, string $index): bool
    {
        $indexes = DB::select("SHOW INDEXES FROM `{$table}` WHERE Key_name = ?", [$index]);

        return count($indexes) > 0;
    }

    /** هل على الجدول index عموده الأول هو هذا؟ */
    protected function hasIndexLeadingWith(string $table, string $column): bool
    {
        $indexes = DB::select(
            "SHOW INDEXES FROM `{$table}` WHERE Seq_in_index = 1 AND Column_name = ?",
            [$column]
        );

        return count($indexes) > 0;
    }
};
