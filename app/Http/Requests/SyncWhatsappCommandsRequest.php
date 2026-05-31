<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SyncWhatsappCommandsRequest extends FormRequest
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
                Rule::exists('whatsapp_commands', 'id')->where(function ($query) use ($organizationId) {
                    return $query->where('organization_id', $organizationId);
                }),
            ],
            'items.*.command_name' => 'required|string|max:30|regex:/^[a-zA-Z0-9_]+$/',
            'items.*.command_description' => 'required|string|max:256',
            'items.*.sort_order' => 'required|integer|min:0',
        ];
    }
}
