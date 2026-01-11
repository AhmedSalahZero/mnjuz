<?php

namespace App\Rules;

use Illuminate\Contracts\Validation\Rule;
use App\Models\Role;

class UniqueRole implements Rule
{
    protected $existingUuid;

    public function __construct($existingUuid)
    {
        $this->existingUuid = $existingUuid;
    }

    public function passes($attribute, $value)
    {
        return Role::where('uuid', $value)
            ->where('uuid', '!=', $this->existingUuid)
            ->exists();
    }

    public function message()
    {
        return __('The selected role is invalid.');
    }
}
