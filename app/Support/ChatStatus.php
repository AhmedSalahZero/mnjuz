<?php

namespace App\Support;

/**
 * حالات الرسالة التي يفهمها تطبيق الموبايل.
 *
 * واتساب يرسل حالات أكثر مما يدعمه التطبيق — أبرزها `played` للرسائل الصوتية
 * عندما يشغّلها المستلم. التطبيق يرفض أي قيمة خارج قائمته، فنُترجم هنا قبل
 * الإخراج بدل أن نُسرّب قيمة تُعطّله.
 */
final class ChatStatus
{
    /** القيم التي يقبلها التطبيق. */
    public const SUPPORTED = ['accepted', 'sent', 'delivered', 'read', 'failed'];

    /**
     * ترجمة الحالات غير المدعومة إلى أقرب قيمة صحيحة.
     *
     * `played` تعني أن المستلم شغّل رسالة صوتية. لا نُخزّنها ولا نُرجعها؛
     * تُترجَم إلى `delivered` في المدخل والمخرج معاً.
     */
    private const ALIASES = [
        'played' => 'delivered',
    ];

    /**
     * الحالة كما تُحفظ في قاعدة البيانات.
     *
     * الترجمة عند الكتابة لا عند القراءة فقط: قيمة لا تدخل النظام لا تُسرَّب
     * من نقطة نسيناها. القيمة المجهولة تُترك كما هي — حارس السلّم في
     * ProcessMessageStatusJob يتكفّل بها، وطمسها يُخفي حالة جديدة من واتساب.
     */
    public static function forStorage(?string $status): ?string
    {
        if ($status === null || $status === '') {
            return $status;
        }

        return self::ALIASES[$status] ?? $status;
    }

    /**
     * الحالة كما تُرسَل للتطبيق، أو null إذا كانت غير معروفة.
     *
     * القيمة المجهولة تُحذف عمداً: قيمة لا يفهمها التطبيق أسوأ من غيابها.
     */
    public static function forApi(?string $status): ?string
    {
        if ($status === null || $status === '') {
            return null;
        }

        $status = self::ALIASES[$status] ?? $status;

        return in_array($status, self::SUPPORTED, true) ? $status : null;
    }

    /**
     * تطبيق نفس الترجمة على حقل status داخل بيانات سجل الحالة.
     *
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    public static function normalizeLogMetadata(array $metadata): array
    {
        if (!array_key_exists('status', $metadata)) {
            return $metadata;
        }

        $mapped = self::forApi(is_string($metadata['status']) ? $metadata['status'] : null);

        if ($mapped === null) {
            unset($metadata['status']);

            return $metadata;
        }

        $metadata['status'] = $mapped;

        return $metadata;
    }
}
