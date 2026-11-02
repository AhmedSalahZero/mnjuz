<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const COLUMN = 'latest_contact_id';
    private const INDEX = 'uq_chat_tickets_single_latest';

    /**
     * تذكرة «أحدث» واحدة لكل جهة اتصال — بقيد في قاعدة البيانات لا بالاتفاق.
     *
     * العطل: عمود is_latest يُفترض أن يحمل 1 في صفّ واحد لكل جهة اتصال، لكن
     * لا شيء كان يضمن ذلك. أول رسالة من عميل جديد تُنشئ تذكرته في ProcessTicketAssignmentJob،
     * وفي اللحظة نفسها قد تُنشئ صفحة المحادثات تذكرة ثانية (ensureChatTicketsExist)
     * لأن العميل صار «بلا تذكرة» في لقطتها — وكانت تستعمل insertOrIgnore ظنّاً
     * أن الجدول يمنع التكرار، وهو لا يمنعه. النتيجة صفّان بـ is_latest = 1:
     * يظهر العميل مرتين في قائمة المحادثات، وتُحدَّث الصفّان معاً فتتشوّه الحالة.
     *
     * الإصلاح في خطوتين: ننظّف ما تراكم، ثم نضيف قيداً فريداً على عمود محسوب
     * يساوي contact_id حين is_latest = 1 وNULL غير ذلك. MySQL لا يملك فهرساً
     * جزئياً، لكن القيم NULL لا تتصادم في الفهرس الفريد — فيقيّد الصفوف الأحدث
     * وحدها ويترك الأقدم بلا قيد.
     *
     * العمود VIRTUAL فلا يخزَّن ولا يعيد بناء الجدول، والفهرس يُبنى online
     * (INPLACE + LOCK=NONE) — الكتابة على الجدول لا تتوقف أثناءه. والجملتان
     * منفصلتان عمداً: MySQL يرفض إضافة عمود افتراضي مع أي إجراء آخر في INPLACE.
     * ونصّ ALGORITHM/LOCK صريح كي يفشل الترحيل بوضوح إن تعذّر online بدل أن
     * يقفل الجدول بصمت.
     */
    public function up(): void
    {
        $this->demoteExtraLatestTickets();

        if (!Schema::hasColumn('chat_tickets', self::COLUMN)) {
            DB::statement(
                'ALTER TABLE chat_tickets ADD COLUMN ' . self::COLUMN . ' INT '
                . 'GENERATED ALWAYS AS (IF(is_latest = 1, contact_id, NULL)) VIRTUAL, '
                . 'ALGORITHM=INPLACE, LOCK=NONE'
            );
        }

        if (!$this->indexExists()) {
            DB::statement(
                'ALTER TABLE chat_tickets ADD UNIQUE INDEX ' . self::INDEX . ' (' . self::COLUMN . '), '
                . 'ALGORITHM=INPLACE, LOCK=NONE'
            );
        }
    }

    public function down(): void
    {
        if ($this->indexExists()) {
            DB::statement('ALTER TABLE chat_tickets DROP INDEX ' . self::INDEX . ', ALGORITHM=INPLACE, LOCK=NONE');
        }

        if (Schema::hasColumn('chat_tickets', self::COLUMN)) {
            DB::statement('ALTER TABLE chat_tickets DROP COLUMN ' . self::COLUMN . ', ALGORITHM=INPLACE, LOCK=NONE');
        }
    }

    /**
     * لكل جهة اتصال لها أكثر من صفّ is_latest = 1: يبقى صفٌّ واحد ويُنزَّل الباقي إلى 0.
     * لا يُحذف أيّ صفّ: الصفوف المُنزَّلة تبقى تاريخاً.
     *
     * الفائز (بهذا الترتيب):
     *  1. الأحدث تحديثاً — صفٌّ أُعيد فتحه لاحقاً يحمل الحالة الحيّة، وReopen في
     *     ProcessTicketAssignmentJob يحدّث أعلى id وحده فيتباعد الصفّان.
     *  2. ثم من له موظف مسند: عند التعادل (الصفّان وُلدا في الثانية نفسها) أحدهما
     *     من الإسناد الآلي بموظف، والآخر من صفحة المحادثات بلا موظف.
     *  3. ثم الأعلى id.
     * الترتيب كلّي، فلكلّ جهة اتصال فائز واحد بالضبط.
     *
     * الجدول المشتق d يحصر العمل في الجهات المكرَّرة (مئات) بدل أن يمرّ على كل
     * صفّ في الجدول: كانت الجملة بدونه تمسح الجدول كله وتبحث عن منافس لكل صفّ،
     * فتأخذ 2–20 ثانية على 445 ألف صفّ بحسب حرارة الذاكرة، وتأخذ الآن أقلّ من
     * ثانية لأن d يُحسب من الفهرس (contact_id, is_latest) وحده.
     *
     * جملة self-join واحدة بدل دوال النوافذ كي تعمل على MySQL 5.7 أيضاً. وصحّتها
     * لا تتأثر بترتيب معالجة الصفوف: الفائز لا يهزمه أحد فلا يُنزَّل أبداً، وكلّ
     * خاسر يهزمه الفائز نفسه وهو ما زال 1.
     *
     * @param string $table اسم الجدول — يُمرَّر في الاختبار ليجري على جدول مؤقت
     *                      يقبل بذور التكرار التي يمنعها القيد على الجدول الحقيقي.
     */
    public function demoteExtraLatestTickets(string $table = 'chat_tickets'): int
    {
        $stamp = static fn (string $alias) => "COALESCE({$alias}.updated_at, {$alias}.created_at, '1970-01-01')";

        return DB::affectingStatement("
            UPDATE {$table} t
            JOIN (
                SELECT contact_id FROM {$table} WHERE is_latest = 1
                GROUP BY contact_id HAVING COUNT(*) > 1
            ) d ON d.contact_id = t.contact_id
            JOIN {$table} k
              ON k.contact_id = t.contact_id AND k.is_latest = 1 AND k.id <> t.id
             AND (
                  {$stamp('k')} > {$stamp('t')}
               OR ({$stamp('k')} = {$stamp('t')} AND (
                    (k.assigned_to IS NOT NULL) > (t.assigned_to IS NOT NULL)
                 OR ((k.assigned_to IS NOT NULL) = (t.assigned_to IS NOT NULL) AND k.id > t.id)
               ))
             )
            SET t.is_latest = 0
            WHERE t.is_latest = 1
        ");
    }

    private function indexExists(): bool
    {
        return DB::table('information_schema.statistics')
            ->where('table_schema', DB::raw('DATABASE()'))
            ->where('table_name', 'chat_tickets')
            ->where('index_name', self::INDEX)
            ->exists();
    }
};
