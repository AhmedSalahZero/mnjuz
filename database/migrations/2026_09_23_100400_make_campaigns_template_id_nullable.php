<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * template_id يصير اختيارياً.
 *
 * الحملة على قناة غير واتساب لا قالب لها. والعمود اليوم `int NOT NULL` بلا
 * قيمة افتراضية، و«جعل العمود يقبل NULL» ليس ضمن عمليات INSTANT في
 * MySQL 8.4 — مُتحقَّق منه:
 *
 *   ALGORITHM=INSTANT
 *     → ERROR 1845: ALGORITHM=INSTANT is not supported for this operation.
 *       (خطأ صريح، لا هبوط صامت)
 *   ALGORITHM=INPLACE, LOCK=NONE
 *     → نجحت. وهي إعادة بناء كاملة — يتغيّر table_id وspace بعدها — لكنّها
 *       على الخطّ: القراءة والكتابة تستمرّان عبر سجلّ التعديل.
 *
 * ١٫٣ ثانية على ١٬٩٣٧ صفّاً. وcampaigns ليس على مسار الرسائل الواردة أصلاً.
 *
 * والبديل عند الرفض: COPY مع LOCK=SHARED — ستّة ميغابايت، أقلّ من ثانية،
 * والكتابة محجوبة خلالها وحدها.
 *
 * انتبه: MODIFY يُعيد كتابة تعريف العمود كاملاً، فما لا يُذكَر يسقط.
 * template_id مجرّد `int NOT NULL` بلا قيمة افتراضية ولا تعليق، فـ INT NULL
 * تعريفٌ تامّ. راجع SHOW CREATE TABLE قبل التشغيل تحسّباً لتغيّر طرأ.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        if (!$this->templateIdIsRequired()) {
            return;
        }

        DB::statement('SET SESSION lock_wait_timeout = 10');

        try {
            DB::statement('ALTER TABLE campaigns MODIFY template_id INT NULL, ALGORITHM=INPLACE, LOCK=NONE');
        } catch (\Illuminate\Database\QueryException $e) {
            // ١٨٤٥ و١٨٤٦: المحرّك رفض INPLACE أو LOCK=NONE. الجدول صغير
            // (ستّة ميغابايت) فـ COPY هنا أقلّ من ثانية، وحجب الكتابة خلالها
            // لا يمسّ مساراً حسّاساً.
            if (!in_array($e->errorInfo[1] ?? null, [1845, 1846], true)) {
                throw $e;
            }

            DB::statement('ALTER TABLE campaigns MODIFY template_id INT NULL, ALGORITHM=COPY, LOCK=SHARED');
        }
    }

    /**
     * لا نُعيد template_id إلى NOT NULL: قد تكون حملات قنوات أخرى أُنشئت
     * بقيمة NULL، فإعادة القيد إمّا تُفشل الترحيل العكسي وإمّا تُتلف البيانات.
     * التراجع يترك العمود متساهلاً — وهو غير ضارّ: واتساب تملأه دائماً.
     */
    public function down(): void
    {
        // متروك عمداً.
    }

    private function templateIdIsRequired(): bool
    {
        $column = DB::selectOne(
            "SELECT IS_NULLABLE FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = 'campaigns' AND column_name = 'template_id'"
        );

        return $column !== null && strtoupper($column->IS_NULLABLE) === 'NO';
    }
};
