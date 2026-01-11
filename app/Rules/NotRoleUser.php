<?php

namespace App\Rules;

use Illuminate\Contracts\Validation\Rule;

class NotRoleUser implements Rule
{
    public function passes($attribute, $value)
    {
        return strtolower($value) !== 'user';
    }

    public function message()
    {
        return 'The :attribute cannot be "user".';
    }
}
