<?php

namespace App\Services\ChatImport;

use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberUtil;

/**
 * لماذا رُفض الرقم، بنصّ يفهمه صاحب الملف.
 *
 * السبب يُقاس بطول الجوال لا بما تقبله libphonenumber عموماً: السعودية عندها
 * أرقام 800 بعشر خانات، فرقم جوال بخانة زائدة يبدو لها «ممكناً». والعميل في
 * واتساب جوال دائماً. وبلا دولة معروفة للرقم لا يُذكر طول، فأي طول يُذكر يكون
 * لدولة مظنونة.
 */
final class PhoneProblem
{
    private const UNKNOWN_COUNTRY = 'Invalid phone number. Include the country code, e.g. 966501234567.';

    private const TOO_SHORT = 'The number is too short: :country numbers have :digits digits after the country code.';

    private const TOO_LONG = 'The number is too long: :country numbers have :digits digits after the country code.';

    private const NO_MATCH = 'The number does not match the numbers of :country.';

    private function __construct(
        private readonly string $key,
        private readonly ?string $region = null,
        private readonly int $digits = 0,
    ) {
    }

    /** @param bool $hasCountryCode هل ذكر الرقم مفتاح دولته؟ وإلا قُرئ على الدولة الافتراضية. */
    public static function of(string $digits, bool $hasCountryCode, ?string $defaultCountry): self
    {
        if (!$hasCountryCode && !$defaultCountry) {
            return new self(self::UNKNOWN_COUNTRY);
        }

        $util = PhoneNumberUtil::getInstance();

        try {
            $number = $hasCountryCode ? $util->parse('+' . $digits) : $util->parse($digits, $defaultCountry);
        } catch (NumberParseException) {
            return new self(self::UNKNOWN_COUNTRY);
        }

        $region = $util->getRegionCodeForCountryCode($number->getCountryCode());
        $metadata = $util->getMetadataForRegion($region);

        if ($metadata === null) {
            return new self(self::UNKNOWN_COUNTRY);
        }

        $lengths = $metadata->getMobile()->getPossibleLength() ?: $metadata->getGeneralDesc()->getPossibleLength();
        $length = strlen((string) $number->getNationalNumber());

        if ($lengths === [] || $lengths === null) {
            return new self(self::NO_MATCH, $region);
        }

        return match (true) {
            $length < min($lengths) => new self(self::TOO_SHORT, $region, min($lengths)),
            $length > max($lengths) => new self(self::TOO_LONG, $region, max($lengths)),
            default => new self(self::NO_MATCH, $region),
        };
    }

    public function message(?string $locale = null): string
    {
        if ($this->region === null) {
            return __($this->key, [], $locale);
        }

        return __($this->key, [
            'country' => self::countryName($this->region, $locale ?? app()->getLocale()),
            'digits' => $this->digits,
        ], $locale);
    }

    private static function countryName(string $region, string $locale): string
    {
        $name = class_exists(\Locale::class) ? \Locale::getDisplayRegion('-' . $region, $locale) : '';

        return $name ?: $region;
    }
}
