<?php

namespace Modules\Pabbly\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePabblySettings extends FormRequest
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
            'settings.pabbly_api_key' => 'required',
            'settings.pabbly_secret_key' => 'required',
            'settings.pabbly_product_name' => 'required',
        ];

        return $rules;
    }

    public function messages(): array
    {
        return [
            'settings.pabbly_api_key.required' => __('This field is required.'),
            'settings.pabbly_secret_key.required' => __('This field is required.'),
            'settings.pabbly_product_name.required' => __('This field is required.'),
        ];
    }
}
