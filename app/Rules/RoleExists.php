<?php

namespace App\Rules;

use Illuminate\Contracts\Validation\Rule;
use App\Models\Role;

class RoleExists implements Rule
{
    public function passes($attribute, $value)
    {
        // Check if the role with the provided UUID exists in the roles table
        return Role::where('uuid', $value)->exists();
    }

    public function message()
    {
        return __('The selected role does not exist.');
    }
}
