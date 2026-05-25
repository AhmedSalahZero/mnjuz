<?php

namespace App\Services\MyFatoorah;

use App\Models\BillingPayment;
use App\Models\BillingTransaction;
use App\Models\Setting;
use App\Models\User;
use App\Services\SubscriptionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MyFatoorahPaymentProcessor
{
    public function __construct(
        private readonly SubscriptionService $subscriptionService = new SubscriptionService(),
    ) {
    }

    /**
     * Record a verified payment and activate the subscription when applicable.
     */
    public function processVerifiedPayment(array $context): object
    {
        $paymentId = (string) ($context['payment_id'] ?? '');
        $invoiceId = (string) ($context['invoice_id'] ?? '');
        $amount = (float) ($context['amount'] ?? 0);
        $currency = (string) ($context['currency'] ?? config('myfatoorah.currency', 'SAR'));
        $paymentMethod = (string) ($context['payment_method'] ?? '');
        $paymentStatus = (string) ($context['payment_status'] ?? 'paid');
        $organizationId = (int) ($context['organization_id'] ?? 0);
        $userId = (int) ($context['user_id'] ?? 0);
        $planId = $context['plan_id'] ?? null;

        if ($paymentId === '' || $organizationId <= 0) {
            Log::warning('MyFatoorah payment context incomplete', $context);

            return (object) ['success' => false, 'message' => 'Incomplete payment context'];
        }

        if ($this->paymentAlreadyProcessed($paymentId, $invoiceId)) {
            Log::info('MyFatoorah payment already processed', [
                'payment_id' => $paymentId,
                'invoice_id' => $invoiceId,
            ]);

            return (object) ['success' => true, 'duplicate' => true];
        }

        DB::transaction(function () use (
            $paymentId,
            $invoiceId,
            $amount,
            $currency,
            $paymentMethod,
            $paymentStatus,
            $organizationId,
            $userId,
            $planId
        ) {
            $payment = BillingPayment::create([
                'organization_id' => $organizationId,
                'processor' => config('myfatoorah.processor'),
                'details' => $paymentId,
                'transaction_id' => $paymentId,
                'invoice_id' => $invoiceId !== '' ? $invoiceId : null,
                'payment_status' => $paymentStatus,
                'payment_method' => $paymentMethod !== '' ? $paymentMethod : null,
                'currency' => $currency,
                'amount' => $amount,
            ]);

            BillingTransaction::create([
                'organization_id' => $organizationId,
                'entity_type' => 'payment',
                'entity_id' => $payment->id,
                'description' => $this->buildDescription($paymentMethod),
                'amount' => $amount,
                'created_by' => $userId,
            ]);

            if ($planId === null || $planId === '' || $planId === 'topup') {
                $this->subscriptionService->activateSubscriptionIfInactiveAndExpiredWithCredits($organizationId, $userId);
            } else {
                $this->subscriptionService->updateSubscriptionPlan($organizationId, (int) $planId, $userId);
            }
        });

        return (object) ['success' => true, 'duplicate' => false];
    }

    public function buildCustomerReference(int $organizationId, int $userId, mixed $planId = null): string
    {
        $planSegment = $planId === null ? 'topup' : (string) $planId;

        return "{$organizationId}_{$userId}_{$planSegment}";
    }

    public function parseCustomerReference(?string $reference): array
    {
        if (empty($reference)) {
            return [
                'organization_id' => null,
                'user_id' => null,
                'plan_id' => null,
            ];
        }

        $parts = explode('_', $reference);

        return [
            'organization_id' => isset($parts[0]) ? (int) $parts[0] : null,
            'user_id' => isset($parts[1]) ? (int) $parts[1] : null,
            'plan_id' => isset($parts[2]) && $parts[2] !== 'topup' ? $parts[2] : null,
        ];
    }

    public function buildExecutePaymentPayload(float $amount, int $organizationId, int $userId, mixed $planId = null): array
    {
        $config = MyFatoorahApiClient::resolveConfig();
        $user = User::find($userId);
        $companyName = Setting::where('key', 'company_name')->value('value') ?? config('app.name');

        $planSegment = $planId === null ? 'topup' : (string) $planId;
        $reference = $this->buildCustomerReference($organizationId, $userId, $planId);

        return [
            'InvoiceValue' => round($amount, 2),
            'DisplayCurrencyIso' => $config['currency'],
            'CustomerName' => trim(($user?->first_name ?? '') . ' ' . ($user?->last_name ?? '')) ?: 'Customer',
            'CustomerEmail' => $user?->email,
            'CustomerMobile' => $user?->phone,
            'MobileCountryCode' => '+966',
            'CustomerReference' => $reference,
            'UserDefinedField' => json_encode([
                'organization_id' => $organizationId,
                'user_id' => $userId,
                'plan_id' => $planSegment,
            ]),
            'CallBackUrl' => url('/payment/myfatoorah/success'),
            'ErrorUrl' => url('/payment/myfatoorah/error'),
            'Language' => $config['language'],
            'NotificationOption' => 'LNK',
            'InvoiceItems' => [
                [
                    'ItemName' => $planId ? 'Subscription Plan' : 'Account Top-up',
                    'Quantity' => 1,
                    'UnitPrice' => round($amount, 2),
                ],
            ],
            'Suppliers' => [],
            'ProcessingDetails' => [
                'AutoCapture' => true,
                'Bypass3DS' => false,
            ],
            'CustomerCivilId' => null,
            'ExpiryDate' => null,
            'SourceInfo' => $companyName,
        ];
    }

    private function paymentAlreadyProcessed(string $paymentId, string $invoiceId): bool
    {
        return BillingPayment::where('processor', config('myfatoorah.processor'))
            ->where(function ($query) use ($paymentId, $invoiceId) {
                $query->where('details', $paymentId)
                    ->orWhere('transaction_id', $paymentId);

                if ($invoiceId !== '') {
                    $query->orWhere('invoice_id', $invoiceId);
                }
            })
            ->exists();
    }

    private function buildDescription(?string $paymentMethod): string
    {
        $label = 'MyFatoorah Payment';

        if (!empty($paymentMethod)) {
            $label .= ' (' . $paymentMethod . ')';
        }

        return $label;
    }
}
