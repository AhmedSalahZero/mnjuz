<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class OrganizationHasUserRule implements ValidationRule
{
    private $user;

    public function __construct($user)
    {
        $this->user = $user;
    }

    /**
     * Run the validation rule.
     *
     * @param  \Closure(string): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if(!$this->user->hasOrganization($value)){
            $fail(__('The selected organization is not associated with the user.'));
        }
    }
}
