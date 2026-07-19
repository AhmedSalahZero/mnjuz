<?php

namespace App\Rules;

use App\Services\PhoneService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidPhone implements ValidationRule
{
   public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!PhoneService::isValid($value)) {
            $fail(__('The phone number is not valid.',[],getApiLang()));
        }
    }

}
