<?php

namespace Modules\FlowBuilder\Support;

/**
 * الكلمات المفتاحية التي تشغّل مساراً.
 *
 * كانت تُخزَّن كما كتبها المستخدم وتُطابَق بـ FIND_IN_SET، وهي تقسم على «,»
 * فقط ولا تقصّ الفراغات: «hi, hello» تُطابق hi وحدها لأن العنصر الثاني
 * « hello»، و«مرحبا، عرض» عنصرٌ واحد لأن الفاصلة العربية ليست فاصلاً، وكل
 * سطرٍ في الحقل لا يُطابَق. وعلامة الترقيم في الرسالة («hi!») تُفشل المطابقة.
 */
final class KeywordMatcher
{
    /** @return string[] الكلمات منظّفة: بلا فراغات حولها، بأحرف صغيرة، بلا تكرار. */
    public static function split(?string $keywords): array
    {
        $parts = preg_split('/[,،\r\n]+/u', (string) $keywords) ?: [];
        $clean = [];

        foreach ($parts as $part) {
            $part = self::normalizeText($part);

            if ($part !== '' && !in_array($part, $clean, true)) {
                $clean[] = $part;
            }
        }

        return $clean;
    }

    /** الصيغة المخزّنة: a,b — أو null إن لم تبقَ كلمة. */
    public static function normalize(?string $keywords): ?string
    {
        $list = self::split($keywords);

        return $list === [] ? null : implode(',', $list);
    }

    /**
     * الرسالة كلها كلمةٌ مفتاحية، أو تحوي إحداها كلمةً أو عبارةً كاملة.
     */
    public static function matches(string $message, ?string $keywords): bool
    {
        $text = self::normalizeText($message);

        if ($text === '') {
            return false;
        }

        $padded = ' ' . $text . ' ';

        foreach (self::split($keywords) as $keyword) {
            if ($keyword === $text || str_contains($padded, ' ' . $keyword . ' ')) {
                return true;
            }
        }

        return false;
    }

    /** أحرف صغيرة، وعلامات الترقيم فراغات (الرموز كالإيموجي تبقى: قد تكون هي الكلمة). */
    private static function normalizeText(string $text): string
    {
        $text = mb_strtolower($text);
        $text = preg_replace('/\p{P}+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }
}
