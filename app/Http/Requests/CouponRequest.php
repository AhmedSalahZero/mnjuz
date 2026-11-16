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
                    // القواعد نفسها التي يحسب بها السعر: فعّال، للباقات، لم ينفد،
                    // ولم تستعمله هذه المنشأة من قبل.
                    $result = app(\App\Services\Billing\CouponRedemptionService::class)->findUsable(
                        (string) $value,
                        (int) session()->get('current_organization'),
                        \App\Models\CouponRedemption::CONTEXT_PLAN
                    );

                    if ($result['error'] !== null) {
                        $fail($result['error']);
                    }
                },
            ],
        ];

        return $rules;
    }
}
