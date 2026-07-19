<?php

namespace App\Http\Requests;

use App\Models\PasswordResetToken;
use Illuminate\Foundation\Http\FormRequest;

class ValidateResetToken extends FormRequest
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

        return $rules;
    }

    private function isTokenValid(string $token): bool
    {
       return !PasswordResetToken::where('token', $token)->exists();
    }
}
