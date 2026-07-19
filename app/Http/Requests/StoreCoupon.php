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
            'percentage' => 'required|numeric',
            'quantity' => 'required|numeric',
        ];

        if ($this->isMethod('post')) {
            $rules['code'] = 'required|unique:coupons';
        } elseif ($this->isMethod('put') || $this->isMethod('patch')) {
            $rules['code'] = 'required|unique:coupons,code,'.$this->route('coupon');
        }
        

        return $rules;
    }
}
