<?php

namespace App\Services\MyFatoorah;

use Illuminate\Support\Facades\Log;

class WebhookSignatureVerifier
{
    /**
     * Verify MyFatoorah webhook v2 signature (PAYMENT_STATUS_CHANGED).
     *
     * @see https://docs.myfatoorah.com/docs/webhook-v2-payment-status-data-model
     */
    public function verify(string $payload, ?string $signature, ?string $secret): bool
    {
        if (empty($signature) || empty($secret)) {
            Log::warning('MyFatoorah webhook missing signature or secret');

            return false;
        }

        $data = json_decode($payload, true);

        if (!is_array($data)) {
            return false;
        }

        $transaction = $data['Data']['Transaction'] ?? $data['Transaction'] ?? [];
        $amount = $data['Data']['Amount']['Value'] ?? $data['Amount']['Value'] ?? '';
        $currency = $data['Data']['Amount']['Currency'] ?? $data['Amount']['Currency'] ?? '';

        $parts = [
            'PaymentId' => (string) ($transaction['PaymentId'] ?? ''),
            'InvoiceId' => (string) ($data['Data']['Invoice']['Id'] ?? $data['Invoice']['Id'] ?? ''),
            'TransactionStatus' => (string) ($transaction['Status'] ?? ''),
            'InvoiceValue' => (string) $amount,
            'Currency' => (string) $currency,
        ];

        $ordered = implode(',', array_values($parts));
        $expected = base64_encode(hash_hmac('sha256', $ordered, $secret, true));

        return hash_equals($expected, $signature);
    }
}
