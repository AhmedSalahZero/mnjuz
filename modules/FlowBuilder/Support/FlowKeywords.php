<?php

namespace Modules\FlowBuilder\Support;

/**
 * كلمات تشغيل الـ flow: تطبيعها ومطابقتها.
 *
 * العطب الذي يعالجه هذا الملفّ
 * ─────────────────────────────
 * الكلمات تُخزَّن نصّاً مفصولاً بفاصلة، والمستخدم يكتبها كما يكتب أي إنسان:
 * «ايقاف تلقي الاعلانات, إيقاف الأعلانات؟, STOP» — بفاصلة **ومسافة**.
 *
 * وكانت المطابقة تجري بـ `FIND_IN_SET(?, keywords)` في MySQL، وهي **لا
 * تقصّ المسافات**. فتصير عناصر المجموعة عند MySQL:
 *
 *     [0] = «ايقاف تلقي الاعلانات»
 *     [1] = « إيقاف الأعلانات؟»   ← مسافة بادئة
 *     [2] = « STOP»               ← مسافة بادئة
 *
 * والبحث عن «stop» لا يطابق « stop». فالكلمة الأولى وحدها تعمل، وما بعدها
 * ميّت — ولذلك بدا الـ flow «يعمل أحياناً».
 *
 * قِسناه على الإنتاج: **٢٦ من ٩١ كلمة (٢٩٪)** لا تُطابَق أبداً، في ثلاث
 * منشآت مختلفة. ومنها كلمة STOP لإيقاف الرسائل التسويقية — فالعميل يكتبها
 * ولا يُزال من المجموعات.
 *
 * الحلّ هنا: المطابقة في PHP بعد التطبيع، لا في SQL. عدد الـ flows لكل
 * منشأة قليل (٦٤ في النظام كلّه)، والاستعلام واحد كما كان.
 */
final class FlowKeywords
{
    /**
     * تفكيك نصّ الكلمات إلى قائمة مطبَّعة.
     *
     * mb_strtolower لا strtolower: الثانية لا تُصغّر الحروف غير اللاتينية،
     * فكلمة بحروف كبيرة في لغة أخرى تبقى بلا تطابق.
     *
     * @return array<int, string>
     */
    public static function parse(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return [];
        }

        $keywords = array_map(
            static fn ($keyword) => mb_strtolower(trim($keyword)),
            explode(',', $raw)
        );

        return array_values(array_unique(array_filter(
            $keywords,
            static fn ($keyword) => $keyword !== ''
        )));
    }

    /**
     * الصيغة التي تُحفظ في العمود: بفاصلة بلا مسافات.
     *
     * التطبيع عند الكتابة يمنع تكرار العطب، والتطبيع عند القراءة يُصلح ما
     * كُتب قبله. نفعل الاثنين لأن أحدهما لا يُغني.
     */
    public static function normalizeForStorage(?string $raw): ?string
    {
        $keywords = self::parse($raw);

        return $keywords === [] ? null : implode(',', $keywords);
    }

    /**
     * هل تُشغّل هذه الرسالة الـ flow؟
     *
     * نحفظ السلوك القديم كما هو: تطابق الرسالة كاملةً، **أو** أي كلمة
     * مفردة فيها. توسيعه أو تضييقه هنا يُغيّر سلوك ٦٤ flow قائماً.
     */
    public static function matches(?string $rawKeywords, ?string $message): bool
    {
        $keywords = self::parse($rawKeywords);

        if ($keywords === []) {
            return false;
        }

        $message = mb_strtolower(trim((string) $message));

        if ($message === '') {
            return false;
        }

        if (in_array($message, $keywords, true)) {
            return true;
        }

        foreach (explode(' ', $message) as $word) {
            $word = trim($word);

            if ($word !== '' && in_array($word, $keywords, true)) {
                return true;
            }
        }

        return false;
    }
}
