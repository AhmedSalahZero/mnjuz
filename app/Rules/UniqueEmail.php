<?php

namespace App\Rules;

use Illuminate\Contracts\Validation\Rule;
use App\Models\User;

class UniqueEmail implements Rule
{
    protected $ignoreId;

    public function __construct($ignoreId = null)
    {
        $this->ignoreId = $ignoreId;
    }
    
    public function passes($attribute, $value)
    {
        // Check if the email does not already exist in the users table
        $query = User::where('email', $value)->where('deleted_at', NULL);

        // Exclude the user with the specified ID
        if ($this->ignoreId !== null) {
            $query->where('id', '!=', $this->ignoreId);
        }

        return !$query->exists();
    }

    public function message()
    {
        return __('The email has already been taken.');
    }
}
