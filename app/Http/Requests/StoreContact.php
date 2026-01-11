<?php

namespace App\Http\Requests;

use App\Rules\UniquePhone;
use App\Rules\BrazilPhoneValidation;
use Illuminate\Foundation\Http\FormRequest;
use App\Rules\ContactLimit;

class StoreContact extends FormRequest
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
        $uuid = $this->route('uuid');

        $rules = [
            'first_name' => 'required',
            'last_name' => 'nullable|max:255',
            'phone' => [
                'required',
                'string',
                'max:255',
                new BrazilPhoneValidation,
                new UniquePhone($this->organization, $uuid),
            ],
            'email' => 'nullable|email|max:255',
        ];

        if ($this->isMethod('post')) {
            $rules['first_name'] = ['required', new ContactLimit];
        } else {
            $rules['first_name'] = ['required'];
        }

        return $rules;
    }
}
