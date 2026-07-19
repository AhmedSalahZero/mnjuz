<?php

namespace App\Http\Requests;

use App\Models\PaymentGateway;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePaymentGateway extends FormRequest
{
    /**
     * Secret fields are no longer sent to the browser, so a blank submission
     * means "keep the stored value". Require them only when nothing is stored yet.
     */
    private function secretRule(string $field): string
    {
        $type = strtolower((string) $this->route('payment_gateway'));
        $gateway = PaymentGateway::whereRaw('LOWER(name) = ?', [$type])->first();
        $metadata = $gateway && $gateway->metadata ? json_decode($gateway->metadata, true) : [];

        return filled($metadata[$field] ?? null) ? 'nullable' : 'required';
    }
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
                'secret' => $this->secretRule('secret'),
                'mode' => 'required',
                'webhook_id' => 'required',
            ];
        } else if($type == 'stripe') {
            $rules = [
                'publishable_key' => 'required',
                'secret_key' => $this->secretRule('secret_key'),
                'webhook_secret' => $this->secretRule('webhook_secret'),
            ];
        } else if($type == 'paystack' || $type == 'flutterwave') {
            $rules = [
                'public_key' => 'required',
                'secret_key' => $this->secretRule('secret_key'),
            ];
        } else if ($type == 'myfatoorah') {
            $rules = [
                'api_key' => $this->secretRule('api_key'),
                'webhook_secret' => 'nullable|string',
                'mode' => 'required|in:sandbox,production',
                'country_code' => 'nullable|string|size:3',
                'currency' => 'nullable|string|size:3',
                'language' => 'nullable|string|in:ar,en',
            ];
        }

        return $rules;
    }
}
