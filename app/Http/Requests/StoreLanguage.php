<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLanguage extends FormRequest
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
            'status' => 'required',
            'name' => 'required',
            'code' => 'required',
        ];
    
        if (!$this->isMethod('post')) {
            $id = $this->route('language');
            $rules['name'] = Rule::unique('languages')->ignore($id)->whereNull('deleted_at');
            $rules['code'] = Rule::unique('languages')->ignore($id)->whereNull('deleted_at');
        } else {
            $rules['name'] = Rule::unique('languages')->whereNull('deleted_at');
            $rules['code'] = Rule::unique('languages')->whereNull('deleted_at');
        }

        return $rules;
    }
}
