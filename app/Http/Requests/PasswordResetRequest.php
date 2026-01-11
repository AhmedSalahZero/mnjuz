<?php

namespace App\Http\Requests;

use App\Models\Setting;
use App\Models\User;
use App\Rules\Recaptcha;
use Illuminate\Foundation\Http\FormRequest;

class PasswordResetRequest extends FormRequest
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

        $rules = [
            'email' => [
                'required',
                'email',
                function ($attribute, $value, $fail) use ($email) {
                    if (!$this->emailExists($email)) {
                        $fail(__('This email has not been registered. Please try again!'));
                    }
                },
            ],
        ];

        // Check if recaptcha_active is 1, then add recaptcha_response rule
        if (Setting::getValueByKey('recaptcha_active') === '1') {
            $rules['recaptcha_response'] = ['required', new Recaptcha];
        }

        return $rules;
    }

    private function emailExists(string $email): bool
    {
        return User::where('email', $email)->exists();
    }
}
