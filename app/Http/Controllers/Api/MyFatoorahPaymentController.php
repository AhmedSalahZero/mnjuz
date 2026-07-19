<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\MyFatoorahInitPaymentRequest;
use App\Http\Resources\MyFatoorahPaymentResource;
use App\Resolvers\PaymentPlatformResolver;
use App\Services\MyFatoorah\MyFatoorahApiClient;
use App\Services\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MyFatoorahPaymentController extends Controller
{
    public function __construct(
        private readonly PaymentPlatformResolver $paymentPlatformResolver = new PaymentPlatformResolver(),
    ) {
    }

    /**
     * Initialize a MyFatoorah hosted payment session.
     */
    public function initialize(MyFatoorahInitPaymentRequest $request): JsonResponse
    {
        $organizationId = (int) session()->get('current_organization');
        $userId = (int) auth()->id();
        $planId = $request->input('plan_id');
        $amount = (float) $request->input('amount');

        if ($planId) {
            $billingDetails = SubscriptionService::calculateSubscriptionBillingDetails($organizationId, $planId);
            $amount = (float) str_replace(',', '', $billingDetails['amountDue']);
        }

        if ($amount <= 0) {
            return response()->json([
                'success' => false,
                'message' => __('No payment is required for this request.'),
            ], 422);
        }

        session()->put('current_organization', $organizationId);
        session()->put('paymentPlatform', 'MyFatoorah');

        $paymentPlatform = $this->paymentPlatformResolver->resolveService('MyFatoorah');
        $response = $paymentPlatform->handlePayment($amount, $planId);

        if (!($response->success ?? false)) {
            return response()->json([
                'success' => false,
                'message' => $response->error ?? __('Could not initialize payment.'),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => new MyFatoorahPaymentResource((object) [
                'payment_url' => $response->data,
                'invoice_id' => $response->invoice_id ?? null,
                'amount' => $amount,
                'currency' => MyFatoorahApiClient::resolveConfig()['currency'],
            ]),
        ]);
    }

    /**
     * Verify a MyFatoorah payment status by payment ID.
     */
    public function status(Request $request, string $paymentId): JsonResponse
    {
        $paymentPlatform = $this->paymentPlatformResolver->resolveService('MyFatoorah');
        $result = $paymentPlatform->verifyAndProcessPayment($paymentId);

        return response()->json([
            'success' => (bool) ($result->success ?? false),
            'duplicate' => (bool) ($result->duplicate ?? false),
            'message' => $result->message ?? null,
            'payment' => $result->payment ?? null,
        ], ($result->success ?? false) ? 200 : 422);
    }
}
