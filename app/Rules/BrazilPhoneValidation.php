<?php

namespace App\Rules;

use App\Helpers\BrazilPhoneHelper;
use Illuminate\Contracts\Validation\Rule;
use Propaganistas\LaravelPhone\PhoneNumber;

class BrazilPhoneValidation implements Rule
{
    /**
     * Create a new rule instance.
     *
     * @return void
     */
    public function __construct()
    {
        //
    }

    /**
     * Determine if the validation rule passes.
     *
     * @param  string  $attribute
     * @param  mixed  $value
     * @return bool
     */
    public function passes($attribute, $value)
    {
        // Check if it's a Brazilian number
        if (!BrazilPhoneHelper::isBrazilianNumber($value)) {
            // If not Brazilian, use the standard libphonenumber validation
            try {
                $phone = new PhoneNumber($value);
                return $phone->isValid();
            } catch (\Exception $e) {
                return false;
            }
        }

        // For Brazilian numbers, use our custom validation
        $validation = BrazilPhoneHelper::validateBrazilPhone($value);
        return $validation['is_valid'];
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message()
    {
        return 'The :attribute field must be a valid phone number.';
    }
} 