<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;

class StoreContactGroup extends FormRequest
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
        $organizationId = session()->get('current_organization');

        // If it's an update request, exclude the current record from the uniqueness check
        if (!$this->route('uuid')) {
            $rules = [
                'name' => [
                    'required',
                    Rule::unique('contact_groups', 'name')->where(function ($query) use ($organizationId) {
                        return $query->where('organization_id', $organizationId)
                            ->where('deleted_at', null);
                    }),
                ],
                // Add other validation rules as needed
            ];
        } else {
            $rules['name'][] = Rule::unique('contact_groups', 'name')
            ->where(function ($query) use ($organizationId) {
                return $query
                    ->where('organization_id', $organizationId)
                    ->where('deleted_at', null)
                    ->whereNotIn('uuid', [$this->route('uuid')]);
            });
        }

        return $rules;
    }
}
