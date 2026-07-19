<?php

namespace App\Http\Requests\Verification;

use Illuminate\Foundation\Http\FormRequest;

class RequestVerificationCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'method' => ['required', 'in:email,whatsapp'],
            'verification_token' => ['nullable', 'string'],
            'email' => ['nullable', 'email', 'required_if:method,email'],
            'phone' => ['nullable', 'string', 'required_if:method,whatsapp', 'min:3'],
        ];
    }

    public function messages(): array
    {
        return [
            'method.required' => __('verification.method_required'),
            'method.in' => __('verification.method_invalid'),
            'email.required_if' => __('verification.email_required'),
            'email.email' => __('verification.email_invalid'),
            'phone.required_if' => __('verification.phone_required'),
            'phone.min' => __('verification.phone_invalid'),
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => is_string($this->email) ? trim($this->email) : $this->email,
            'phone' => is_string($this->phone) ? trim($this->phone) : $this->phone,
        ]);
    }

    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator)
    {
        if ($this->expectsJson() || $this->is('api/*')) {
            throw new \Illuminate\Http\Exceptions\HttpResponseException(
                response()->json([
                    'success' => false,
                    'message' => __('verification.validation_failed'),
                    'errors' => $validator->errors(),
                ], 422)
            );
        }

        parent::failedValidation($validator);
    }
}
