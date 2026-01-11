<?php

namespace App\Http\Requests;

use App\Rules\UniqueEmail;
use Illuminate\Foundation\Http\FormRequest;

class StoreProfile extends FormRequest
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
            'first_name' => 'required',
            'last_name' => 'required',
            'email' => ['required', 'email', new UniqueEmail(auth()->user()->id)],
            'language' => 'nullable|string|max:10'
        ];

        return $rules;
    }
}
