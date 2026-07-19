/**
 * Test file for Brazilian Phone Validation Utility
 * 
 * This file contains tests for the Brazilian phone validation functions.
 * You can run these tests in the browser console or in a test environment.
 */

import { 
    isBrazilianNumber, 
    validateBrazilianPhone, 
    formatBrazilianPhone, 
    getBrazilianPhoneType,
    validatePhone 
} from './brazilPhoneValidation.js';

// Test function to run all tests
export function runBrazilianPhoneTests() {
    console.log('🧪 Running Brazilian Phone Validation Tests...\n');
    
    let passedTests = 0;
    let totalTests = 0;
    
    function test(description, testFunction) {
        totalTests++;
        try {
            const result = testFunction();
            if (result) {
                console.log(`✅ ${description}`);
                passedTests++;
            } else {
                console.log(`❌ ${description}`);
            }
        } catch (error) {
            console.log(`❌ ${description} - Error: ${error.message}`);
        }
    }
    
    // Test isBrazilianNumber function
    console.log('📞 Testing isBrazilianNumber function:');
    test('Should identify Brazilian numbers with +55', () => 
        isBrazilianNumber('+5511999999999')
    );
    test('Should identify Brazilian numbers with 55', () => 
        isBrazilianNumber('5511999999999')
    );
    test('Should identify Brazilian 8-digit numbers', () => 
        isBrazilianNumber('+5511234567')
    );
    test('Should identify Brazilian 14-digit numbers', () => 
        isBrazilianNumber('+5511234567890123')
    );
    test('Should not identify non-Brazilian numbers', () => 
        !isBrazilianNumber('+1234567890')
    );
    test('Should handle empty input', () => 
        !isBrazilianNumber('')
    );
    console.log('');
    
    // Test validateBrazilianPhone function
    console.log('🔍 Testing validateBrazilianPhone function:');
    test('Should validate Brazilian 8-digit numbers', () => 
        validateBrazilianPhone('+5511234567').isValid
    );
    test('Should validate Brazilian 9-digit numbers', () => 
        validateBrazilianPhone('+55112345678').isValid
    );
    test('Should validate Brazilian 10-digit numbers', () => 
        validateBrazilianPhone('+551123456789').isValid
    );
    test('Should validate Brazilian 11-digit numbers', () => 
        validateBrazilianPhone('+5511999999999').isValid
    );
    test('Should validate Brazilian 12-digit numbers', () => 
        validateBrazilianPhone('+55112345678901').isValid
    );
    test('Should validate Brazilian 13-digit numbers', () => 
        validateBrazilianPhone('+551123456789012').isValid
    );
    test('Should validate Brazilian 14-digit numbers', () => 
        validateBrazilianPhone('+5511234567890123').isValid
    );
    test('Should reject invalid area code', () => 
        !validateBrazilianPhone('+5510000000000').isValid
    );
    test('Should reject too short numbers', () => 
        !validateBrazilianPhone('+55119999999').isValid
    );
    test('Should reject too long numbers', () => 
        !validateBrazilianPhone('+551199999999999999').isValid
    );
    console.log('');
    
    // Test formatBrazilianPhone function
    console.log('📝 Testing formatBrazilianPhone function:');
    test('Should format short numbers (≤4 digits)', () => 
        formatBrazilianPhone('+55111234') === '+55 (11) 1234'
    );
    test('Should format medium numbers (5-6 digits)', () => 
        formatBrazilianPhone('+5511123456') === '+55 (11) 123-456'
    );
    test('Should format standard numbers (7-8 digits)', () => 
        formatBrazilianPhone('+551112345678') === '+55 (11) 1234-5678'
    );
    test('Should format long numbers (9+ digits)', () => 
        formatBrazilianPhone('+55111234567890') === '+55 (11) 12345-67890'
    );
    test('Should format very long numbers', () => 
        formatBrazilianPhone('+551112345678901') === '+55 (11) 12345-678901'
    );
    test('Should format maximum length numbers', () => 
        formatBrazilianPhone('+55111234567890123') === '+55 (11) 12345-67890123'
    );
    test('Should return original for non-Brazilian numbers', () => 
        formatBrazilianPhone('+1234567890') === '+1234567890'
    );
    console.log('');
    
    // Test getBrazilianPhoneType function
    console.log('🏷️ Testing getBrazilianPhoneType function:');
    test('Should identify mobile numbers (starting with 9)', () => 
        getBrazilianPhoneType('+5511999999999') === 'mobile'
    );
    test('Should identify mobile numbers with various lengths', () => 
        getBrazilianPhoneType('+551199999999') === 'mobile'
    );
    test('Should identify landline numbers (not starting with 9)', () => 
        getBrazilianPhoneType('+551133333333') === 'landline'
    );
    test('Should identify landline numbers with various lengths', () => 
        getBrazilianPhoneType('+55113333333') === 'landline'
    );
    test('Should identify international numbers', () => 
        getBrazilianPhoneType('+1234567890') === 'international'
    );
    console.log('');
    
    // Test validatePhone function
    console.log('🌍 Testing validatePhone function:');
    test('Should validate Brazilian 8-digit numbers', () => 
        validatePhone('+5511234567').isValid
    );
    test('Should validate Brazilian 11-digit mobile numbers', () => 
        validatePhone('+5511999999999').isValid && 
        validatePhone('+5511999999999').type === 'mobile'
    );
    test('Should validate Brazilian 10-digit landline numbers', () => 
        validatePhone('+551133333333').isValid && 
        validatePhone('+551133333333').type === 'landline'
    );
    test('Should validate Brazilian 14-digit numbers', () => 
        validatePhone('+5511234567890123').isValid
    );
    test('Should handle international numbers', () => 
        validatePhone('+1234567890').isValid && 
        validatePhone('+1234567890').type === 'international'
    );
    console.log('');
    
    // Test results
    console.log(`📊 Test Results: ${passedTests}/${totalTests} tests passed`);
    
    if (passedTests === totalTests) {
        console.log('🎉 All tests passed! Brazilian phone validation is working correctly.');
    } else {
        console.log('⚠️ Some tests failed. Please check the implementation.');
    }
    
    return { passed: passedTests, total: totalTests };
}

// Export test function for use in browser console
if (typeof window !== 'undefined') {
    window.runBrazilianPhoneTests = runBrazilianPhoneTests;
    console.log('🧪 Brazilian Phone Validation Tests loaded. Run runBrazilianPhoneTests() to execute tests.');
} 