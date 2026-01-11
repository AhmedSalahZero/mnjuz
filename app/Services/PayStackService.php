<?php

namespace App\Services;

use Carbon\Carbon;
use DB;
use Helper;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Http\Request;
use App\Models\BillingHistory;
use App\Models\Coupon;
use App\Models\User;
use App\Models\UserSubscription;
use App\Traits\ConsumesExternalServices;

class PayStackService
{
    public function __construct()
    {
        $paystackInfo = DB::table('integrations')->where('name', 'PayStack')->first();
        $this->config = unserialize($paystackInfo->data);
        $this->baseUri = 'https://api.paystack.co';
        $this->secretKey = $this->config['secret_key'];
    }

    public function makeRequest($method, $url, $body)
    {
        $httpClient = new HttpClient();

        try {
            $request = $httpClient->request($method, $this->baseUri . $url, [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $this->secretKey,
                        'Content-Type' => 'application/json',
                        'Cache-Control' => 'no-cache'
                    ],
                    'body' => json_encode($body)
                ]
            );

            return (object) array('success' => true, 'data' => json_decode($request->getBody()->getContents()));
        } catch (RequestException $e) {
            return (object) array('success' => false, 'error' => json_decode($e->getResponse()->getBody()->getContents()));
        }
    }

    public function createPlan($plan, $amount, $paystackAmount, $interval, $coupon, $taxRates)
    {
        return $this->makeRequest(
            'POST',
            '/plan',
            [
                'name' => $plan->name,
                'interval' => 'monthly',
                'amount' => $paystackAmount,
                'currency' => Helper::config('currency'),
                'description' => http_build_query([
                    'user' => auth()->user()->id,
                    'plan' => $plan->id,
                    'plan_amount' => $interval == 'year' ? $plan->yearly_price : $plan->monthly_price,
                    'amount' => $amount,
                    'interval' => $interval,
                    "transcurrency" => Helper::config('currency'),
                    'coupon' => $coupon->id ?? null,
                    'tax_rates' => isset($taxRates) ?? $taxRates->pluck('id')->implode('_'),
                ])
            ],
        );
    }

    public function createSubscription($plan, $paystackPlan, $paystackAmount)
    {
        return $this->makeRequest(
            'POST',
            '/transaction/initialize',
            [
                'email' => auth()->user()->email,
                'amount' => $paystackAmount,
                'currency' => Helper::config('currency'),
                'callback_url' => url('billing'),
                'plan' => $paystackPlan->data->plan_code
            ],
        );
    }

    public function handleSubscription(Request $request,$plan, $coupon, $taxRates, $amount, $interval)
    {
        $paystackAmount = in_array(Helper::config('currency'), config('currencies.zero_decimals')) ? $amount : ($amount * 100);

        // Attempt to create the plan
        $paystackPlan = $this->createPlan($plan, $amount, $paystackAmount, $interval, $coupon, $taxRates);

        if($paystackPlan->success){
            // Attempt to create the subscription
            $paystackSubscription = $this->createSubscription($plan, $paystackPlan->data, $paystackAmount);
            return redirect($paystackSubscription->data->data->authorization_url);
        } else {
            dd($paystackPlan);
        } 
    }

    public function getSubscription($plan_subscription_id)
    {
        return $this->makeRequest(
            'POST',
            '/subscription' . '/' . $plan_subscription_id,
            [],
        );
    }

    public function cancelSubscription($plan_subscription_id)
    {
        $paystackSubscription = $this->getSubscription($plan_subscription_id);

        if (isset($paystackSubscription->data->data->email_token)) {
            return $this->makeRequest(
                'POST',
                '/subscription/disable',
                [
                    'code' => $plan_subscription_id,
                    'token' => $paystackSubscription->data->data->email_token
                ],
            );
        }
    }

    public function handleWebhook(Request $request)
    {
        $payload = json_decode($request->getContent());

        $signature = $request->header('x-paystack-signature');

        $computedSignature = hash_hmac('sha512', $request->getContent(), $this->config['secret_key']);

        // Validate the webhook signature
        if (hash_equals($computedSignature, $signature)) {
            // Get the metadata
            // Parse the custom metadata parameters
            parse_str($payload->data->plan->description ?? null, $metadata);

            if (isset($metadata['user'])) {
                $user = User::where('id', '=', $metadata['user'])->first();
                $user_subscription = UserSubscription::where('user_id', $metadata['user'])->first();

                // If a user was found
                if ($user) {
                    if ($payload->event == 'subscription.create') {
                        // If the user previously had a subscription, attempt to cancel it
                        if ($user_subscription->subscription_id) {
                            $user_subscription->cancelSubscription();
                        }

                        $user_subscription->plan_id = $metadata['plan'];
                        $user_subscription->plan_amount = $metadata['amount'];
                        $user_subscription->plan_interval = $metadata['interval'];
                        $user_subscription->payment_processor = 'paystack';
                        $user_subscription->subscription_id = $payload->data->subscription_code;
                        $user_subscription->status = $payload->data->status;
                        $user_subscription->created_at = Carbon::now();
                        $user_subscription->recurring_at = $payload->data->next_payment_date ? Carbon::createFromTimeString($payload->data->next_payment_date) : null;
                        $user_subscription->cancelled_at = null;
                        $user_subscription->save();

                        // If a coupon was used
                        if (isset($metadata['coupon']) && $metadata['coupon']) {
                            $coupon = Coupon::find($metadata['coupon']);

                            // If a coupon was found
                            if ($coupon) {
                                // Increase the coupon usage
                                $coupon->increment('quantity_redeemed', 1);
                            }
                        }
                    } elseif (stripos($payload->event, 'subscription.') !== false) {
                        // If the subscription exists
                        if ($user_subscription->payment_processor == 'paystack' && $user_subscription->subscription_id == $payload->data->subscription_code) {
                            // Update the recurring date
                            if ($payload->data->next_payment_date) {
                                $user_subscription->recurring_at = Carbon::createFromTimeString($payload->data->next_payment_date);
                                $user_subscription->cancelled_at = null;
                            } else {
                                // Update the subscription end date and recurring date
                                if (!empty($user_subscription->recurring_at)) {
                                    $user_subscription->cancelled_at = $user_subscription->recurring_at;
                                    $user_subscription->recurring_at = null;
                                }
                            }

                            // Update the subscription status
                            if ($payload->data->status) {
                                $user_subscription->status = $payload->data->status;
                            }

                            $user_subscription->save();
                        }
                    }

                    if ($payload->event == 'charge.success') {
                        // If the payment does not exist
                        if (!BillingHistory::where([['processor', '=', 'paystack'], ['payment_id', '=', $payload->data->reference]])->exists()) {
                            $payment = BillingHistory::create([
                                'user_id' => $user->id,
                                'plan_id' => $metadata['plan'],
                                'payment_id' => $payload->data->reference,
                                'processor' => 'paystack',
                                'amount' => $metadata['amount'],
                                'currency' => $metadata['transcurrency'],
                                'interval' => $metadata['interval'],
                                'status' => 'completed',
                                'coupon' => $metadata['coupon'] ?? null,
                                'tax_rates' => $metadata['tax_rates'] ?? null,
                                'customer' => $user->billing_information,
                                'billing_date' => date('Y-m-d H:i:s')
                            ]);

                            // Attempt to send the payment confirmation email
                            /*try {
                                Mail::to($user->email)->locale($user->locale)->send(new PaymentMail($payment));
                            }
                            catch (\Exception $e) {}*/
                        }
                    }
                }
            }
        } else {
            //Log::info('Paystack signature validation failed.');

            return response()->json([
                'status' => 400,
                'error' => __('Paystack signature validation failed.')
            ], 400);
        }

        return response()->json([
            'status' => 200
        ], 200);
    }
}