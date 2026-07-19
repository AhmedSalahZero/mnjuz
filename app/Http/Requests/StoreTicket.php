<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTicket extends FormRequest
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
            'category' => 'required',
            'subject' => 'required',
            'message' => 'required',
        ];

        // Only require user id if it exists in the request
        if ($this->has('user')) {
            $rules['user'] = 'required';
        }

        return $rules;
    }
}
