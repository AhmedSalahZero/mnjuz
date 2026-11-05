<?php

namespace App\Services\ChatImport;

use App\Services\PhoneService;
use libphonenumber\PhoneNumberUtil;

/**
 * الدولة التي تغلب على أرقام الملف، حين لا يختار المستخدم دولة.
 *
 * الدليل هو الأرقام المكتوبة بمفتاح دولتها: إن كان معظمها من دولة واحدة قُرئت
 * الأرقام المحلية في الملف عليها. وكل رقم يُعدّ مرّة واحدة، فلا يُرجّح دولةً
 * عميلٌ كثير الرسائل. وبلا غلبة واضحة تبقى الدولة فارغة والرقم المحلي خطأً.
 */
final class CountryDetector
{
    /** @return string|null رمز الدولة (SA)، أو null بلا غلبة واضحة. */
    public static function detect(CsvReader $reader, HeaderMap $map): ?string
    {
        /** @var array<string, true> $seen */
        $seen = [];
        /** @var array<string, int> $counts */
        $counts = [];

        while (($record = $reader->read()) !== null) {
            $raw = RowParser::normalizeDigits((string) ($map->extract($record)['phone'] ?? ''));

            if ($raw === '' || isset($seen[$raw])) {
                continue;
            }

            $seen[$raw] = true;
            $region = self::regionOf($raw);

            if ($region !== null) {
                $counts[$region] = ($counts[$region] ?? 0) + 1;
            }
        }

        $total = array_sum($counts);

        if ($total < (int) config('chat_import.country_detect_min_numbers', 20)) {
            return null;
        }

        arsort($counts);
        $top = (string) array_key_first($counts);

        return $counts[$top] / $total >= (float) config('chat_import.country_detect_share', 0.8) ? $top : null;
    }

    private static function regionOf(string $raw): ?string
    {
        $digits = (string) preg_replace('/\D/', '', $raw);
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        $e164 = $digits === '' ? null : PhoneService::getE164Format('+' . $digits);

        if ($e164 === null) {
            return null;
        }

        $util = PhoneNumberUtil::getInstance();

        return $util->getRegionCodeForNumber($util->parse($e164));
    }
}
