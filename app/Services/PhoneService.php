<?php

namespace App\Services;

use App\Helpers\BrazilPhoneHelper;
use Propaganistas\LaravelPhone\PhoneNumber;

class PhoneService
{
    /**
     * Validate and format a phone number
     * 
     * @param string $phoneNumber
     * @return array ['is_valid' => bool, 'formatted' => string, 'error' => string|null, 'type' => string]
     */
    public static function validateAndFormat($phoneNumber)
    {
        // Check if it's a Brazilian number
        if (BrazilPhoneHelper::isBrazilianNumber($phoneNumber)) {
            $validation = BrazilPhoneHelper::validateBrazilPhone($phoneNumber);
            
            if ($validation['is_valid']) {
                $type = BrazilPhoneHelper::getPhoneType($phoneNumber);
                return [
                    'is_valid' => true,
                    'formatted' => $validation['formatted'],
                    'error' => null,
                    'type' => $type
                ];
            } else {
                return [
                    'is_valid' => false,
                    'formatted' => null,
                    'error' => $validation['error'],
                    'type' => 'invalid'
                ];
            }
        }

        // For non-Brazilian numbers, use libphonenumber
        try {
            $phone = new PhoneNumber($phoneNumber);
            
            if ($phone->isValid()) {
                return [
                    'is_valid' => true,
                    'formatted' => $phone->formatE164(),
                    'error' => null,
                    'type' => 'international'
                ];
            } else {
                return [
                    'is_valid' => false,
                    'formatted' => null,
                    'error' => 'Invalid phone number format',
                    'type' => 'invalid'
                ];
            }
        } catch (\Exception $e) {
            return [
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
        if (BrazilPhoneHelper::isBrazilianNumber($phoneNumber)) {
            return BrazilPhoneHelper::formatBrazilPhone($phoneNumber);
        }

        try {
            $phone = new PhoneNumber($phoneNumber);
            return $phone->formatInternational();
        } catch (\Exception $e) {
            return $phoneNumber;
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