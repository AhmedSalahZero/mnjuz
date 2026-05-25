<?php

namespace App\Services\MyFatoorah;

use App\Models\PaymentGateway;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class MyFatoorahApiClient
{
    private string $apiKey;

    private string $baseUrl;

    public function __construct(?string $apiKey = null, ?string $baseUrl = null)
    {
        $config = self::resolveConfig();

        $this->apiKey = $apiKey ?? $config['api_key'];
        $this->baseUrl = rtrim($baseUrl ?? $config['base_url'], '/');

        if (empty($this->apiKey)) {
            throw new RuntimeException('MyFatoorah API key is not configured.');
        }
    }

    /**
     * Resolve credentials from environment variables or the admin gateway panel.
     */
    public static function resolveConfig(): array
    {
        $gateway = PaymentGateway::where('name', config('myfatoorah.gateway_name'))->first();
        $metadata = $gateway?->metadata;

        if (is_string($metadata)) {
            $metadata = json_decode($metadata, true) ?? [];
        } elseif (is_object($metadata)) {
            $metadata = (array) $metadata;
        } else {
            $metadata = is_array($metadata) ? $metadata : [];
        }

        $mode = config('myfatoorah.api_key')
            ? config('myfatoorah.mode', 'sandbox')
            : ($metadata['mode'] ?? config('myfatoorah.mode', 'sandbox'));

        $baseUrl = config('myfatoorah.base_url')
            ?: ($metadata['base_url'] ?? config("myfatoorah.base_urls.{$mode}"));

        return [
            'api_key' => config('myfatoorah.api_key') ?: ($metadata['api_key'] ?? null),
            'base_url' => $baseUrl,
            'webhook_secret' => config('myfatoorah.webhook_secret') ?: ($metadata['webhook_secret'] ?? null),
            'mode' => $mode,
            'country_code' => $metadata['country_code'] ?? config('myfatoorah.country_code', 'SAU'),
            'currency' => $metadata['currency'] ?? config('myfatoorah.currency', 'SAR'),
            'language' => $metadata['language'] ?? config('myfatoorah.language', 'ar'),
        ];
    }

    public function executePayment(array $payload): object
    {
        return $this->post('/v2/ExecutePayment', $payload);
    }

    public function getPaymentStatus(string $key, string $keyType = 'PaymentId'): object
    {
        return $this->post('/v2/GetPaymentStatus', [
            'Key' => $key,
            'KeyType' => $keyType,
        ]);
    }

    public function getPaymentDetails(string $paymentId): object
    {
        $response = Http::withToken($this->apiKey)
            ->acceptJson()
            ->timeout(30)
            ->get("{$this->baseUrl}/v3/payments/{$paymentId}");

        if ($response->failed()) {
            Log::error('MyFatoorah getPaymentDetails failed', [
                'payment_id' => $paymentId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new RequestException($response);
        }

        return (object) $response->json();
    }

    public function initiatePayment(float $amount, string $currency): object
    {
        return $this->post('/v2/InitiatePayment', [
            'InvoiceAmount' => $amount,
            'CurrencyIso' => $currency,
        ]);
    }

    private function post(string $endpoint, array $payload): object
    {
        $response = Http::withToken($this->apiKey)
            ->acceptJson()
            ->timeout(30)
            ->post("{$this->baseUrl}{$endpoint}", $payload);

        if ($response->failed()) {
            Log::error('MyFatoorah API request failed', [
                'endpoint' => $endpoint,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new RequestException($response);
        }

        $body = (object) $response->json();

        if (!($body->IsSuccess ?? false)) {
            Log::warning('MyFatoorah API returned unsuccessful response', [
                'endpoint' => $endpoint,
                'message' => $body->Message ?? null,
                'validation_errors' => $body->ValidationErrors ?? null,
            ]);
        }

        return $body;
    }
}
