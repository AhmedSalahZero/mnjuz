<?php

namespace App\Http\Requests;

use App\Models\Addon;
use App\Models\Setting;
use App\Models\User;
use App\Rules\Recaptcha;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
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

                    if (!$user) {
                        return $fail(__('Your credentials are incorrect!'));
                    }

                    // على جهاز المطوّر فقط: الدخول بأي كلمة مرور لتفحّص حساب
                    // عميل دون معرفة كلمته. مشروط بـ APP_ENV=local وحدها، فأي
                    // بيئة أخرى — بما فيها staging — تتحقّق كالمعتاد.
                    if (app()->environment('local')) {
                        Log::warning('Local auth bypass used', ['email' => $this->email]);

                        return;
                    }

                    if (!Hash::check($value, $user->getAuthPassword())) {
                        return $fail(__('Your credentials are incorrect!'));
                    }
                },
            ],
        ];
	
        // Check if recaptcha_active is 1, then add recaptcha_response rule
        $recaptchaAddon = Addon::where('name', 'Google Recaptcha')->first();
        if ($recaptchaAddon && $recaptchaAddon->is_active === '1') {
            $rules['recaptcha_response'] = ['required', new Recaptcha];
        }
        return $rules;
    }

    private function emailExists(string $email): bool
    {
        return User::where('email', $email)->where('status', '1')->where('deleted_at', null)->exists();
    }

    /**
     * Handle a failed validation attempt.
     *
     * @param  \Illuminate\Contracts\Validation\Validator  $validator
     * @return void
     *
     * @throws \Illuminate\Http\Exceptions\HttpResponseException
     */
    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator)
    {
        if ($this->expectsJson() || $this->is('api/*')) {
            throw new \Illuminate\Http\Exceptions\HttpResponseException(
                response()->json([
                    'success' => false,
                    'message' => __('The given data was invalid.'),
                    'errors' => $validator->errors()
                ], 422)
            );
        }

        parent::failedValidation($validator);
    }
}
