<?php

namespace App\Services;

use App\Models\BillingPayment;
use App\Models\BillingTransaction;
use App\Models\PaymentGateway;
use App\Models\Setting;
use App\Models\User;
use App\Services\SubscriptionService;
use App\Traits\ConsumesExternalServices;
use Carbon\Carbon;
use CurrencyHelper;
use DB;
use Helper;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FlutterwaveService
{
    public function __construct()
    {
        $this->subscriptionService = new SubscriptionService();

        $methodInfo = PaymentGateway::where('name', 'Flutterwave')->first();

        $this->config = json_decode($methodInfo->metadata);
        $this->baseUri = 'https://api.flutterwave.com/';
        $this->publicKey = $this->config->public_key;
        $this->secretKey = $this->config->secret_key;
    }

    public function makeRequest($method, $url, $body = NULL)
    {
        $httpClient = new HttpClient();

        try {
            $request = $httpClient->request($method, $this->baseUri . $url, [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $this->secretKey,
                        'Content-Type' => 'application/json'
                    ],
                    'body' => $body != null ? json_encode($body) : null
                ]
            );

            return (object) array('success' => true, 'data' => json_decode($request->getBody()->getContents()));
        } catch (RequestException $e) {
            Log::error("Error Code:" . $e->getMessage());
            
            return (object) array('success' => false, 'error' => json_decode($e->getResponse()->getBody()->getContents()));
        }
    }

    public function handlePayment($amount, $planId = NULL)
    {
        $currency = strtoupper(Setting::where('key', 'currency')->first()->value);
        $redirectUrl = url('payment/flutterwave');

        $user = User::where('id', auth()->user()->id)->first();

        try {
            $pay = $this->makeRequest(
                'POST',
                'v3/payments',
                [
                    'tx_ref' => 'Ref:' . auth()->user()->id . session()->get('current_organization') . now()->format('YmdHis'),
                    'amount' => $amount,
                    'currency' => $currency,
                    'redirect_url' => $redirectUrl,
                    'meta' => [
                        'organization_id' => session()->get('current_organization'),
                        'plan_id' => $planId,
                        'user_id' => auth()->user()->id,
                    ],
                    'customer' => [
                        'email' => $user->email,
                        'phonenumber' => $user->phone,
                        'name' => $user->first_name . ' ' . $user->last_name,
                    ],
                    'customizations' => [
                        'title' => Setting::where('key', 'company_name')->first()->value,
                    ]
                ]
            );

            return (object) array('success' => true, 'data' => $pay->data->data->link);
        } catch (\Exception $e) {
            // Log or handle the error as needed
            \Log::error("Error Code:" . $e->getMessage());

            return (object) array('success' => false, 'error' => $e->getMessage());
        }
    }

    public function verifyTransaction($transactionId)
    {
        return $this->makeRequest(
            'GET',
            'v3/transactions/'. $transactionId .'/verify'
        );
    }

    public function updateSubscription($dataId)
    {
        $verifiedTrans = $this->verifyTransaction($dataId);

        if($verifiedTrans->success && isset($verifiedTrans->data)){
            $request = $verifiedTrans->data;

            if($request->status === 'success'){
                $transaction = DB::transaction(function () use ($request) {
                    $metadata = $request->data->meta;

                    $organizationId = $metadata->organization_id ?? null;
                    $userId = $metadata->user_id ?? null;
                    $planId = $metadata->plan_id ?? null;
                    $amount = $request->data->amount;

                    //Check if payment exists
                    $exists = BillingPayment::where('processor', 'flutterwave')->where('details', $request->data->flw_ref)->first();

                    Log::info($exists);

                    if(!$exists){
                        $payment = BillingPayment::create([
                            'organization_id' => $organizationId,
                            'processor' => 'flutterwave',
                            'details' => $request->data->flw_ref,
                            'amount' => $amount
                        ]);

                        $transaction = BillingTransaction::create([
                            'organization_id' => $organizationId,
                            'entity_type' => 'payment',
                            'entity_id' => $payment->id,
                            'description' => 'Flutterwave Payment',
                            'amount' => $amount,
                            'created_by' => $userId,
                        ]);

                        if($planId == null){
                            $this->subscriptionService->activateSubscriptionIfInactiveAndExpiredWithCredits($organizationId, $userId);
                        } else {
                            $this->subscriptionService->updateSubscriptionPlan($organizationId, $planId, $userId);
                        }

                        //Log::debug($transaction);
                        return $transaction;
                    }
                });

                return (object) array('success' => true);
            }
        }

        return (object) array('success' => false);
    }

    public function handleWebhook(Request $request)
    {
        $this->updateSubscription($request->data['id']);
    }
}