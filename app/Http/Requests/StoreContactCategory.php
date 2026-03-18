<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreContactCategory extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $organizationId = session()->get('current_organization');
        $uuid = $this->route('uuid');

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('contact_categories', 'name')->where(function ($query) use ($organizationId) {
                    return $query->where('organization_id', $organizationId);
                })->ignore($uuid ? \App\Models\ContactCategory::where('uuid', $uuid)->value('id') : null),
            ],
            'background_color' => [
                'nullable',
                'string',
                'max:20',
                'regex:/^#([0-9a-fA-F]{6})$/',
            ],
            'text_color' => [
                'nullable',
                'string',
                'max:20',
                'regex:/^#([0-9a-fA-F]{6})$/',
            ],
        ];
    }
}
