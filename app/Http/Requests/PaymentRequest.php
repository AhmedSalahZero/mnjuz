<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Models\Setting;
use App\Rules\Recaptcha;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class PaymentRequest extends FormRequest
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
            // موجب: السالب والصفر كانا يصلان البوّابة.
            'amount' => [ 'required', 'numeric', 'gt:0' ],
            'method' => [ 'required' ],
            'redirect_json' => [ 'nullable', 'boolean' ],
            // كوبون «إضافة دفعة» (اختياري): يُفحص في المتحكّم بقواعد الكوبونات.
            'coupon' => [ 'nullable', 'string', 'max:64' ],
        ];

        return $rules;
    }
}
