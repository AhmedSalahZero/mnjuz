<?php

namespace App\Services;

use App\Events\NewPaymentEvent;
use App\Models\BillingPayment;
use App\Models\BillingTransaction;
use App\Models\PaymentGateway;
use App\Models\Setting;
use App\Models\User;
use App\Services\SubscriptionService;
use App\Traits\ConsumesExternalServices;
use Carbon\Carbon;
use CurrencyHelper;
use Illuminate\Support\Facades\DB;
use Helper;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

class PayPalService
{
    protected $config;
    protected $subscriptionService;
    protected $baseUri;
    protected $clientId;
    protected $clientSecret;

    public function __construct()
    {
        $this->subscriptionService = new SubscriptionService();

        $paypalInfo = PaymentGateway::where('name', 'Paypal')->first();

        $this->config = json_decode($paypalInfo->metadata);
        $this->baseUri = $this->config->mode == 'sandbox' ? 'https://api-m.sandbox.paypal.com/' : 'https://api-m.paypal.com/';
        $this->clientId = $this->config->client_id;
        $this->clientSecret = $this->config->secret;

        Config::set('broadcasting.connections.pusher', [
            'driver' => 'pusher',
            'key' => Setting::where('key', 'pusher_app_key')->value('value'),
            'secret' => Setting::where('key', 'pusher_app_secret')->value('value'),
            'app_id' => Setting::where('key', 'pusher_app_id')->value('value'),
            'options' => [
                'cluster' => Setting::where('key', 'pusher_app_cluster')->value('value'),
            ],
        ]);
    }

    public function resolveAccessToken()
    {
        $httpClient = new HttpClient();

        // Attempt to retrieve the auth token
        try {
            $payPalAuthRequest = $httpClient->request('POST', $this->baseUri . 'v1/oauth2/token', [
                    'auth' => [$this->clientId, $this->clientSecret],
                    'form_params' => [
                        'grant_type' => 'client_credentials'
                    ]
                ]
            );

            return json_decode($payPalAuthRequest->getBody()->getContents())->access_token;
        } catch (RequestException $e) {
            Log::info($e->getResponse()->getBody()->getContents());

            return response()->json([
                'status' => 400,
                'error' => $e->getResponse()->getBody()->getContents()
            ], 400);
        }
    }

    public function makeRequest($method, $url, $body)
    {
        $httpClient = new HttpClient();

        try {
            $request = $httpClient->request($method, $this->baseUri . $url, [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $this->resolveAccessToken(),
                        'Content-Type' => 'application/json'
                    ],
                    'body' => json_encode($body)
                ]
            );

            return (object) array('success' => true, 'data' => json_decode($request->getBody()->getContents()));
        } catch (RequestException $e) {
            return (object) array('success' => false, 'error' => json_decode($e->getResponse()->getBody()->getContents()));
        }
    }

    public function handlePayment($amount, $planId = NULL)
    {
        $currency = Setting::where('key', 'currency')->first()->value;
        $returnUrl = url('billing');
        $cancelUrl = url('billing');

        try {
            $pay = $this->makeRequest(
                'POST',
                'v1/payments/payment',
                [
                    'intent' => 'sale',
                    'payer' => [
                        'payment_method' => 'paypal',
                    ],
                    'redirect_urls' => [
                        'return_url' => $returnUrl,
                        'cancel_url' => $cancelUrl,
                    ],
                    'transactions' => [
                        [
                            'amount' => [
                                'total' => number_format((float) $amount, 2, '.', ''),
                                'currency' => $currency,
                            ],
                            'description' => 'Subscription Payment',
                            'custom' => session()->get('current_organization') . '_' . auth()->user()->id . '_' . $planId,
                        ],
                    ],
                    'application_context' => [
                        'brand_name' => Setting::where('key', 'company_name')->first()->value,
                        'shipping_preference' => 'NO_SHIPPING',
                    ],
                ]
            );

            return (object) array('success' => true, 'data' => $pay->data->links[1]->href);
        } catch (\Exception $e) {
            return (object) array('success' => false, 'error' => $e->getMessage());
        }
    }

    protected function isValidWebhook()
    {
        // get request headers
        $headers = apache_request_headers();
        $headers = array_change_key_case($headers, CASE_UPPER);

        // get http payload
        $body = file_get_contents('php://input');

        $data =
            $headers['PAYPAL-TRANSMISSION-ID'] . '|' .
            $headers['PAYPAL-TRANSMISSION-TIME'] . '|' .
            $this->config->webhook_id . '|' . crc32($body);


        // load certificate and extract public key
        $pubKey = openssl_pkey_get_public(file_get_contents($headers['PAYPAL-CERT-URL']));
        $key = openssl_pkey_get_details($pubKey)['key'];

        // verify data against provided signature 
        $result = openssl_verify(
            $data,
            base64_decode($headers['PAYPAL-TRANSMISSION-SIG']),
            $key,
            'sha256WithRSAEncryption'
        );


        if ($result == 1) {
            // webhook notification is verified
            Log::info('webhook verified');
            return true;
        } elseif ($result == 0) {
            // webhook notification is NOT verified
            Log::info('webhook not verified');
            return false;
        } else {
            // there was an error verifying this
            Log::info('webhook verification error');
            return false;
        }
    }
	
    public function handleWebhook(Request $request)
	{
		if (!$this->isValidWebhook($request)) {
			Log::error('Received invalid webhook');
			return response('Invalid webhook', 400);
		}


		if ($request->event_type == "PAYMENTS.PAYMENT.CREATED") {
			try {
				$paymentId = $request->resource['id'];
				$payerId = $request->resource['payer']['payer_info']['payer_id'];

				$executionResult = $this->executePaymentFromWebhook($paymentId, $payerId);

				if (!$executionResult->success) {
					Log::error('Failed to execute payment: ' . json_encode($executionResult->error));
					return response('Payment execution failed', 400);
				}

				$transaction = DB::transaction(function () use ($request) {
					$transactionData = $request->resource['transactions'][0];
					$metadata = $transactionData['custom'] ?? null;

					if($metadata){
						$metadata = explode('_', $metadata);
						$organizationId = $metadata[0] ?? null;
						$userId = $metadata[1] ?? null;
						$planId = ($metadata[2] !== '') ? $metadata[2] : null;
						$amount = $transactionData['amount']['total'];

						$payment = BillingPayment::create([
							'organization_id' => $organizationId,
							'processor' => 'paypal',
							'details' => $request->resource['id'],
							'amount' => $amount
						]);

						$transaction = BillingTransaction::create([
							'organization_id' => $organizationId,
							'entity_type' => 'payment',
							'entity_id' => $payment->id,
							'description' => 'PayPal Payment',
							'amount' => $amount,
							'created_by' => $userId,
						]);

						if($planId == null){
							$this->subscriptionService->activateSubscriptionIfInactiveAndExpiredWithCredits($organizationId, $userId);
						} else {
							$this->subscriptionService->updateSubscriptionPlan($organizationId, $planId, $userId);
						}

						event(new NewPaymentEvent($transaction, $organizationId));
						return $transaction;
					}
				});

				return response('Webhook processed', 200);
			} catch (\Exception $e) {
				Log::error('Error processing PayPal webhook: ' . $e->getMessage());
				return response('Error processing webhook', 500);
			}
		}

		return response('Webhook received', 200);
	}

	private function executePaymentFromWebhook($paymentId, $payerId)
	{
		try {
			$request = $this->makeRequest(
				'POST',
				"v1/payments/payment/{$paymentId}/execute",
				[
					'payer_id' => $payerId
				]
			);

			return $request;
		} catch (\Exception $e) {
			Log::error('PayPal execute payment error: ' . $e->getMessage());
			return (object) array('success' => false, 'error' => $e->getMessage());
		}
	}
}