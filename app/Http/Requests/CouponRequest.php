<?php

namespace App\Http\Requests;

use App\Models\Coupon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class CouponRequest extends FormRequest
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
            'coupon' => [ 
                'required',
                function ($attribute, $value, $fail) {
                    $coupon = Coupon::where('code', $value)->where('status', 'active')->whereNull('deleted_at')->first();

                    if (!$coupon) {
                        $fail(__('The coupon code is invalid!'));
                        return;
                    }

                    // Check if the quantity exceeds the limit
                    if ($coupon->quantity_redeemed >= $coupon->quantity) {
                        $fail(__('The coupon has expired!'));
                    }
                },
            ],
        ];

        return $rules;
    }
}
