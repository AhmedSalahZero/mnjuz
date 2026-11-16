<?php

namespace App\Services\WebChat;

use App\Services\PhoneService;
use Illuminate\Support\Facades\Validator;

/**
 * التحقّق من إجابات أسئلة البداية وتحويلها إلى ما يُحفظ.
 *
 * الخادم هو الحَكَم؛ الـ widget يعيد القواعد نفسها ليعرض السبب فوراً
 * (resources/widget/src/core/answers.ts)، والحالات المشتركة في
 * tests/Fixtures/webchat/answers.json يمرّ بها الطرفان.
 *
 * الأرقام العربية والفارسية تُقبل حيث يُنتظر رقم (جوال، عدد، تاريخ، هوية).
 */
final class WebChatAnswers
{
    public const NAME_MAX = 80;
    public const TEXT_MAX = 255;
    public const EMAIL_MAX = 191;

    /**
     * @param array<int, array> $questions من WebChatQuestions::forAccount
     * @param array<string, mixed> $answers بمعرّف السؤال
     * @param array<string, mixed> $countries دولة كل سؤال جوال
     * @return array{values: array<string, string>, errors: array<string, array<int, string>>}
     */
    public static function validate(array $questions, array $answers, array $countries, string $defaultCountry): array
    {
        $values = [];
        $errors = [];

        foreach ($questions as $question) {
            $id = $question['id'];
            $raw = $answers[$id] ?? null;
            $raw = is_scalar($raw) ? (string) $raw : '';
            $country = is_string($countries[$id] ?? null) ? $countries[$id] : $defaultCountry;

            [$ok, $value, $reason] = self::check($question, $raw, $country);

            if ($ok && $value !== null) {
                $values[$id] = $value;
            } elseif (!$ok) {
                $errors[$id] = [self::message($reason)];
            }
        }

        return ['values' => $values, 'errors' => $errors];
    }

    /**
     * سؤال واحد. الفارغ مقبول بلا قيمة إن كان السؤال اختيارياً.
     *
     * @return array{0: bool, 1: ?string, 2: ?string} [مقبول، القيمة المحفوظة، سبب الرفض]
     */
    public static function check(array $question, string $raw, ?string $country): array
    {
        $isName = ($question['target'] ?? null) === 'name';
        $value = self::cleanText($raw, $isName ? self::NAME_MAX + 1 : self::TEXT_MAX + 1);

        if ($value === '') {
            return ($question['required'] ?? false) ? [false, null, 'required'] : [true, null, null];
        }

        $max = $isName ? self::NAME_MAX : self::TEXT_MAX;

        return match ($question['validation'] ?? 'text') {
            'email' => self::email($value),
            'phone' => self::phone($value, $country, ($question['target'] ?? null) === 'phone'),
            'number' => self::number($value),
            'date' => self::date($value),
            'select' => in_array($value, $question['options'] ?? [], true) ? [true, $value, null] : [false, null, 'select'],
            'saudi_id' => self::saudiId($value),
            default => mb_strlen($value) > $max ? [false, null, 'too_long'] : [true, $value, null],
        };
    }

    public static function message(?string $reason): string
    {
        return match ($reason) {
            'required' => __('This question is required.'),
            'too_long' => __('That answer is too long.'),
            'email' => __('Enter a valid email address.'),
            'number' => __('Enter a number.'),
            'date' => __('Enter a valid date.'),
            'select' => __('Choose one of the options.'),
            'saudi_id' => __('Enter a valid Saudi national ID or Iqama number (10 digits).'),
            'phone_letters' => WebChatPhone::message(WebChatPhone::ERROR_LETTERS),
            'phone_landline' => WebChatPhone::message(WebChatPhone::ERROR_NOT_MOBILE),
            default => WebChatPhone::message(WebChatPhone::ERROR_INVALID),
        };
    }

    /** نصّ حرّ بلا محارف تحكّم (ما عدا السطر الجديد والجدولة). */
    public static function cleanText(?string $value, int $max): string
    {
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $value) ?? '';

        return mb_substr(trim($value), 0, $max);
    }

    /**
     * رقم الهوية الوطنية أو الإقامة: 10 أرقام تبدأ بـ 1 (مواطن) أو 2 (مقيم)،
     * وآخرها رقم تحقّق بخوارزمية Luhn (الخانات الزوجية تُضاعف).
     */
    public static function isSaudiId(string $digits): bool
    {
        if (!preg_match('/^[12]\d{9}$/', $digits)) {
            return false;
        }

        $sum = 0;

        for ($i = 0; $i < 10; $i++) {
            $digit = (int) $digits[$i];

            if ($i % 2 === 0) {
                $double = $digit * 2;
                $sum += intdiv($double, 10) + $double % 10;
            } else {
                $sum += $digit;
            }
        }

        return $sum % 10 === 0;
    }

    /* ───────────────────────── الأنواع ───────────────────────── */

    private static function email(string $value): array
    {
        $valid = mb_strlen($value) <= self::EMAIL_MAX
            && Validator::make(['email' => $value], ['email' => 'email'])->passes();

        return $valid ? [true, mb_strtolower($value), null] : [false, null, 'email'];
    }

    /**
     * سؤال الجوال الأساسي يُحفظ دولياً (E.164) كما كان. وحقلٌ مخصّص بنوع
     * «جوال» يُحفظ بصيغة العرض: يقرؤه الموظّف في الشريط كما هو.
     */
    private static function phone(string $value, ?string $country, bool $isMainPhone): array
    {
        [$e164, $reason] = WebChatPhone::strict($value, $country);

        if ($e164 === null) {
            return [false, null, match ($reason) {
                WebChatPhone::ERROR_LETTERS => 'phone_letters',
                WebChatPhone::ERROR_NOT_MOBILE => 'phone_landline',
                default => 'phone_invalid',
            }];
        }

        return [true, $isMainPhone ? $e164 : (PhoneService::formatForDisplay($e164) ?: $e164), null];
    }

    private static function number(string $value): array
    {
        $value = strtr(WebChatPhone::normalize($value), ['٫' => '.', ',' => '.']);

        return preg_match('/^[+-]?\d+(\.\d+)?$/', $value) ? [true, $value, null] : [false, null, 'number'];
    }

    /** 2026-11-14 أو 14/11/2026 (يوم/شهر/سنة كما يُكتب هنا) ← 2026-11-14. */
    private static function date(string $value): array
    {
        $value = WebChatPhone::normalize($value);

        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $value, $m)) {
            [$year, $month, $day] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('#^(\d{1,2})[/.\-](\d{1,2})[/.\-](\d{4})$#', $value, $m)) {
            [$day, $month, $year] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } else {
            return [false, null, 'date'];
        }

        if ($year < 1900 || $year > 2100 || !checkdate($month, $day, $year)) {
            return [false, null, 'date'];
        }

        return [true, sprintf('%04d-%02d-%02d', $year, $month, $day), null];
    }

    private static function saudiId(string $value): array
    {
        $digits = preg_replace('/[\s-]/', '', WebChatPhone::normalize($value)) ?? '';

        return self::isSaudiId($digits) ? [true, $digits, null] : [false, null, 'saudi_id'];
    }
}
