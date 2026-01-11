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

class RazorPayService
{
    public function __construct()
    {
        $razorpayInfo = DB::table('integrations')->where('name', 'RazorPay')->first();
        $this->config = unserialize($razorpayInfo->data);
        $this->razorpay = new \Razorpay\Api\Api($this->config['public_key'], $this->config['secret_key']);
    }

    public function createPlan($plan, $razorpayPlan, $razorpayAmount)
    {
        try {
            $request = $this->razorpay->plan->create([
                'period' => $interval == 'month' ? 'monthly' : 'yearly',
                'interval' => 1,
                'item' => [
                    'name' => $razorpayPlan,
                    'description' => $plan->description,
                    'amount' => $razorpayAmount,
                    'currency' => Helper::config('currency'),
                ],
            ]);
            return (object) array('success' => true, 'data' => $request);
        } catch (\Exception $e) {
            return (object) array('success' => false, 'error' => $e->getCode() . ' - ' . $e->getMessage());
        }
    }

    public function createSubscription($plan, $razorpayPlanRequest, $amount, $coupon, $taxRates, $interval)
    {
        try {
            $request = $this->razorpay->subscription->create([
                'plan_id' => $razorpayPlanRequest->id,
                'total_count' => $interval == 'month' ? 36 : 3,
                'notes' => [
                    'user' => auth()->user()->id,
                    'plan' => $plan->id,
                    'plan_amount' => $interval == 'year' ? $plan->yearly_price : $plan->monthly_price,
                    'amount' => $amount,
                    'currency' => Helper::config('currency'),
                    'interval' => $interval,
                    'coupon' => $coupon->id ?? null,
                    'tax_rates' => isset($taxRates) ?? $taxRates->pluck('id')->implode('_')
                ]
            ]);
            return (object) array('success' => true, 'data' => $request);
        } catch (\Exception $e) {
            return (object) array('success' => false, 'error' => $e->getMessage());
        }
    }

    public function handleSubscription(Request $request,$plan, $coupon, $taxRates, $amount, $interval)
    {
        $razorpayAmount = in_array($plan->currency, config('currencies.zero_decimals')) ? $amount : ($amount * 100);
        $razorpayPlan = $plan->id . '_' .$interval . '_' . $razorpayAmount . '_' . Helper::config('currency');
        $razorpayPlanQuery = $this->createPlan($plan, $razorpayPlan, $razorpayAmount);

        if($razorpayPlanQuery->success){
            $razorpayPlanQuery = $this->createSubscription($plan, $razorpayPlanQuery->data, $amount, $coupon, $taxRates, $interval);
            return redirect($razorpayPlanQuery->data->short_url);
        } else {
            dd($razorpayPlanQuery);
        }
    }

    public function cancelSubscription($plan_subscription_id)
    {
        // Attempt to cancel the current subscription
        try {
            $request = $this->razorpay->subscription->fetch($plan_subscription_id)->cancel();
            return (object) array('success' => true, 'data' => $request);
        } catch (\Exception $e) {
            return (object) array('success' => false, 'error' => $e->getCode() . ' - ' . $e->getMessage());
        }
    }

    public function handleWebhook(Request $request)
    {
        $payload = json_decode($request->getContent());

        $signature = $request->header('x-razorpay-signature');

        $computedSignature = hash_hmac('sha256', $request->getContent(), $this->config['webhook_secret']);

        // Validate the webhook signature
        if (hash_equals($computedSignature, $signature)) {
            // Get the metadata
            $metadata = $payload->payload->subscription->entity->notes ?? null;

            if (isset($metadata->user)) {
                $user = User::where('id', '=', $metadata->user)->first();
                $user_subscription = UserSubscription::where('user_id', $metadata->user)->first();

                // If a user was found
                if ($user) {
                    if ($payload->event == 'subscription.authenticated') {
                        // If the user previously had a subscription, attempt to cancel it
                        if ($user_subscription->subscription_id) {
                            $user_subscription->cancelSubscription();
                        }

                        $user_subscription->plan_id = $metadata->plan;
                        $user_subscription->plan_amount = $metadata->amount;
                        $user_subscription->plan_interval = $metadata->interval;
                        $user_subscription->payment_processor = 'razorpay';
                        $user_subscription->subscription_id = $payload->payload->subscription->entity->id;
                        $user_subscription->status = $payload->payload->subscription->entity->status;
                        $user_subscription->created_at = Carbon::now();
                        $user_subscription->recurring_at = $payload->payload->subscription->entity->charge_at ? Carbon::createFromTimestamp($payload->payload->subscription->entity->charge_at) : null;
                        $user_subscription->cancelled_at = null;
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
                    } elseif (stripos($payload->event, 'subscription.') !== false) {
                        // If the subscription exists
                        if ($user_subscription->payment_processor == 'razorpay' && $user_subscription->subscription_id == $payload->payload->subscription->entity->id) {
                            // Update the recurring date
                            if ($payload->payload->subscription->entity->charge_at) {
                                $user_subscription->recurring_at = Carbon::createFromTimestamp($payload->payload->subscription->entity->charge_at);
                            }

                            // Update the subscription status
                            if ($payload->payload->subscription->entity->status) {
                                $user_subscription->status = $payload->payload->subscription->entity->status;
                            }

                            // Update the subscription end date
                            if ($payload->payload->subscription->entity->ended_at) {
                                // Update the subscription end date and recurring date
                                if (!empty($user_subscription->recurring_at)) {
                                    $user_subscription->cancelled_at = $user_subscription->recurring_at;
                                    $user_subscription->recurring_at = null;
                                }
                            } else {
                                $user_subscription->cancelled_at = null;
                            }

                            $user_subscription->save();
                        }
                    }

                    if ($payload->event == 'subscription.charged') {
                        // If the payment does not exist
                        if (!BillingHistory::where([['processor', '=', 'razorpay'], ['payment_id', '=', $payload->payload->payment->entity->id]])->exists()) {
                            $payment = BillingHistory::create([
                                'user_id' => $user->id,
                                'plan_id' => $metadata->plan,
                                'payment_id' => $payload->payload->payment->entity->id,
                                'processor' => 'razorpay',
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
            Log::info('Razorpay signature validation failed.');

            return response()->json([
                'status' => 400
            ], 400);
        }

        return response()->json([
            'status' => 200
        ], 200);
    }
}