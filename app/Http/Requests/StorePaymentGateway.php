<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePaymentGateway extends FormRequest
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
        $type = strtolower($this->route('payment_gateway'));

        $rules = [];

        if ($type == 'paypal') {
            $rules = [
                'client_id' => 'required',
                'secret' => 'required',
                'mode' => 'required',
                'webhook_id' => 'required',
            ];
        } else if($type == 'stripe') {
            $rules = [
                'publishable_key' => 'required',
                'secret_key' => 'required',
                'webhook_secret' => 'required',
            ];
        } else if($type == 'paystack' || $type == 'flutterwave') {
            $rules = [
                'public_key' => 'required',
                'secret_key' => 'required',
            ];
        }

        return $rules;
    }
}
