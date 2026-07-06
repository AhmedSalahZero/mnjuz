<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSubscriptionPlan extends FormRequest
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
            'name' => 'required',
            'price' => 'required|numeric|gte:0',
            'period' => 'required',
            'campaign_limit' => 'required|numeric|gte:-1',
            'message_limit' => 'required|numeric|gte:-1',
            'contacts_limit' => 'required|numeric|gte:-1',
            'canned_replies_limit' => 'required|numeric|gte:-1',
            'status' => 'required',
        ];

        return $rules;
    }
}
