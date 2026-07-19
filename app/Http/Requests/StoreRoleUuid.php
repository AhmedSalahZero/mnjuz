<?php

namespace App\Http\Requests;

use App\Rules\UniqueRole;
use Illuminate\Foundation\Http\FormRequest;

class StoreRoleUuid extends FormRequest
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
        return [
            'uuid' => [
                'required',
                'uuid',
                new UniqueRole($this->route('uuid')),
            ],
        ];
    }
}
