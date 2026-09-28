<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Modules\FlowBuilder\Support\FlowKeywords;

/**
 * إصلاح بيانات: تطبيع كلمات تشغيل الـ flows.
 *
 * ما يُصلحه
 * ─────────
 * الكلمات تُخزَّن نصّاً مفصولاً بفاصلة، والمستخدم يكتبها «أ, ب, ج» بفاصلة
 * **ومسافة**. وكانت المطابقة تجري بـ `FIND_IN_SET(?, keywords)` في MySQL،
 * وهي لا تقصّ المسافات — فتصير عناصر المجموعة « ب» و« ج» بمسافة بادئة،
 * ولا يطابقها بحثٌ عن «ب».
 *
 * النتيجة: **الكلمة الأولى وحدها تعمل، وكل ما بعدها ميّت.**
 *
 * قياس ٢٤ سبتمبر ٢٠٢٦ على نسخة الإنتاج:
 *
 *     ٩١ كلمة في ٦٤ flow
 *     ٢٦ منها (٢٩٪) لا تُطابَق أبداً
 *     ٣ flows فعّالة متأثّرة في ٣ منشآت مختلفة
 *
 * ومنها كلمة `STOP` لإيقاف الرسائل التسويقية: العميل يكتبها، فلا يُشغَّل
 * الـ flow، ولا يُزال من المجموعات — وهي الشكوى التي وصلت.
 *
 * والمطابقة نفسها صارت تُطبّع عند القراءة (FlowKeywords::matches)، فالإصلاح
 * يعمل حتى بلا هذا الترحيل. لكنّنا نُطبّع العمود كذلك حتى تكون البيانات
 * المخزَّنة صحيحة في نفسها — يقرؤها التصدير والتقارير وأي كود يأتي لاحقاً
 * ولا يعرف بالحيلة.
 *
 * وهو آمن: لا يُغيّر إلّا صفوفاً تطبيعها يختلف عن محتواها، ولا يحذف كلمة
 * واحدة — يقصّ المسافات ويُوحّد حالة الأحرف ويُزيل التكرار.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('flows')) {
            return;
        }

        $changed = 0;
        $revived = 0;

        DB::table('flows')
            ->whereNotNull('keywords')
            ->where('keywords', '!=', '')
            ->orderBy('id')
            ->chunkById(200, function ($flows) use (&$changed, &$revived) {
                foreach ($flows as $flow) {
                    $normalized = FlowKeywords::normalizeForStorage($flow->keywords);

                    if ($normalized === null || $normalized === $flow->keywords) {
                        continue;
                    }

                    // كم كلمة كانت ميّتة قبل التطبيع؟ للسجلّ لا أكثر.
                    $revived += $this->deadKeywordCount($flow->keywords);

                    DB::table('flows')->where('id', $flow->id)->update([
                        'keywords' => $normalized,
                    ]);

                    $changed++;
                }
            });

        Log::info('Flow keywords normalized', [
            'flows_updated' => $changed,
            'keywords_revived' => $revived,
        ]);

        if ($changed > 0) {
            echo "تطبيع كلمات الـ flows: {$changed} flow — أُحييت {$revived} كلمة كانت لا تُطابَق." . PHP_EOL;
        }
    }

    /**
     * لا تراجع: الصيغة القديمة معطوبة بطبيعتها، وإعادتها تُميت الكلمات
     * من جديد. والصيغة الجديدة تُطابِق كل ما كانت تُطابقه القديمة وزيادة.
     */
    public function down(): void
    {
        // متروك عمداً.
    }

    /** كم كلمة في هذا الصفّ كانت `FIND_IN_SET` تعجز عن مطابقتها؟ */
    private function deadKeywordCount(string $raw): int
    {
        $dead = 0;

        foreach (FlowKeywords::parse($raw) as $keyword) {
            $found = DB::selectOne('SELECT FIND_IN_SET(?, ?) AS p', [$keyword, $raw]);

            if (!($found->p ?? 0)) {
                $dead++;
            }
        }

        return $dead;
    }
};
