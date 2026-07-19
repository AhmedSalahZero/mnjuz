<?php

namespace App\Http\Requests;

use App\Models\Organization;
use App\Rules\OrganizationHasUserRule;
use Illuminate\Foundation\Http\FormRequest;

class UserHasOrganizationRequest extends FormRequest
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
            'organization_id' => ['required','exists:organizations,id',new OrganizationHasUserRule($this->user())],
        ];
    }
	public function messages(): array
	{
		return [
			'organization_id.required' => __('The organization field is required.'),
			'organization_id.exists' => __('The selected organization is not valid.'),
		];
	}
}
