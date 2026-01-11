<?php

namespace App\Http\Requests;

use App\Models\Addon;
use App\Models\Setting;
use App\Models\User;
use App\Rules\Recaptcha;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class LoginRequest extends FormRequest
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
        $rules = [
            'email' => [
                'required',
                'email',
                function ($attribute, $value, $fail) {
                    if (!$this->emailExists($this->email)) {
                        $fail(__('This email does not exist!'));
                    }
                },
            ],
            'password' => [
                'required',
                function ($attribute, $value, $fail) {
                    $user = Auth::getProvider()->retrieveByCredentials(['email' => $this->email]);
                    if (!$user || !Hash::check($value, $user->getAuthPassword())) {
                        return $fail(__('Your credentials are incorrect!'));
                    }
                },
            ],
        ];

        // Check if recaptcha_active is 1, then add recaptcha_response rule
        if (Addon::where('name', 'Google Recaptcha')->first()->is_active === '1') {
            $rules['recaptcha_response'] = ['required', new Recaptcha];
        }
        return $rules;
    }

    private function emailExists(string $email): bool
    {
        return User::where('email', $email)->where('status', '1')->where('deleted_at', null)->exists();
    }
}
