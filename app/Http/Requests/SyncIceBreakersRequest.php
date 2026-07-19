<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SyncIceBreakersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $organizationId = session()->get('current_organization');

        return [
            'items' => 'present|array|max:4',
            'items.*.id' => [
                'nullable',
                'integer',
                Rule::exists('ice_breakers', 'id')->where(function ($query) use ($organizationId) {
                    return $query->where('organization_id', $organizationId);
                }),
            ],
            'items.*.text' => 'required|string|max:80',
            'items.*.sort_order' => 'required|integer|min:0',
        ];
    }
}
