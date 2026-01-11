/**
 * Brazilian Phone Number Validation Utility
 * 
 * This utility provides validation and formatting for Brazilian phone numbers
 * to work around libphonenumber-js issues with Brazilian number rules.
 */

/**
 * Check if a phone number is Brazilian
 * @param {string} phoneNumber - The phone number to check
 * @returns {boolean} - True if the number is Brazilian
 */
export function isBrazilianNumber(phoneNumber) {
    if (!phoneNumber) return false;
    
    const cleanNumber = phoneNumber.replace(/[^\d+]/g, '');
    return cleanNumber.startsWith('+55') || cleanNumber.startsWith('55');
}

/**
 * Validate a Brazilian phone number
 * @param {string} phoneNumber - The phone number to validate
 * @returns {object} - Object with isValid boolean and error string
 */
export function validateBrazilianPhone(phoneNumber) {
    if (!phoneNumber) {
        return { isValid: false, error: 'Phone number is required' };
    }
    
    // Remove any non-digit characters except +
    const cleanNumber = phoneNumber.replace(/[^\d+]/g, '');
    
    // Check if it's a Brazilian number
    if (!isBrazilianNumber(cleanNumber)) {
        return { isValid: true, error: '' }; // Not Brazilian, let other validators handle it
    }
    
    // Ensure it starts with +55
    const normalizedNumber = cleanNumber.startsWith('55') ? '+' + cleanNumber : cleanNumber;
    
    // Extract the number after +55
    const nationalNumber = normalizedNumber.substring(3);
    
    // Validate length - Brazilian numbers can be 8-14 digits (including area code)
    if (nationalNumber.length < 8 || nationalNumber.length > 14) {
        return { isValid: false, error: 'Brazilian phone number must be between 8 and 14 digits' };
    }
    
    const areaCode = nationalNumber.substring(0, 2);
    const subscriberNumber = nationalNumber.substring(2);
    
    // Validate area code (must be 11-99)
    if (!/^1[1-9]|[2-9][0-9]$/.test(areaCode)) {
        return { isValid: false, error: 'Invalid Brazilian area code' };
    }
    
    // For Brazilian numbers, we'll be more lenient with validation
    // since there are many valid formats and lengths
    return { isValid: true, error: '' };
}

/**
 * Format a Brazilian phone number for display
 * @param {string} phoneNumber - The phone number to format
 * @returns {string} - Formatted phone number
 */
export function formatBrazilianPhone(phoneNumber) {
    if (!phoneNumber) return phoneNumber;
    
    const cleanNumber = phoneNumber.replace(/[^\d+]/g, '');
    if (!cleanNumber.startsWith('+55')) return phoneNumber;
    
    const nationalNumber = cleanNumber.substring(3);
    const areaCode = nationalNumber.substring(0, 2);
    const subscriberNumber = nationalNumber.substring(2);
    const subLength = subscriberNumber.length;
    
    // Adaptive formatting based on length
    if (subLength <= 4) {
        // Short numbers: +55 (11) 1234
        return `+55 (${areaCode}) ${subscriberNumber}`;
    } else if (subLength <= 6) {
        // Medium numbers: +55 (11) 123-456
        return `+55 (${areaCode}) ${subscriberNumber.substring(0, 3)}-${subscriberNumber.substring(3)}`;
    } else if (subLength <= 8) {
        // Standard numbers: +55 (11) 1234-5678
        return `+55 (${areaCode}) ${subscriberNumber.substring(0, 4)}-${subscriberNumber.substring(4)}`;
    } else {
        // Long numbers: +55 (11) 12345-67890
        return `+55 (${areaCode}) ${subscriberNumber.substring(0, 5)}-${subscriberNumber.substring(5)}`;
    }
}

/**
 * Get the phone number type (mobile or landline)
 * @param {string} phoneNumber - The phone number to check
 * @returns {string} - 'mobile', 'landline', or 'invalid'
 */
export function getBrazilianPhoneType(phoneNumber) {
    const validation = validateBrazilianPhone(phoneNumber);
    
    if (!validation.isValid) {
        return 'invalid';
    }
    
    if (!isBrazilianNumber(phoneNumber)) {
        return 'international';
    }
    
    const cleanNumber = phoneNumber.replace(/[^\d+]/g, '');
    const normalizedNumber = cleanNumber.startsWith('55') ? '+' + cleanNumber : cleanNumber;
    const nationalNumber = normalizedNumber.substring(3);
    const subscriberNumber = nationalNumber.substring(2);
    
    return subscriberNumber.startsWith('9') ? 'mobile' : 'landline';
}

/**
 * Normalize a phone number (ensure it starts with +)
 * @param {string} phoneNumber - The phone number to normalize
 * @returns {string} - Normalized phone number
 */
export function normalizePhoneNumber(phoneNumber) {
    if (!phoneNumber) return phoneNumber;
    
    const cleanNumber = phoneNumber.replace(/[^\d+]/g, '');
    
    if (!cleanNumber.startsWith('+')) {
        return '+' + cleanNumber;
    }
    
    return cleanNumber;
}

/**
 * Get E164 format for a phone number
 * @param {string} phoneNumber - The phone number to format
 * @returns {string|null} - E164 formatted number or null if invalid
 */
export function getE164Format(phoneNumber) {
    const validation = validateBrazilianPhone(phoneNumber);
    
    if (!validation.isValid) {
        return null;
    }
    
    return normalizePhoneNumber(phoneNumber);
}

/**
 * Comprehensive phone validation that handles both Brazilian and international numbers
 * @param {string} phoneNumber - The phone number to validate
 * @returns {object} - Object with isValid boolean, error string, and type
 */
export function validatePhone(phoneNumber) {
    if (!phoneNumber) {
        return { isValid: false, error: 'Phone number is required', type: 'invalid' };
    }
    
    // Check if it's a Brazilian number
    if (isBrazilianNumber(phoneNumber)) {
        const validation = validateBrazilianPhone(phoneNumber);
        const type = getBrazilianPhoneType(phoneNumber);
        
        return {
            isValid: validation.isValid,
            error: validation.error,
            type: type
        };
    }
    
    // For non-Brazilian numbers, we'll let the backend handle validation
    // since we can't easily replicate libphonenumber-js validation here
    return {
        isValid: true,
        error: '',
        type: 'international'
    };
} 