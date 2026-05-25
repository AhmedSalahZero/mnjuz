<?php

namespace Tests\Unit;

use App\Services\MyFatoorah\WebhookSignatureVerifier;
use Tests\TestCase;

class MyFatoorahWebhookSignatureTest extends TestCase
{
    public function test_it_verifies_valid_webhook_signature(): void
    {
        $secret = 'test-webhook-secret';
        $payload = json_encode([
            'Data' => [
                'Invoice' => ['Id' => '12345'],
                'Amount' => ['Value' => '100.00', 'Currency' => 'SAR'],
                'Transaction' => [
                    'PaymentId' => 'pay_123',
                    'Status' => 'SUCCESS',
                ],
            ],
        ]);

        $ordered = 'pay_123,12345,SUCCESS,100.00,SAR';
        $signature = base64_encode(hash_hmac('sha256', $ordered, $secret, true));

        $verifier = new WebhookSignatureVerifier();

        $this->assertTrue($verifier->verify($payload, $signature, $secret));
    }

    public function test_it_rejects_invalid_webhook_signature(): void
    {
        $payload = json_encode([
            'Data' => [
                'Invoice' => ['Id' => '12345'],
                'Amount' => ['Value' => '100.00', 'Currency' => 'SAR'],
                'Transaction' => [
                    'PaymentId' => 'pay_123',
                    'Status' => 'SUCCESS',
                ],
            ],
        ]);

        $verifier = new WebhookSignatureVerifier();

        $this->assertFalse($verifier->verify($payload, 'invalid-signature', 'test-webhook-secret'));
    }

    public function test_it_rejects_webhook_without_signature(): void
    {
        $verifier = new WebhookSignatureVerifier();

        $this->assertFalse($verifier->verify('{}', null, 'secret'));
    }
}
