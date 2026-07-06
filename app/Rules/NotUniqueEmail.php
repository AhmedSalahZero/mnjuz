<?php

namespace App\Rules;

use Illuminate\Contracts\Validation\Rule;
use App\Models\User;

class NotUniqueEmail implements Rule
{
    protected $ignoreId;

    public function __construct($ignoreId = null)
    {
        $this->ignoreId = $ignoreId;
    }
    
    public function passes($attribute, $value)
    {
        // Check if the email already exists in the users table with 
        $query = User::where('email', $value);

        return $query->exists();
    }

    public function message()
    {
        return __('This email does not exist.');
    }
}
