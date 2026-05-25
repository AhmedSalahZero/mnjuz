<?php

namespace App\Services;

use App\Services\MyFatoorah\MyFatoorahApiClient;
use App\Services\MyFatoorah\MyFatoorahPaymentProcessor;
use App\Services\MyFatoorah\WebhookSignatureVerifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MyFatoorahService
{
    private ?MyFatoorahApiClient $client = null;

    private MyFatoorahPaymentProcessor $processor;

    private WebhookSignatureVerifier $signatureVerifier;

    public function __construct()
    {
        $this->processor = new MyFatoorahPaymentProcessor();
        $this->signatureVerifier = new WebhookSignatureVerifier();
    }

    private function client(): MyFatoorahApiClient
    {
        if ($this->client === null) {
            $this->client = new MyFatoorahApiClient();
        }

        return $this->client;
    }

    /**
     * Initialize a hosted MyFatoorah payment and return the redirect URL.
     */
    public function handlePayment($amount, $planId = null)
    {
        try {
            $organizationId = (int) session()->get('current_organization');
            $userId = (int) auth()->id();

            if ($organizationId <= 0 || $userId <= 0) {
                return (object) [
                    'success' => false,
                    'error' => __('Unable to determine the current organization.'),
                ];
            }

            $payload = $this->processor->buildSendPaymentPayload(
                (float) $amount,
                $organizationId,
                $userId,
                $planId
            );

            $response = $this->client()->sendPayment($payload);

            $data = $response->Data ?? null;
            $paymentUrl = is_object($data)
                ? ($data->InvoiceURL ?? $data->PaymentURL ?? null)
                : ($data['InvoiceURL'] ?? $data['PaymentURL'] ?? null);

            if (!($response->IsSuccess ?? false) || empty($paymentUrl)) {
                $validationMessage = $this->formatValidationErrors($response->ValidationErrors ?? null);

                return (object) [
                    'success' => false,
                    'error' => $validationMessage ?: ($response->Message ?? __('Unable to initialize MyFatoorah payment.')),
                ];
            }

            Log::info('MyFatoorah payment initialized', [
                'invoice_id' => is_object($data) ? ($data->InvoiceId ?? null) : ($data['InvoiceId'] ?? null),
                'organization_id' => $organizationId,
                'user_id' => $userId,
                'plan_id' => $planId,
                'amount' => $amount,
                'payment_url' => $paymentUrl,
            ]);

            return (object) [
                'success' => true,
                'data' => $paymentUrl,
                'invoice_id' => is_object($data) ? ($data->InvoiceId ?? null) : ($data['InvoiceId'] ?? null),
            ];
        } catch (\Throwable $exception) {
            Log::error('MyFatoorah handlePayment failed', [
                'message' => $exception->getMessage(),
            ]);

            return (object) [
                'success' => false,
                'error' => str_contains($exception->getMessage(), 'timed out')
                    ? __('Could not reach MyFatoorah. Check your internet connection and try again.')
                    : __('Could not initialize payment. Please try again.'),
            ];
        }
    }

    /**
     * Verify payment after customer redirect and process the subscription.
     */
    public function verifyAndProcessPayment(?string $paymentId): object
    {
        if (empty($paymentId)) {
            return (object) ['success' => false, 'message' => __('Missing payment reference.')];
        }

        try {
            $statusResponse = $this->client()->getPaymentStatus($paymentId, 'PaymentId');

            if (!($statusResponse->IsSuccess ?? false)) {
                return (object) [
                    'success' => false,
                    'message' => $statusResponse->Message ?? __('Unable to verify payment status.'),
                ];
            }

            $data = $statusResponse->Data ?? null;

            if (is_array($data)) {
                $data = (object) $data;
            }

            if (!$this->isPaidStatus($data)) {
                $failureReason = $this->extractFailureReason($data);

                return (object) [
                    'success' => false,
                    'message' => $failureReason ?: __('Payment was not completed successfully.'),
                    'payment_status' => $data->InvoiceStatus ?? null,
                ];
            }

            $context = $this->buildContextFromStatusResponse($data, $paymentId);
            $result = $this->processor->processVerifiedPayment($context);

            return (object) [
                'success' => (bool) ($result->success ?? false),
                'duplicate' => (bool) ($result->duplicate ?? false),
                'message' => ($result->success ?? false)
                    ? __('Payment processed successfully!')
                    : ($result->message ?? __('Payment verification failed.')),
                'payment' => $context,
            ];
        } catch (\Throwable $exception) {
            Log::error('MyFatoorah verifyAndProcessPayment failed', [
                'payment_id' => $paymentId,
                'message' => $exception->getMessage(),
            ]);

            return (object) [
                'success' => false,
                'message' => __('Payment verification failed. Please contact support if you were charged.'),
            ];
        }
    }

    /**
     * Handle MyFatoorah webhook v2 events.
     */
    public function handleWebhook(Request $request)
    {
        $payload = $request->getContent();
        $signature = $request->header('myfatoorah-signature');
        $config = MyFatoorahApiClient::resolveConfig();

        if (!$this->signatureVerifier->verify($payload, $signature, $config['webhook_secret'] ?? null)) {
            Log::warning('MyFatoorah webhook signature verification failed');

            return response()->json(['status' => 'error', 'message' => 'Invalid signature'], 401);
        }

        $event = json_decode($payload, true);

        if (!is_array($event)) {
            return response()->json(['status' => 'error', 'message' => 'Invalid payload'], 400);
        }

        $eventName = $event['Event']['Name'] ?? $event['EventName'] ?? null;

        if ($eventName !== 'PAYMENT_STATUS_CHANGED') {
            return response()->json(['status' => 'ignored'], 200);
        }

        $transaction = $event['Data']['Transaction'] ?? $event['Transaction'] ?? [];
        $status = strtoupper((string) ($transaction['Status'] ?? ''));

        if ($status !== 'SUCCESS') {
            Log::info('MyFatoorah webhook received non-success status', [
                'status' => $status,
                'payment_id' => $transaction['PaymentId'] ?? null,
            ]);

            return response()->json(['status' => 'ignored'], 200);
        }

        $paymentId = (string) ($transaction['PaymentId'] ?? '');

        if ($paymentId === '') {
            return response()->json(['status' => 'error', 'message' => 'Missing payment id'], 422);
        }

        $this->verifyAndProcessPayment($paymentId);

        return response()->json(['status' => 200], 200);
    }

    private function isPaidStatus(object $data): bool
    {
        $invoiceStatus = strtoupper((string) ($data->InvoiceStatus ?? ''));

        if (in_array($invoiceStatus, ['PAID', 'SUCCESS'], true)) {
            return true;
        }

        $transactions = $data->InvoiceTransactions ?? [];

        foreach ($transactions as $transaction) {
            $transaction = is_array($transaction) ? (object) $transaction : $transaction;
            $transactionStatus = strtoupper((string) ($transaction->TransactionStatus ?? ''));

            if (in_array($transactionStatus, ['SUCCSS', 'SUCCESS', 'PAID'], true)) {
                return true;
            }
        }

        return false;
    }

    private function extractFailureReason(object $data): ?string
    {
        foreach ($data->InvoiceTransactions ?? [] as $transaction) {
            $transaction = is_array($transaction) ? (object) $transaction : $transaction;
            $status = strtoupper((string) ($transaction->TransactionStatus ?? ''));

            if (in_array($status, ['FAILED', 'CANCELED', 'CANCELLED'], true)) {
                $error = trim((string) ($transaction->Error ?? ''));

                if ($error !== '') {
                    return __('Payment failed: :reason', ['reason' => $error]);
                }

                return __('Payment was declined by the gateway.');
            }
        }

        $invoiceStatus = strtolower((string) ($data->InvoiceStatus ?? ''));

        if ($invoiceStatus === 'pending') {
            return __('Payment is still pending. Please complete the payment on MyFatoorah or try again.');
        }

        return null;
    }

    private function buildContextFromStatusResponse(object $data, string $paymentId): array
    {
        $reference = $this->processor->parseCustomerReference($data->CustomerReference ?? null);
        $userDefined = json_decode($data->UserDefinedField ?? '', true);

        if (is_array($userDefined)) {
            $reference['organization_id'] = $userDefined['organization_id'] ?? $reference['organization_id'];
            $reference['user_id'] = $userDefined['user_id'] ?? $reference['user_id'];
            $reference['plan_id'] = ($userDefined['plan_id'] ?? null) === 'topup'
                ? null
                : ($userDefined['plan_id'] ?? $reference['plan_id']);
        }

        $transaction = null;

        foreach ($data->InvoiceTransactions ?? [] as $item) {
            $item = is_array($item) ? (object) $item : $item;

            if ((string) ($item->PaymentId ?? '') === $paymentId) {
                $transaction = $item;
                break;
            }
        }

        if ($transaction === null && !empty($data->InvoiceTransactions)) {
            $first = $data->InvoiceTransactions[0];
            $transaction = is_array($first) ? (object) $first : $first;
        }

        $amountDetails = $this->resolvePaymentAmount($data, $userDefined, $transaction);

        return [
            'payment_id' => $paymentId,
            'invoice_id' => (string) ($data->InvoiceId ?? ''),
            'amount' => $amountDetails['amount'],
            'currency' => $amountDetails['currency'],
            'payment_method' => (string) ($transaction->PaymentGateway ?? $transaction->PaymentMethod ?? ''),
            'payment_status' => strtolower((string) ($data->InvoiceStatus ?? 'paid')),
            'organization_id' => (int) ($reference['organization_id'] ?? 0),
            'user_id' => (int) ($reference['user_id'] ?? 0),
            'plan_id' => $reference['plan_id'],
        ];
    }

    /**
     * Resolve the amount credited to the customer account.
     *
     * In MyFatoorah sandbox (KWT test token), InvoiceValue is in KWD while the
     * customer-facing amount stays in SAR (InvoiceDisplayValue). Production SA
     * accounts charge in SAR directly.
     */
    private function resolvePaymentAmount(object $data, ?array $userDefined, ?object $transaction): array
    {
        if (is_array($userDefined) && !empty($userDefined['requested_amount'])) {
            return [
                'amount' => (float) $userDefined['requested_amount'],
                'currency' => (string) ($userDefined['requested_currency'] ?? MyFatoorahApiClient::resolveConfig()['currency']),
            ];
        }

        $displayParsed = $this->parseInvoiceDisplayValue($data->InvoiceDisplayValue ?? null);

        if ($displayParsed !== null) {
            return $displayParsed;
        }

        $currency = strtoupper((string) (
            $data->InvoiceDisplayCurrency
            ?? $transaction->PaidCurrency
            ?? $transaction->Currency
            ?? MyFatoorahApiClient::resolveConfig()['currency']
        ));

        $currency = match ($currency) {
            'KD' => 'KWD',
            'SR' => 'SAR',
            default => $currency,
        };

        return [
            'amount' => (float) ($data->InvoiceValue ?? 0),
            'currency' => $currency,
        ];
    }

    private function parseInvoiceDisplayValue(?string $displayValue): ?array
    {
        if (empty($displayValue)) {
            return null;
        }

        if (!preg_match('/^([\d,.]+)\s*([A-Za-z]{2,3}|SR)?/i', trim($displayValue), $matches)) {
            return null;
        }

        $currency = strtoupper($matches[2] ?? 'SAR');

        return [
            'amount' => (float) str_replace(',', '', $matches[1]),
            'currency' => match ($currency) {
                'SR' => 'SAR',
                'KD' => 'KWD',
                default => $currency,
            },
        ];
    }

    private function formatValidationErrors(mixed $validationErrors): ?string
    {
        if (empty($validationErrors)) {
            return null;
        }

        $messages = [];

        foreach ($validationErrors as $error) {
            $error = (object) $error;
            $messages[] = trim(($error->Name ?? '') . ': ' . ($error->Error ?? ''));
        }

        return implode(' | ', array_filter($messages)) ?: null;
    }
}
