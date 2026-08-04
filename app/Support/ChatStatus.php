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
     * `played` تعني أن المستلم شغّل الرسالة الصوتية، وهذا يتضمّن قراءتها —
     * فترجمتها إلى `read` تحفظ المعلومة، بينما حذفها يُظهر رسالة مقروءة بلا
     * أي مؤشّر.
     */
    private const ALIASES = [
        'played' => 'read',
    ];

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
