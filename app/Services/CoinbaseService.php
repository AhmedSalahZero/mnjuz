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

class CoinbaseService
{
    public function __construct()
    {
        $coinbaseInfo = DB::table('integrations')->where('name', 'Coinbase')->first();
        $this->config = unserialize($coinbaseInfo->data);
        $this->baseUri = 'https://api.commerce.coinbase.com';
        $this->secretKey = $this->config['api_key'];
    }

    public function makeRequest($method, $url, $body)
    {
        $httpClient = new HttpClient();

        try {
            $request = $httpClient->request($method, $this->baseUri . $url, [
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'X-CC-Api-Key' => $this->secretKey,
                        'X-CC-Version' => '2018-03-22',
                    ],
                    'body' => json_encode($body)
                ]
            );

            return (object) array('success' => true, 'data' => json_decode($request->getBody()->getContents()));
        } catch (RequestException $e) {
            return (object) array('success' => false, 'error' => json_decode($e->getResponse()->getBody()->getContents()));
        }
    }

    public function charge($plan, $coupon, $taxRates, $amount, $interval){
        return $this->makeRequest(
            'POST',
            '/charges',
            [
                'name' => $plan->name,
                'description' => $plan->description,
                'local_price' => [
                    'amount' => $amount,
                    'currency' => Helper::config('currency'),
                ],
                'pricing_type' => 'fixed_price',
                'metadata' => [
                    'user' => auth()->user()->id,
                    'plan' => $plan->id,
                    'plan_amount' => $interval == 'year' ? $plan->yearly_price : $plan->monthly_price,
                    'amount' => $amount,
                    'currency' => Helper::config('currency'),
                    'interval' => $interval,
                    'coupon' => $coupon->id ?? null,
                    'tax_rates' => isset($taxRates) ?? $taxRates->pluck('id')->implode('_')
                ],
                'redirect_url' => url('billing'),
                'cancel_url' => url('billing'),
            ],
        );
    }

    public function handleSubscription(Request $request,$plan, $coupon, $taxRates, $amount, $interval)
    {
        //Attempt to charge user
        $charge = $this->charge($plan, $coupon, $taxRates, $amount, $interval);

        if($charge->success){
            return redirect($charge->data->data->hosted_url);
        } else {
            dd($charge->error);
        }
    }

    public function handleWebhook(Request $request)
    {
        $payload = json_decode($request->getContent());

        $computedSignature = hash_hmac('sha256', $request->getContent(), $this->config['webhook_key']);

        // Validate the webhook signature
        if (hash_equals($computedSignature, $request->server('HTTP_X_CC_WEBHOOK_SIGNATURE'))) {
            // If the payment was successful
            $metadata = $payload->event->data->metadata ?? null;

            if (isset($metadata->user)) {
                $user = User::where('id', '=', $metadata->user)->first();
                $user_subscription = UserSubscription::where('user_id', '=', $metadata->user)->first();

                // If a user was found
                if ($user) {
                    if ($payload->event->type == 'charge:confirmed' || $payload->event->type == 'charge:resolved') {
                        // If the payment does not exist
                        if (!BillingHistory::where([['processor', '=', 'coinbase'], ['payment_id', '=', $payload->event->data->code]])->exists()) {
                            $now = Carbon::now();

                            // If the user previously had a subscription, attempt to cancel it
                            if ($user_subscription->subscription_id) {
                                $user_subscription->cancelSubscription();
                            }

                            $user_subscription->plan_id = $metadata->plan;
                            $user_subscription->plan_amount = $metadata->amount;
                            $user_subscription->plan_interval = $metadata->interval;
                            $user_subscription->payment_processor = 'coinbase';
                            $user_subscription->subscription_id = $payload->event->data->code;
                            $user_subscription->status = null;
                            $user_subscription->created_at = $now;
                            $user_subscription->recurring_at = null;
                            $user_subscription->cancelled_at = $metadata->interval == 'month' ? (clone $now)->addMonth() : (clone $now)->addYear();
                            $user_subscription->save();

                            // If a coupon was used
                            if (isset($metadata->coupon) && $metadata->coupon) {
                                $coupon = Coupon::find($metadata->coupon);

                                // If a coupon was found
                                if ($coupon) {
                                    // Increase the coupon usage
                                    $coupon->increment('quantity_redeemed', 1);
                                }
                            }

                            $payment = BillingHistory::create([
                                'user_id' => $user->id,
                                'plan_id' => $metadata->plan,
                                'payment_id' => $payload->event->data->code,
                                'processor' => 'coinbase',
                                'amount' => $metadata->amount,
                                'currency' => $metadata->currency,
                                'interval' => $metadata->interval,
                                'status' => 'completed',
                                'coupon' => $metadata->coupon ?? null,
                                'tax_rates' => $metadata->tax_rates ?? null,
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
            //Log::info('Coinbase signature validation failed.');
            return response()->json([
                'status' => 400,
                'message' => __('Coinbase signature validation failed.')
            ], 400);
        }

        return response()->json([
            'status' => 200
        ], 200);
    }
}