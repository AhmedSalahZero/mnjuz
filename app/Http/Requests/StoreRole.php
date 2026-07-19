<?php

namespace App\Http\Requests;

use App\Rules\NotRoleUser;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRole extends FormRequest
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
        if ($this->isMethod('post')) {
            $rules['name'] = [
                'required',
                'unique:roles',
                new NotRoleUser,
            ];
        } elseif ($this->isMethod('put') || $this->isMethod('patch')) {
            $rules['name'] = [
                'required',
                Rule::unique('roles')->ignore($this->route('role'), 'uuid'),
                new NotRoleUser,
            ];
        }

        return $rules;
    }
}
