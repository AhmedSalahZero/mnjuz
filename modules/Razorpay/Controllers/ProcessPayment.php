<?php

namespace Modules\Razorpay\Controllers;

use App\Http\Controllers\Controller as BaseController;
use App\Events\NewPaymentEvent;
use App\Models\BillingPayment;
use App\Models\BillingTransaction;
use App\Models\Setting;
use App\Services\SubscriptionService;
use Carbon\Carbon;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ProcessPayment extends BaseController
{
    protected $base_url;
    protected $currency;
    protected $key_id;
    protected $key_secret;
    protected $razorpay;
    protected $subscriptionService;
    protected $webhook_secret;

    public function __construct()
    {
        $this->base_url = 'https://api.razorpay.com/v1';
        $this->subscriptionService = new SubscriptionService();

        $settings = Setting::whereIn('key', [
            'razorpay_key_id', 
            'razorpay_secret_key', 
            'currency', 
            'razorpay_webhook_secret'
        ])->pluck('value', 'key');

        $this->key_id = $settings['razorpay_key_id'] ?? null;
        $this->key_secret = $settings['razorpay_secret_key'] ?? null;
        $this->currency = $settings['currency'] ?? null;
        $this->webhook_secret = $settings['razorpay_webhook_secret'] ?? null;
    }
    
    public function handlePayment($amount, $planId = NULL)
    {
        try {
            $httpClient = new HttpClient();
            $response = $httpClient->request('POST', 'https://api.razorpay.com/v1/payment_links', [
                    'headers' => [
                        'Authorization' => 'Basic ' . base64_encode($this->key_id . ':' . $this->key_secret),
                        'Content-Type' => 'application/json',
                        'Cache-Control' => 'no-cache'
                    ],
                    'body' => json_encode([
                        'amount'          => (int) ($amount * 100), // amount in the smallest currency unit (e.g., 50000 paise = 500 INR)
                        'currency'        => $this->currency,
                        'description'     => 'Subscription payment',
                        'callback_url'    => url('billing'),
                        'callback_method' => 'get',
                        'customer' => [
                            'name'         => auth()->user()->first_name. ' ' .auth()->user()->last_name,
                            'contact'      => null,
                            'email'        => auth()->user()->email,
                        ],
                        'notes' => [
                            'organization_id' => session()->get('current_organization'),
                            'user_id' => auth()->user()->id,
                            'plan_id' => $planId
                        ],
                        'reminder_enable' => false
                    ])
                ]
            );

            $statusCode = $response->getStatusCode();

            if ($statusCode == 200 || $statusCode == 201) {
                $paymentLink = json_decode($response->getBody()->getContents(), true);
                return (object) array('success' => true, 'data' => $paymentLink['short_url']);
            } else {
                return (object) array('success' => false, 'error' => 'Unable to create payment link');
            }
        } catch (\Exception $e) {
            // Handle any exceptions that occur during the HTTP request
            return (object) array('success' => false, 'error' => $e->getMessage());
        }
    }

    public function handleWebhook(Request $request)
    {
        $payload = json_decode($request->getContent(), true);
        $signature = $request->header('X-Razorpay-Signature');

        // Check if the signature is null
        if (is_null($signature)) {
            Log::error('Razorpay Webhook signature is missing.');
            return response()->json(['status' => 'error', 'message' => 'Missing signature'], 400);
        }
        
        $computedSignature = hash_hmac('sha256', $request->getContent(), $this->webhook_secret);

        // Validate the webhook signature
        if (hash_equals($computedSignature, $signature)) {
            if ($payload['event'] === 'payment.authorized') {
                $transaction = DB::transaction(function () use ($payload) {
                    $notes = $payload['payload']['payment']['entity']['notes'];
                    $organizationId = $notes['organization_id'] ?? null;
                    $userId = $notes['user_id'] ?? null;
                    $planId = $notes['plan_id'] ?? null;
                    $amount = $payload['payload']['payment']['entity']['amount'] / 100; // Convert paise to currency

                    $payment = BillingPayment::create([
                        'organization_id' => $organizationId,
                        'processor' => 'razorpay',
                        'details' => $payload['payload']['payment']['entity']['id'],
                        'amount' => $amount
                    ]);

                    $transaction = BillingTransaction::create([
                        'organization_id' => $organizationId,
                        'entity_type' => 'payment',
                        'entity_id' => $payment->id,
                        'description' => 'Razorpay Payment',
                        'amount' => $amount,
                        'created_by' => $userId,
                    ]);

                    if ($planId == null) {
                        $this->subscriptionService->activateSubscriptionIfInactiveAndExpiredWithCredits($organizationId, $userId);
                    } else {
                        $this->subscriptionService->updateSubscriptionPlan($organizationId, $planId, $userId);
                    }

                    // Uncomment if you have an event or further processing to do
                    // event(new NewPaymentEvent($transaction, $organizationId));

                    return $transaction;
                });
            }
        } else {
            Log::error('Invalid Razorpay Webhook signature.');
            return response()->json(['status' => 'error', 'message' => 'Invalid signature'], 400);
        }

        return response()->json([
            'status' => 200
        ], 200);
    }
}
