<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * توسيع `templates.name` من ١٢٨ إلى ٥١٢ حرفاً.
 *
 * عمودنا كان **أضيق من عقد Meta**: واتساب تسمح باسم قالب حتى ٥١٢ حرفاً،
 * ونحن نخزّن ١٢٨. فأي قالب باسم أطول يُسقط الإدراج:
 *
 *     SQLSTATE[22001] 1406 Data too long for column 'name'
 *
 * ووقع فعلاً: منشأة ٦ فيها قالب اسمه رابط خرائط جوجل مُجرَّداً من رموزه
 * (نحو ٤٠٠ حرف) — أنشأه أحدهم بلصق الرابط في خانة الاسم. فتعثّرت مزامنة
 * القوالب عنده.
 *
 * ولماذا التوسيع لا القصّ؟ لأن الاسم هو ما نُرسله إلى Meta عند استعمال
 * القالب. واسمٌ مقصوص يُقبَل في قاعدتنا ثم تردّه Meta برسالة غامضة عند أول
 * إرسال — أسوأ من رفضٍ صريح. فالصواب أن يتّسع عمودنا لما تسمح به Meta.
 *
 * الكلفة: `ALGORITHM=INPLACE, LOCK=NONE` — مُتحقَّق منه على نسخة من الجدول.
 * لا يُعيد بناءً ولا يحجب قراءةً ولا كتابة، والجدول ١٠٨٣ صفّاً أصلاً.
 *
 * (INSTANT غير متاحة لتغيير طول VARCHAR — تردّ ERROR 1845.)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        if (!Schema::hasTable('templates')) {
            return;
        }

        if ($this->nameLength() >= 512) {
            return;
        }

        DB::statement('SET SESSION lock_wait_timeout = 10');
        DB::statement('ALTER TABLE templates MODIFY name VARCHAR(512) NOT NULL, ALGORITHM=INPLACE, LOCK=NONE');
    }

    /**
     * لا نُضيّق العمود في التراجع: قد تكون أسماء أطول من ١٢٨ حُفظت بعده،
     * وتضييقه يقصّها بلا رجعة.
     */
    public function down(): void
    {
        // متروك عمداً.
    }

    private function nameLength(): int
    {
        $column = DB::selectOne(
            "SELECT CHARACTER_MAXIMUM_LENGTH AS len FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = 'templates' AND column_name = 'name'"
        );

        return (int) ($column->len ?? 0);
    }
};
