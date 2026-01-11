<?php

namespace App\Http\Requests;

use App\Rules\NotRoleUser;
use App\Rules\NotUniqueEmail;
use App\Rules\UniqueEmail;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOrganization extends FormRequest
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
            'name' => 'required',
            'plan' => 'required',
        ];

        if ($this->isMethod('post')) {
            if ($this->input('create_user') == 1) {
                $rules += [
                    'email' => ['required', 'email', new UniqueEmail],
                    'first_name' => 'required',
                    'last_name' => 'required',
                    'password' => 'required|confirmed',
                ];
            } else {
                $rules['email'] = ['required', 'email', new NotUniqueEmail];
            }
        }

        return $rules;
    }
}
