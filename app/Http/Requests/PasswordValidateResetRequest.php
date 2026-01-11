<?php

namespace App\Http\Requests;

use App\Models\PasswordResetToken;
use App\Models\Setting;
use App\Models\User;
use App\Rules\Recaptcha;
use Illuminate\Foundation\Http\FormRequest;

class PasswordValidateResetRequest extends FormRequest
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
        $code = $this->input('token');
        
        $rules = [
            'password' => [
                'required',
                'confirmed',
                function ($attribute, $value, $fail) use ($code) {
                    if (!$this->isTokenValid($code)) {
                        $fail(__('Your token is invalid!'));
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

    private function isTokenValid(string $token): bool
    {
       return !PasswordResetToken::where('token', $token)->exists();
    }
}
