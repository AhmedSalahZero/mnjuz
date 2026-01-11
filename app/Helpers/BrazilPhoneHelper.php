<?php

namespace App\Helpers;

use Propaganistas\LaravelPhone\PhoneNumber;

class BrazilPhoneHelper
{
    /**
     * Validate and format Brazilian phone numbers
     * 
     * @param string $phoneNumber
     * @return array ['is_valid' => bool, 'formatted' => string, 'error' => string|null]
     */
    public static function validateBrazilPhone($phoneNumber)
    {
        // Remove any non-digit characters except +
        $cleanNumber = preg_replace('/[^\d+]/', '', $phoneNumber);
        
        // Ensure it starts with +55 (Brazil country code)
        if (!str_starts_with($cleanNumber, '+55')) {
            if (str_starts_with($cleanNumber, '55')) {
                $cleanNumber = '+' . $cleanNumber;
            } else {
                return [
                    'is_valid' => false,
                    'formatted' => null,
                    'error' => 'Brazilian phone numbers must start with +55'
                ];
            }
        }
        
        // Extract the number after +55
        $nationalNumber = substr($cleanNumber, 3);
        
        // Validate length - Brazilian numbers can be 8-14 digits (including area code)
        if (strlen($nationalNumber) < 8 || strlen($nationalNumber) > 14) {
            return [
                'is_valid' => false,
                'formatted' => null,
                'error' => 'Brazilian phone number must be between 8 and 14 digits'
            ];
        }
        
        $areaCode = substr($nationalNumber, 0, 2);
        $subscriberNumber = substr($nationalNumber, 2);
        
        // Validate area code (must be 11-99)
        if (!preg_match('/^1[1-9]|[2-9][0-9]$/', $areaCode)) {
            return [
                'is_valid' => false,
                'formatted' => null,
                'error' => 'Invalid Brazilian area code'
            ];
        }
        
        // For Brazilian numbers, we'll be more lenient with validation
        // since there are many valid formats and lengths
        return [
            'is_valid' => true,
            'formatted' => $cleanNumber,
            'error' => null
        ];
    }
    
    /**
     * Check if the number is a mobile number
     */
    private static function isMobileNumber($areaCode, $subscriberNumber)
    {
        $firstDigit = substr($subscriberNumber, 0, 1);
        
        // Mobile numbers start with 9 (except legacy São Paulo area code 11)
        if ($areaCode === '11') {
            // São Paulo: legacy numbers 91-95 are mobile, new numbers 96-99 are mobile
            return in_array($firstDigit, ['9']);
        } else {
            // All other area codes: mobile numbers start with 9
            return $firstDigit === '9';
        }
    }
    
    /**
     * Validate mobile number (legacy validation for specific formats)
     */
    private static function validateMobileNumber($fullNumber, $areaCode, $subscriberNumber)
    {
        $length = strlen($subscriberNumber);
        
        // Mobile numbers can be 8-9 digits depending on area code and legacy status
        if ($length < 8 || $length > 9) {
            return [
                'is_valid' => false,
                'formatted' => null,
                'error' => 'Mobile numbers must have 8 or 9 digits'
            ];
        }
        
        $firstDigit = substr($subscriberNumber, 0, 1);
        
        if ($areaCode === '11') {
            // São Paulo area code 11
            // Legacy numbers: 91-95 (8 digits)
            // New numbers: 96-99 (9 digits)
            if (!in_array($firstDigit, ['9'])) {
                return [
                    'is_valid' => false,
                    'formatted' => null,
                    'error' => 'Invalid São Paulo mobile number format'
                ];
            }
            
            if ($length === 9) {
                $secondDigit = substr($subscriberNumber, 1, 1);
                if (!in_array($secondDigit, ['1', '2', '3', '4', '5', '6', '7', '8', '9'])) {
                    return [
                        'is_valid' => false,
                        'formatted' => null,
                        'error' => 'Invalid São Paulo mobile number format'
                    ];
                }
            } elseif ($length === 8) {
                $secondDigit = substr($subscriberNumber, 1, 1);
                if (!in_array($secondDigit, ['1', '2', '3', '4', '5'])) {
                    return [
                        'is_valid' => false,
                        'formatted' => null,
                        'error' => 'Invalid São Paulo legacy mobile number format'
                    ];
                }
            }
        } else {
            // All other area codes
            if ($firstDigit !== '9') {
                return [
                    'is_valid' => false,
                    'formatted' => null,
                    'error' => 'Mobile numbers must start with 9'
                ];
            }
            
            // For 9-digit mobile numbers, second digit must be 6, 7, 8, or 9
            if ($length === 9) {
                $secondDigit = substr($subscriberNumber, 1, 1);
                if (!in_array($secondDigit, ['6', '7', '8', '9'])) {
                    return [
                        'is_valid' => false,
                        'formatted' => null,
                        'error' => 'Mobile numbers must have second digit 6, 7, 8, or 9'
                    ];
                }
            }
        }
        
        return [
            'is_valid' => true,
            'formatted' => $fullNumber,
            'error' => null
        ];
    }
    
    /**
     * Validate landline number (legacy validation for specific formats)
     */
    private static function validateLandlineNumber($fullNumber, $areaCode, $subscriberNumber)
    {
        $length = strlen($subscriberNumber);
        
        // Landline numbers can be 7-8 digits depending on area code
        if ($length < 7 || $length > 8) {
            return [
                'is_valid' => false,
                'formatted' => null,
                'error' => 'Landline numbers must have 7 or 8 digits'
            ];
        }
        
        $firstDigit = substr($subscriberNumber, 0, 1);
        
        // Landline numbers start with 2, 3, 4, or 5
        if (!in_array($firstDigit, ['2', '3', '4', '5'])) {
            return [
                'is_valid' => false,
                'formatted' => null,
                'error' => 'Landline numbers must start with 2, 3, 4, or 5'
            ];
        }
        
        return [
            'is_valid' => true,
            'formatted' => $fullNumber,
            'error' => null
        ];
    }
    
    /**
     * Format Brazilian phone number for display
     */
    public static function formatBrazilPhone($phoneNumber)
    {
        $validation = self::validateBrazilPhone($phoneNumber);
        
        if (!$validation['is_valid']) {
            return $phoneNumber; // Return original if invalid
        }
        
        $nationalNumber = substr($validation['formatted'], 3);
        $areaCode = substr($nationalNumber, 0, 2);
        $subscriberNumber = substr($nationalNumber, 2);
        $subLength = strlen($subscriberNumber);
        
        // Adaptive formatting based on length
        if ($subLength <= 4) {
            // Short numbers: +55 (11) 1234
            return sprintf('+55 (%s) %s', $areaCode, $subscriberNumber);
        } elseif ($subLength <= 6) {
            // Medium numbers: +55 (11) 123-456
            return sprintf(
                '+55 (%s) %s-%s',
                $areaCode,
                substr($subscriberNumber, 0, 3),
                substr($subscriberNumber, 3)
            );
        } elseif ($subLength <= 8) {
            // Standard numbers: +55 (11) 1234-5678
            return sprintf(
                '+55 (%s) %s-%s',
                $areaCode,
                substr($subscriberNumber, 0, 4),
                substr($subscriberNumber, 4)
            );
        } else {
            // Long numbers: +55 (11) 12345-67890
            return sprintf(
                '+55 (%s) %s-%s',
                $areaCode,
                substr($subscriberNumber, 0, 5),
                substr($subscriberNumber, 5)
            );
        }
    }
    
    /**
     * Check if a phone number is Brazilian
     */
    public static function isBrazilianNumber($phoneNumber)
    {
        $cleanNumber = preg_replace('/[^\d+]/', '', $phoneNumber);
        return str_starts_with($cleanNumber, '+55') || str_starts_with($cleanNumber, '55');
    }
    
    /**
     * Get phone number type (mobile/landline)
     */
    public static function getPhoneType($phoneNumber)
    {
        $validation = self::validateBrazilPhone($phoneNumber);
        
        if (!$validation['is_valid']) {
            return 'invalid';
        }
        
        $nationalNumber = substr($validation['formatted'], 3);
        $areaCode = substr($nationalNumber, 0, 2);
        $subscriberNumber = substr($nationalNumber, 2);
        
        return self::isMobileNumber($areaCode, $subscriberNumber) ? 'mobile' : 'landline';
    }
} 