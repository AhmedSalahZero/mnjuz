<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Rules\CannedReplyLimit;

class StoreAutoReply extends FormRequest
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
            'trigger' => 'required',
            'match_criteria' => 'required',
            'response_type' => 'required',
            'response' => 'required',
        ];

        // Add the CannedReplyLimit rule only for POST requests
        if ($this->isMethod('post')) {
            $rules['name'] = ['required', new CannedReplyLimit($this->organization)];
        } else {
            $rules['name'] = ['required'];
        }

        return $rules;
    }
}
