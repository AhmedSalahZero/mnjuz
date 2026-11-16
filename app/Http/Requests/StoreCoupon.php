<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCoupon extends FormRequest
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
            // بين 0.01 و100: السالبة كانت ترفع السعر بدل أن تخفضه.
            'percentage' => 'required|numeric|min:0.01|max:100',
            'quantity' => 'required|numeric',
            // أين ينطبق: الباقات، «إضافة دفعة»، أو الاثنان. غيابه = الباقات.
            'applies_to' => 'nullable|in:' . implode(',', \App\Services\Billing\CouponRedemptionService::APPLIES_TO),
        ];

        if ($this->isMethod('post')) {
            $rules['code'] = 'required|unique:coupons';
        } elseif ($this->isMethod('put') || $this->isMethod('patch')) {
            $rules['code'] = 'required|unique:coupons,code,'.$this->route('coupon');
        }
        

        return $rules;
    }
}
