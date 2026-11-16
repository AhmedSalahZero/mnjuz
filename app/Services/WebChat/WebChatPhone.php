<?php

namespace App\Services\WebChat;

use Propaganistas\LaravelPhone\PhoneNumber;

/**
 * رقم جوال يكتبه زائر المحادثة المباشرة.
 *
 * كان كل ما ليس رقماً لاتينياً يُمسح قبل التحليل: «٠٥٠١٢٣٤٥٦٧» من لوحة
 * مفاتيح عربية يُرفض، و«abc0501234567» يُقبل بعد مسح حروفه، والرقم الأرضي
 * يُقبل في حقلٍ اسمه «الجوال».
 *
 * strict(): سؤال الجوال في الـ widget — حروف مرفوضة بسببها، وجوال فقط
 * (MOBILE أو FIXED_LINE_OR_MOBILE كالأرقام الأمريكية).
 * lenient(): ما يكتبه العميل في محادثة (update_contact في منشئ المسارات):
 * «رقمي 0501234567» جملةٌ لا خطأ، فيُستخرج الرقم كما كان.
 */
final class WebChatPhone
{
    public const ERROR_LETTERS = 'letters';
    public const ERROR_INVALID = 'invalid';
    public const ERROR_NOT_MOBILE = 'not_mobile';

    private const DIGITS = [
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
    ];

    /**
     * @return array{0: ?string, 1: ?string} [E.164 أو null، سبب الرفض أو null]
     */
    public static function strict(string $raw, ?string $country): array
    {
        $value = self::normalize($raw);

        if ($value === '') {
            return [null, self::ERROR_INVALID];
        }

        // ما يكتبه الناس في رقم: أرقام ومسافات و+ ( ) . -. ما سواه خطأٌ
        // يُقال للزائر، لا يُمسح بصمت.
        if (preg_match('/[^0-9+().\-\s]/u', $value)) {
            return [null, self::ERROR_LETTERS];
        }

        $number = self::parse($value, $country);

        if ($number === null) {
            return [null, self::ERROR_INVALID];
        }

        if (!$number->isOfType('mobile')) {
            return [null, self::ERROR_NOT_MOBILE];
        }

        return [$number->formatE164(), null];
    }

    /** الرقم من نصّ حرّ، أرضياً كان أو جوالاً — كما كان StartWebChatSessionRequest::toE164. */
    public static function lenient(string $raw, ?string $country): ?string
    {
        $number = self::parse(self::normalize($raw), $country);

        return $number?->formatE164();
    }

    public static function message(?string $reason): string
    {
        return match ($reason) {
            self::ERROR_LETTERS => __('Use digits only for the mobile number.'),
            self::ERROR_NOT_MOBILE => __('That looks like a landline number. Enter a mobile number.'),
            default => __('Enter a valid mobile number.'),
        };
    }

    /**
     * أرقام عربية وفارسية إلى لاتينية، وحذف علامات الاتجاه والمسافات
     * الصفرية التي يلصقها النسخ من صفحات عربية، والمسافة غير القاطعة مسافة.
     */
    public static function normalize(string $raw): string
    {
        $value = strtr($raw, self::DIGITS);
        $value = preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u', '', $value) ?? '';

        return trim(str_replace("\u{00A0}", ' ', $value));
    }

    private static function parse(string $value, ?string $country): ?PhoneNumber
    {
        $digits = preg_replace('/[^\d+]/', '', $value) ?? '';

        if (str_starts_with($digits, '00')) {
            $digits = '+' . substr($digits, 2);
        }

        // + في أوّل الرقم فقط.
        if ($digits === '' || strlen(ltrim($digits, '+')) < 6 || strrpos($digits, '+') > 0) {
            return null;
        }

        try {
            $number = str_starts_with($digits, '+')
                ? new PhoneNumber($digits)
                : new PhoneNumber($digits, strtoupper((string) $country));

            return $number->isValid() ? $number : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
