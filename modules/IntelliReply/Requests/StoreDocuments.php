<?php

namespace Modules\IntelliReply\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreDocuments extends FormRequest
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
            'title' => 'required|string|max:255',
            'file' => 'required|file|mimes:txt,pdf,doc,docx',
        ];

        return $rules;
    }
}
