<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreDb extends FormRequest
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
            'host' => 'required',
            'port' => 'required',
            'dbname' => 'required',
            'dbuser' => 'required',
        ];

        return $rules;
    }

    public function messages(): array
    {
        return [
            'host.required' => __('This field is required.'),
            'port.required' => '*required.',
            'dbname.required' => __('This field is required.'),
            'dbuser.required' => __('This field is required.'),
        ];
    }
}
