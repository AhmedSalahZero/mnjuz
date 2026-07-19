<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MyFatoorahInitPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'amount' => 'required_without:plan_id|nullable|numeric|min:1',
            'plan_id' => 'nullable|integer|exists:subscription_plans,id',
        ];
    }
}
