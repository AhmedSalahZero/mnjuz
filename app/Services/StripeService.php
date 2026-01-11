<?php

namespace App\Services;

use Carbon\Carbon;
use Helper;
use App\Models\BillingPayment;
use App\Models\BillingTransaction;
use App\Models\Organization;
use App\Models\PaymentGateway;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\SubscriptionService;
use App\Traits\ConsumesExternalServices;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class StripeService
{
    protected $config;
    protected $subscriptionService;
    protected $stripe;

    public function __construct()
    {
        $this->subscriptionService = new SubscriptionService();

        $stripeInfo = PaymentGateway::where('name', 'Stripe')->first();
        $this->config = json_decode($stripeInfo->metadata);
        $this->stripe = new \Stripe\StripeClient($this->config->secret_key);
    }

    public function handlePayment($amount, $planId = NULL)
    {
        try {
            if($planId == NULL){
                $stripeSession = $this->stripe->checkout->sessions->create([
                    'line_items' => [[
                        'price_data' => [
                            'currency' => strtolower(Setting::where('key', 'currency')->first()->value), 
                            'product_data' => [ 
                                'name' => 'Account Credits' 
                            ], 
                            'unit_amount' => $amount * 100
                        ],
                        'quantity' => 1,
                    ]],
                    'mode' => 'payment',
                    //'customer' => session()->get('current_organization'),
                    'customer_email' => auth()->user()->email,
                    'metadata' => [
                        'organization_id' => session()->get('current_organization'),
                        'user_id' => auth()->user()->id,
                        'amount' => $amount,
                        'plan_id' => $planId
                    ],
                    'success_url' => url('billing'),
                    'cancel_url' => url('billing'),
                ]);
            } else {
                $plan = SubscriptionPlan::where('id', $planId)->first();
                $metadata = json_decode($plan->metadata, true);
                $productId = $metadata['stripe']['product']['id'] ?? null;
                $priceId = $metadata['stripe']['price']['id'] ?? null;

                $stripeSession = $this->stripe->checkout->sessions->create([
                    'line_items' => [[
                        'price' => $priceId,
                        'quantity' => 1,
                    ]],
                    'mode' => 'subscription',
                    //'customer' => session()->get('current_organization'),
                    'customer_email' => auth()->user()->email,
                    'metadata' => [
                        'organization_id' => session()->get('current_organization'),
                        'user_id' => auth()->user()->id,
                        'amount' => $amount,
                        'plan_id' => $planId
                    ],
                    'success_url' => url('billing'),
                    'cancel_url' => url('billing'),
                ]);
            }

            return (object) array('success' => true, 'data' => $stripeSession->url);
        } catch (\Exception $e) {
            return (object) array('success' => false, 'error' => $e->getMessage());
        }
    }

    public function createProduct($productData){
        try {
            $plan = SubscriptionPlan::where('id', $productData->id)->first();
            $metadata = json_decode($plan->metadata, true);

            $product = $this->stripe->products->create([
                'name' => $plan->name,
            ]);

            $currency = Setting::where('key', 'currency')->value('value') ?? 'usd';
            $interval = $plan->period == 'monthly' ? 'month' : 'year';

            $price = $this->stripe->prices->create([
                'currency' => $currency,
                'unit_amount' => $plan->price * 100,
                'recurring' => ['interval' => $interval],
                'product' => $product->id,
            ]);
            
            $metadata['stripe'] = [
                'product' => $product,
                'price' => $price,
            ];
            $plan->metadata = json_encode($metadata);
            $plan->save();

            return (object) array('success' => true);
        } catch (\Exception $e) {
            return (object) array('success' => false, 'error' => $e->getMessage());
        }
    }

    public function updateProduct($productData){
        try {
            // Retrieve the subscription plan and its metadata
            $plan = SubscriptionPlan::where('id', $productData->id)->first();
            $metadata = json_decode($plan->metadata, true);

            // Get currency and interval settings
            $currency = Setting::where('key', 'currency')->value('value') ?? 'usd';
            $interval = $plan->period == 'monthly' ? 'month' : 'year';

            // Retrieve product and price IDs from metadata
            $productId = $metadata['stripe']['product']['id'] ?? null;
            $priceId = $metadata['stripe']['price']['id'] ?? null;

            if($productId){
                // Update the product in Stripe
                $product = $this->stripe->products->update(
                    $productId,
                    [
                        'name' => $plan->name ?? null,
                    ]
                );
            } else {
                // Create a new product if it does not exist
                $product = $this->stripe->products->create([
                    'name' => $plan->name,
                ]);

                $productId = $product->id;
            }

            // Check if we need to update the existing price or create a new one
            if ($priceId) {
                // Update the existing price for the product
                $price = $this->stripe->prices->update(
                    $priceId,
                    [
                        'active' => false, //Deactivate price
                    ]
                );
            }

            // Create a new price if the price ID does not exist
            $price = $this->stripe->prices->create([
                'currency' => $currency,
                'unit_amount' => $plan->price * 100,  // Stripe expects amount in the smallest currency unit
                'recurring' => isset($interval) ? ['interval' => $interval] : null,
                'product' => $productId,
            ]);
            
            // Update the metadata with the new product and price information
            $metadata['stripe'] = [
                'product' => $product,
                'price' => $price ?? null, // Use null if price is not created or updated
            ];
            $plan->metadata = json_encode($metadata);
            $plan->save();

            return (object) array('success' => true);
        } catch (\Exception $e) {
            return (object) array('success' => false, 'error' => $e->getMessage());
        }
    }

    public function updateProductPrices(){
        $plans = SubscriptionPlan::whereNull('deleted_at')->get();

        foreach($plans as $plan){
            $metadata = json_decode($plan->metadata, true);

            // Get currency and interval settings
            $currency = Setting::where('key', 'currency')->value('value') ?? 'usd';
            $interval = $plan->period == 'monthly' ? 'month' : 'year';

            // Retrieve product and price IDs from metadata
            $productId = $metadata['stripe']['product']['id'] ?? null;
            $priceId = $metadata['stripe']['price']['id'] ?? null;

            try {
                if($productId){
                    // Update the product in Stripe
                    $product = $this->stripe->products->update(
                        $productId,
                        [
                            'name' => $plan->name ?? null,
                        ]
                    );
                } else {
                    // Create a new product if it does not exist
                    $product = $this->stripe->products->create([
                        'name' => $plan->name,
                    ]);

                    $productId = $product->id;
                }

                // Check if we need to update the existing price or create a new one
                if ($priceId) {
                    // Update the existing price for the product
                    $price = $this->stripe->prices->update(
                        $priceId,
                        [
                            'active' => false, //Deactivate price
                        ]
                    );
                }

                $isTaxInclusive = Setting::where('key', 'is_tax_inclusive')->first()->value === '1';

                if($isTaxInclusive){
                    $totalAmount = $plan->price * 100;  // Stripe expects amount in the smallest currency unit
                } else {
                    $totalTax = $this->calculateTaxRates($plan->price);
                    $totalAmount = round(($plan->price + $totalTax), 2) * 100;  // Stripe expects amount in the smallest currency unit
                }

                // Create a new price if the price ID does not exist
                $price = $this->stripe->prices->create([
                    'currency' => $currency,
                    'unit_amount' => $totalAmount,
                    'recurring' => isset($interval) ? ['interval' => $interval] : null,
                    'product' => $productId,
                ]);
                
                // Update the metadata with the new product and price information
                $metadata['stripe'] = [
                    'product' => $product,
                    'price' => $price ?? null, // Use null if price is not created or updated
                ];

                $plan->metadata = json_encode($metadata);
                $plan->save();
            } catch (\Exception $e) {
                \Log::error('Error updating product prices: ' . $e->getMessage(), [
                    'plan_id' => $plan->id,
                ]);
            }
        }

        return (object) array('success' => true);
    }

    public function deleteProduct($productData){
        try {
            $plan = SubscriptionPlan::where('id', $productData->id)->first();
            $metadata = json_decode($plan->metadata, true);

            //Retrieve the product id
            $productId = $metadata['stripe']['product']['id'] ?? null;

            //Archive the product
            $product = $this->stripe->products->update(
                $productId,
                [
                    'active' => false,
                ]
            );

            return (object) array('success' => true);
        } catch (\Exception $e) {
            return (object) array('success' => false, 'error' => $e->getMessage());
        }
    }

    public function handleSubscription($amount, $planId = NULL)
    {
        try{
            /*$organization = Organization::where('id', session()->get('current_organization'))->first();
            if ($organization && $metadata = json_decode($organization->metadata, true)) {
                $stripeCustomerId = $metadata['stripe']['id'] ?? null;
            } else {
                $stripeCustomerId = $this->createCustomer(auth()->user()->id);
            }*/

            // Create the subscription.
            $stripeSession = $this->stripe->subscriptions->create([
                'line_items' => [[
                    'price_data' => [
                        'currency' => strtolower(Setting::where('key', 'currency')->first()->value), 
                        'product_data' => [ 
                            'name' => 'Subscription Payment' 
                        ], 
                        'unit_amount' => $amount * 100
                    ],
                    'quantity' => 1,
                ]],
                'mode' => 'subscription',
                'customer_email' => auth()->user()->email,
                'metadata' => [
                    'organization_id' => session()->get('current_organization'),
                    'user_id' => auth()->user()->id,
                    'amount' => $amount,
                    'plan_id' => $planId
                ],
                'success_url' => url('billing'),
                'cancel_url' => url('billing'),
            ]);

            return (object) array('success' => true, 'data' => $stripeSession->url);
        } catch (\Exception $e) {
            return (object) array('success' => false, 'error' => $e->getMessage());
        }
    }

    public function handleWebhook(Request $request)
    {
        // Attempt to validate the Webhook
        try {
            $stripeEvent = \Stripe\Webhook::constructEvent($request->getContent(), $request->server('HTTP_STRIPE_SIGNATURE'), $this->config->webhook_secret);
        } catch(\UnexpectedValueException $e) {
            // Invalid payload
            //Log::info($e->getMessage());

            return response()->json([
                'status' => 400,
                'message' => $e->getMessage()
            ], 400);
        } catch(\Stripe\Exception\SignatureVerificationException $e) {
            // Invalid signature
            //Log::info($e->getMessage());

            return response()->json([
                'status' => 400,
                'message' => $e->getMessage()
            ], 400);
        }
        
        /*if ($stripeEvent->type == 'checkout.session.completed') {
            // Provide enough time for the event to be handled
            sleep(3);
        }

        if ($stripeEvent->type == 'invoice.paid') {
            // Provide enough time for the event to be handled
            sleep(3);
        }*/

        if($stripeEvent->type == 'checkout.session.completed'){
            // Get the metadata
            $metadata = $stripeEvent->data->object->lines->data[0]->metadata ?? ($stripeEvent->data->object->metadata ?? null);

            if (isset($metadata->organization_id)) {
                $transaction = DB::transaction(function () use ($stripeEvent, $metadata) {
                    //Save stripe id to database
                    $organization = Organization::where('id', $metadata->organization_id)->first();
                    $orgMetadata = json_decode($organization->metadata, true);
                    $orgMetadata['stripe_id'] = $stripeEvent->data->object->customer;
                    $organization->metadata = json_encode($orgMetadata);
                    $organization->save();

                    if($stripeEvent->data->object->mode == 'subscription'){
                        //Save plan id to database
                        if($metadata->plan_id != NULL){
                            $subscription = Subscription::where('organization_id', $metadata->organization_id)->update([
                                'plan_id' => $metadata->plan_id
                            ]);
                        }
                    } else {
                        $payment = BillingPayment::create([
                            'organization_id' => $metadata->organization_id,
                            'processor' => 'stripe',
                            'details' => $stripeEvent->data->object->payment_intent,
                            'amount' => $metadata->amount
                        ]);

                        //Log::info($payment);
                        $transaction = BillingTransaction::create([
                            'organization_id' => $metadata->organization_id,
                            'entity_type' => 'payment',
                            'entity_id' => $payment->id,
                            'description' => 'Stripe Payment',
                            'amount' => $metadata->amount,
                            'created_by' => $metadata->user_id,
                        ]);

                        if($metadata->plan_id == null){
                            $this->subscriptionService->activateSubscriptionIfInactiveAndExpiredWithCredits($metadata->organization_id, $metadata->user_id);
                        } else {
                            $this->subscriptionService->updateSubscriptionPlan($metadata->organization_id, $metadata->plan_id, $metadata->user_id);
                        }

                        return $transaction;
                    }

                    return response()->json([
                        'status' => 200
                    ], 200);
                });
            }
        }

        if($stripeEvent->type == 'invoice.paid'){
            $transaction = DB::transaction(function () use ($stripeEvent) {
                $customerId = $stripeEvent->data->object->customer;

                // Query the Organization using the stripe_id (customer ID)
                $organization = Organization::whereJsonContains('metadata->stripe_id', $customerId)->first();

                if ($organization) {
                    $subscription = Subscription::where('organization_id', $organization->id)->first();
                    $organizationId = $organization->id;
                    $planId = $subscription->plan_id;
                    $amount = $stripeEvent->data->object->total/100;
                    $userId = 0;

                    $payment = BillingPayment::create([
                        'organization_id' => $organizationId,
                        'processor' => 'stripe',
                        'details' => $stripeEvent->data->object->id,
                        'amount' => $amount,
                    ]);

                    //Log::info($payment);
                    $transaction = BillingTransaction::create([
                        'organization_id' => $organizationId,
                        'entity_type' => 'payment',
                        'entity_id' => $payment->id,
                        'description' => 'Stripe Payment',
                        'amount' => $amount,
                        'created_by' => $userId,
                    ]);

                    if($planId == null){
                        $this->subscriptionService->activateSubscriptionIfInactiveAndExpiredWithCredits($organizationId, $userId);
                    } else {
                        $this->subscriptionService->updateSubscriptionPlan($organizationId, $planId, $userId);
                    }

                    return response()->json([
                        'status' => 200
                    ], 200);
                } 

                return response()->json([
                    'status' => 404
                ], 404);
            });
        }

        return response()->json([
            'status' => 200
        ], 200);
    }

    private static function calculateTaxRates($grossAmount)
    {
        $activeTaxRates = TaxRate::where('status', 'active')->whereNull('deleted_at')->get();
        $taxRatesDetails = [];
        $totalTaxAmount = 0;

        foreach($activeTaxRates as $taxRate){
            $taxAmount = $taxRate->percentage * $grossAmount / 100;
            $taxRatesDetails[] = array(
                'name' => $taxRate->name,
                'percentage' => $taxRate->percentage,
                'amount' => number_format($taxAmount, 2),
            );
            $totalTaxAmount += $taxAmount;
        }

        return $totalTaxAmount;
    }
}