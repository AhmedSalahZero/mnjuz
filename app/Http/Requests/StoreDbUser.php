<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreDbUser extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'company_name' => 'required',
            'url' => 'required',
            'first_name' => 'required',
            'last_name' => 'required',
            'email' => 'required',
            'password' => 'required',
        ];

        return $rules;
    }

    public function messages(): array
    {
        return [
            'company_name.required' => __('This field is required.'),
            'url.required' => __('This field is required.'),
            'first_name.required' => __('This field is required.'),
            'last_name.required' => __('This field is required.'),
            'email.required' => __('This field is required.'),
            'password.required' => __('This field is required.'),
        ];
    }
}
