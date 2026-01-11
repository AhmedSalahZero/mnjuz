<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreProfileAddress extends FormRequest
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
            'organization_name' => 'required',
            'address' => 'required',
            'country' => 'required',
            'state' => 'required',
            'zip' => 'required',
            'city' => 'required',
            'enable_campaign_resend' => 'boolean',
            'move_failed_contacts_to_group' => 'boolean',
            'resend_intervals' => 'nullable|array',
            'resend_intervals.*' => 'integer|min:1|max:24',
            'failed_campaign_group' => 'required_if:move_failed_contacts_to_group,true|nullable|exists:contact_groups,uuid',
        ];

        return $rules;
    }
}
