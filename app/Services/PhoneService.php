<?php

namespace App\Services;

use App\Helpers\BrazilPhoneHelper;
use Propaganistas\LaravelPhone\PhoneNumber;

class PhoneService
{
    /** @var array<string, string> */
    private static array $displayFormatCache = [];

    /** @var array<string, array{is_valid: bool, formatted: ?string, error: ?string, type: string}> */
    private static array $validationCache = [];

    /**
     * Validate and format a phone number
     * 
     * @param string $phoneNumber
     * @return array ['is_valid' => bool, 'formatted' => string, 'error' => string|null, 'type' => string]
     */
    public static function validateAndFormat($phoneNumber)
    {
        $cacheKey = (string) $phoneNumber;
        if (isset(self::$validationCache[$cacheKey])) {
            return self::$validationCache[$cacheKey];
        }

        // Check if it's a Brazilian number
        if (BrazilPhoneHelper::isBrazilianNumber($phoneNumber)) {
            $validation = BrazilPhoneHelper::validateBrazilPhone($phoneNumber);
            
            if ($validation['is_valid']) {
                $type = BrazilPhoneHelper::getPhoneType($phoneNumber);
                return self::$validationCache[$cacheKey] = [
                    'is_valid' => true,
                    'formatted' => $validation['formatted'],
                    'error' => null,
                    'type' => $type
                ];
            }

            return self::$validationCache[$cacheKey] = [
                'is_valid' => false,
                'formatted' => null,
                'error' => $validation['error'],
                'type' => 'invalid'
            ];
        }

        // For non-Brazilian numbers, use libphonenumber
        try {
            $phone = new PhoneNumber($phoneNumber);
            
            if ($phone->isValid()) {
                return self::$validationCache[$cacheKey] = [
                    'is_valid' => true,
                    'formatted' => $phone->formatE164(),
                    'error' => null,
                    'type' => 'international'
                ];
            }

            return self::$validationCache[$cacheKey] = [
                'is_valid' => false,
                'formatted' => null,
                'error' => 'Invalid phone number format',
                'type' => 'invalid'
            ];
        } catch (\Exception $e) {
            return self::$validationCache[$cacheKey] = [
                'is_valid' => false,
                'formatted' => null,
                'error' => 'Invalid phone number format',
                'type' => 'invalid'
            ];
        }
    }

    /**
     * Format phone number for display
     * 
     * @param string $phoneNumber
     * @return string
     */
    public static function formatForDisplay($phoneNumber)
    {
        $cacheKey = (string) $phoneNumber;
        if (isset(self::$displayFormatCache[$cacheKey])) {
            return self::$displayFormatCache[$cacheKey];
        }

        if (BrazilPhoneHelper::isBrazilianNumber($phoneNumber)) {
            return self::$displayFormatCache[$cacheKey] = BrazilPhoneHelper::formatBrazilPhone($phoneNumber);
        }

        try {
            $phone = new PhoneNumber($phoneNumber);
            return self::$displayFormatCache[$cacheKey] = $phone->formatInternational();
        } catch (\Exception $e) {
            return self::$displayFormatCache[$cacheKey] = $phoneNumber;
        }
    }

    /**
     * Get E164 formatted phone number
     * 
     * @param string $phoneNumber
     * @return string|null
     */
    public static function getE164Format($phoneNumber)
    {
        $validation = self::validateAndFormat($phoneNumber);
        
        if ($validation['is_valid']) {
            return $validation['formatted'];
        }
        
        return null;
    }

    /**
     * Check if phone number is valid
     * 
     * @param string $phoneNumber
     * @return bool
     */
    public static function isValid($phoneNumber)
    {
        $validation = self::validateAndFormat($phoneNumber);
        return $validation['is_valid'];
    }

    /**
     * Get phone number type
     * 
     * @param string $phoneNumber
     * @return string
     */
    public static function getType($phoneNumber)
    {
        $validation = self::validateAndFormat($phoneNumber);
        return $validation['type'];
    }

    /**
     * تحويل الرقم إلى صيغة E.164 قياسية، أو null إن تعذّر تحليله.
     *
     * getE164Format وحدها لا تكفي: هي تشترط البادئة «+»، وواتساب يسلّم الأرقام
     * بدونها («966502486051»)، والإدخال اليدوي يأتي بفواصل أو ببادئة «00».
     * فنجرّب الرقم كما هو، ثم نعيد بناءه من أرقامه وحدها ببادئة «+».
     *
     * لا نُخمّن مفتاح دولة أبداً: رقم محلي مجرّد مثل «0537675751» يبقى كما هو،
     * لأن افتراض السعودية قد يرسل رسالة عميلٍ إلى بلد آخر. الأسلم أن يبقى
     * كما أدخله صاحبه من أن نغيّره بظنّ.
     *
     * @param  string|null  $phoneNumber
     * @return string|null  صيغة E.164، أو null إن لم يكن الرقم صالحاً دولياً.
     */
    public static function toE164($phoneNumber): ?string
    {
        if ($phoneNumber === null) {
            return null;
        }

        $raw = trim((string) $phoneNumber);
        if ($raw === '') {
            return null;
        }

        // 1) كما هو — يغطّي «+966…» و«+966 53 767 5751»
        $e164 = self::getE164Format($raw);
        if ($e164 !== null) {
            return $e164;
        }

        // 2) من أرقامه وحدها ببادئة «+» — يغطّي «966…» و«00966…» و«966-53-…»
        $digits = preg_replace('/\D/', '', $raw);
        if ($digits === '' || $digits === null) {
            return null;
        }

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        return self::getE164Format('+' . $digits);
    }

    /**
     * Normalize phone number (ensure it starts with +)
     * 
     * @param string $phoneNumber
     * @return string
     */
    public static function normalize($phoneNumber)
    {
        if (!str_starts_with($phoneNumber, '+')) {
            $phoneNumber = '+' . $phoneNumber;
        }
        
        return $phoneNumber;
    }
} 
