<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Models\TeamInvite;
use Illuminate\Foundation\Http\FormRequest;

class StoreUserInvite extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array|string>
     */
    public function rules(): array
    {
        $email = $this->input('email');
        $code = $this->route('identifier');

        $rules = [
            'first_name' => 'required',
            'last_name' => 'required',
            'email' => [
                'required',
                'email',
                function ($attribute, $value, $fail) use ($email, $code) {
                    if (!$this->isEmailSimilarToCode($email, $code)) {
                        $fail(__('The provided email is not associated with the given code.'));
                    }
                },
            ],
        ];

        if ($this->isEmailUnique($email)) {
            $rules['password'] = 'required|confirmed';
        }

        return $rules;
    }

    private function isEmailSimilarToCode(string $email, string $code): bool
    {
        return TeamInvite::where('email', $email)->where('code', $code)->exists();
    }

    private function isEmailUnique(string $email): bool
    {
        return !User::where('email', $email)->exists();
    }
}
