<?php

namespace Modules\Razorpay\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreRazorpaySettings extends FormRequest
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
            'settings.razorpay_key_id' => 'required',
            'settings.razorpay_secret_key' => 'required',
            'settings.razorpay_webhook_secret' => 'required',
        ];

        return $rules;
    }

    public function messages(): array
    {
        return [
            'settings.razorpay_key_id.required' => __('This field is required.'),
            'settings.razorpay_secret_key.required' => __('This field is required.'),
            'settings.razorpay_webhook_secret.required' => __('This field is required.'),
        ];
    }
}
