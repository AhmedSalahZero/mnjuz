<?php

namespace App\Services;

use App\Helpers\Email;
use App\Http\Resources\SubscriptionPlanResource;
use App\Models\AutoReply;
use App\Models\BillingCredit;
use App\Models\BillingInvoice;
use App\Models\BillingTaxRate;
use App\Models\BillingTransaction;
use App\Models\Campaign;
use App\Models\Chat;
use App\Models\Contact;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\TaxRate;
use App\Models\Team;
use App\Models\User;
use App\Resolvers\PaymentPlatformResolver;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use DB;

class SubscriptionService
{
    public static function isSubscriptionActive(string $organizationId)
    {
        $subscription = Subscription::where('organization_id', $organizationId)->first();

        /*if($subscription->status != 'trial'){
            $billingDetails = self::calculateSubscriptionBillingDetails($organizationId, $subscription->plan_id);

            if($billingDetails['accountBalance'] < 0){
                return false;
            }
        }*/

        if ($subscription && $subscription->valid_until >= now()) {
            return true;
        }
    
        return false;
    }

    public static function store($request, $organizationId, $planId, $userId)
    {
        $billingDetails = self::calculateSubscriptionBillingDetails($organizationId, $planId);

        $response = false;

        if($billingDetails['amountDue'] == 0){
            self::createBillingInvoice($billingDetails, $organizationId, $planId, $userId);
        } else {
            $paymentPlatform = (new PaymentPlatformResolver())->resolveService($request->method);
            session()->put('paymentPlatform', $request->method);

            $amountDue = str_replace(',', '', $billingDetails['amountDue']);
            $amountDue = (float)$amountDue;
            $response = $paymentPlatform->handlePayment($amountDue, $request->plan);

            return $response;
        }
    }

    public static function activateSubscriptionIfInactiveAndExpiredWithCredits($organizationId, $userId = 0)
    {
        $subscription = Subscription::where('organization_id', $organizationId)->first();

        if($subscription->valid_until < now()){
            if($subscription->plan_id){
                $planId = $subscription->plan_id;

                $billingDetails = self::calculateSubscriptionBillingDetails($organizationId, $planId);

                if($billingDetails['amountDue'] == 0){
                    self::createBillingInvoice($billingDetails, $organizationId, $planId, $userId);

                    $team = Team::where('organization_id', $organizationId)->where('role', 'owner')->first();
                    $user = User::where('id', $team->user_id)->first();
                    $plan = SubscriptionPlan::where('id', $planId)->first();

                    //Send subscription email
                    Email::sendSubscriptionEmail('Subscription Renewal', $user, $plan);
                }
            } 
        }

        return false;
    }

    public static function updateSubscriptionPlan($organizationId, $planId, $userId)
    {
        $plan = SubscriptionPlan::where('id', $planId)->first();

        if($plan){
            $billingDetails = self::calculateSubscriptionBillingDetails($organizationId, $planId);

            if($billingDetails['amountDue'] == 0){
                self::createBillingInvoice($billingDetails, $organizationId, $planId, $userId);

                $team = Team::where('organization_id', $organizationId)->where('role', 'owner')->first();
                $user = User::where('id', $team->user_id)->first();
                $plan = SubscriptionPlan::where('id', $planId)->first();

                //Send subscription email
                Email::sendSubscriptionEmail('Subscription Plan Purchase', $user, $plan);
            }
        }
    }

    public static function createBillingInvoice($billingDetails, $organizationId, $planId, $userId)
    {
        return DB::transaction(function () use ($billingDetails, $organizationId, $planId, $userId) {
            $netAmount = str_replace(',', '', $billingDetails['netAmount']);
            $netAmount = (float)$netAmount;
            $totalTaxAmount = str_replace(',', '', $billingDetails['totalTaxAmount']);
            $totalTaxAmount = (float)$totalTaxAmount;

            $invoice = BillingInvoice::create([
                'organization_id' => $organizationId,
                'plan_id' => $planId,
                'subtotal' => $netAmount,
                'tax' => $totalTaxAmount,
                'tax_type' => $billingDetails['isTaxInclusive'] === true ? 'inclusive' : 'exclusive',
                'total' => $netAmount,
            ]);

            foreach($billingDetails['taxRates'] as $taxRate){
                $taxRateAmount = str_replace(',', '', $taxRate['amount']);
                $taxrate = BillingTaxRate::create([
                    'invoice_id' => $invoice->id,
                    'rate' => $taxRateAmount,
                    'amount' => $taxRate['percentage'],
                ]);
            }

            $invoiceBillingTransaction = BillingTransaction::create([
                'organization_id' => $organizationId,
                'entity_type' => 'invoice',
                'entity_id' => $invoice->id,
                'description' => 'Invoice',
                'amount' => -$netAmount,
                'created_by' => $userId,
            ]);

            if(abs($billingDetails['credit']['new']) > 0){
                BillingCredit::create([
                    'organization_id' => $organizationId,
                    'description' => 'Credit memo',
                    'amount' => abs($billingDetails['credit']['new'])
                ]);

                $creditBillingTransaction = BillingTransaction::create([
                    'organization_id' => $organizationId,
                    'entity_type' => 'credit',
                    'entity_id' => $invoice->id,
                    'description' => 'Credit memo',
                    'amount' => $billingDetails['credit']['new'],
                    'created_by' => $userId,
                ]);
            }

            //Update subscription
            $plan = SubscriptionPlan::where('id', $planId)->first();
            
            Subscription::where('organization_id', $organizationId)->update([
                'plan_id' => $planId,
                'start_date' => now(),
                'valid_until' => date('Y-m-d H:i:s', strtotime('+1 ' . ($plan->period === 'monthly' ? 'month' : 'year'))),
                'status' => 'active',
            ]);

            return $invoice;
        });
    }

    public static function calculateSubscriptionBillingDetails($organizationId, $selectedPlanId)
    {
        $currentSubscription = Subscription::where('organization_id', $organizationId)->first();
        $subscriptionStatus = $currentSubscription->status;

        $selectedSubscriptionPlan = SubscriptionPlan::where('id', $selectedPlanId)->first();
        $isTaxInclusive = Setting::where('key', 'is_tax_inclusive')->first()->value === '1';

        $totalTaxPercentage = self::calculateTotalTaxPercentage();

        if($selectedSubscriptionPlan){
            $basePrice = ($subscriptionStatus == 'trial') ? $selectedSubscriptionPlan->price : $selectedSubscriptionPlan->price;
        } else {
            $basePrice = 0;
        }

        $grossAmount = $isTaxInclusive ? $basePrice - ($basePrice * $totalTaxPercentage / (100 + $totalTaxPercentage)) : $basePrice;

        $proratedCreditAmount = 0;

        if ($subscriptionStatus != 'trial') {
            // Calculate the unused amount for the current invoiced period as a credit to the user's account
            $lastInvoice = BillingInvoice::where('organization_id', $organizationId)->orderBy('id', 'desc')->first();
            $lastInvoiceTotal = $lastInvoice ? $lastInvoice->total : 0;
            $proratedAmount = self::calculateProratedAmount($organizationId, $lastInvoiceTotal);

            //Calculate unutilized amount for current invoiced period
            $proratedCreditAmount = $proratedAmount;
        }

        //Get user's account credits and debits
        $accountBalance = BillingTransaction::where('organization_id', $organizationId)->sum('amount');
        $availableCredits = max(0, $accountBalance);
        $availableDebits = min(0, $accountBalance);

        // Calculate tax rates
        $taxCalculationResult = self::calculateTaxRates($grossAmount);

        // Calculate net amount after considering taxes
        $netAmount = $grossAmount + $taxCalculationResult['totalTaxAmount'];

        // Calculate amount due considering credits, debits, and taxes
        $amountDue = $grossAmount + $taxCalculationResult['totalTaxAmount'] - $proratedCreditAmount - $accountBalance;

        // Ensure that amount due is not negative
        $amountDue = max(0, $amountDue);

        //Apply coupon is amount due > 0
        $coupon = [];
        if($amountDue > 0){
            $couponCode = session('applied_coupon');
            $couponData = \App\Models\Coupon::where('code', $couponCode)
                ->where('status', 'active')
                ->whereNull('deleted_at')
                ->first();

            if ($couponData) {
                if ($couponData->quantity_redeemed < $couponData->quantity) {
                    $discount = ($amountDue * $couponData->percentage) / 100;
                    $discount = min($discount, $amountDue);

                    $coupon = [
                        'code' => $couponData->code,
                        'type' => 'percentage',
                        'amount' => $couponData->percentage,
                        'discount' => number_format($discount, 2)
                    ];

                    $amountDue = max(0, $amountDue - $discount);
                }
            }
        }

        $response = [
            'isTaxInclusive' => $isTaxInclusive,
            'basePrice' => number_format($basePrice, 2),
            'grossAmount' => number_format($grossAmount, 2),
            'taxRates' => $taxCalculationResult['taxRatesDetails'],
            'totalTaxAmount' => $taxCalculationResult['totalTaxAmount'],
            'netAmount' => number_format($netAmount, 2),
            'accountBalance' => number_format($accountBalance, 2),
            'credit' => [
                'available' => number_format($availableCredits, 2),
                'new' => number_format($proratedCreditAmount, 2),
                'total' => number_format($availableCredits + $proratedCreditAmount, 2)
            ],
            'debit' => [
                'available' => number_format($availableDebits, 2),
                'total' => number_format($availableDebits, 2)
            ],
            'coupon' => $coupon,
            'amountDue' => number_format($amountDue, 2)
        ];

        return $response;
    }

    private static function calculateTotalTaxPercentage()
    {
        $activeTaxRates = TaxRate::where('status', 'active')->whereNull('deleted_at')->get();
        $totalTaxPercent = 0;

        foreach($activeTaxRates as $taxRate){
            $totalTaxPercent += $taxRate->percentage;
        }

        return $totalTaxPercent;
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

        $response['taxRatesDetails'] = $taxRatesDetails;
        $response['totalTaxAmount'] = $totalTaxAmount;

        return $response;
    }

    private static function calculateProratedAmount($organizationId, $amount)
    {
        // Calculate the prorated amount based on the remaining days
        $periodInDays = self::subscriptionPeriodInDays($organizationId);

        if($periodInDays > 0){
            $amountPerDay = $amount / $periodInDays;
            $proratedAmount = $amountPerDay * self::subscriptionPeriodRemainingDays($organizationId);

            return $proratedAmount;
        }

        return 0;
    }

    private static function subscriptionPeriodInDays($organizationId)
    {
        $subscription = Subscription::where('organization_id', $organizationId)->first();
        $subscriptionStartDate = Carbon::parse($subscription->start_date);
        $subscriptionEndDate = Carbon::parse($subscription->valid_until);

        return $subscriptionStartDate->diffInDays($subscriptionEndDate);
    }

    private static function subscriptionPeriodRemainingDays($organizationId)
    {
        $subscription = Subscription::where('organization_id', $organizationId)->first();

        if ($subscription) {
            $subscriptionEndDate = Carbon::parse($subscription->valid_until)->endOfDay();
            
            if ($subscriptionEndDate->isPast()) {
                return 0;
            }

            return now()->endOfDay()->diffInDays($subscriptionEndDate);
        }
    
        return 0;
    }

    public static function isSubscriptionFeatureLimitReached($organizationId, $feature)
    {
        $subscription = Subscription::where('organization_id', $organizationId)->first();

        if (!$subscription) {
            return true;
        }

        if($subscription->valid_until < now()){
            return true;
        }

        $subscriptionPlan = SubscriptionPlan::find($subscription->plan_id);

        if ($subscriptionPlan) {
            $subscriptionPlanLimits = json_decode($subscriptionPlan->metadata, true);

            if (!array_key_exists($feature, $subscriptionPlanLimits)) {
                return false;
            }

            $featureLimit = $subscriptionPlanLimits[$feature];
        }

        if ($feature == 'canned_replies_limit') {
            $count = AutoReply::where('organization_id', $organizationId)->whereNull('deleted_at')->count();

            if($subscription->status === 'trial' && $subscription->valid_until > now()){
                $limit = optional(Setting::where('key', 'trial_limits')->first())->value;
                $usageLimit = $limit ? json_decode($limit, true)['automated_replies'] ?? '-1' : '-1';

                return $usageLimit == -1 ? false : $count >= $usageLimit;
            }

            if ($subscriptionPlan) {
                return $featureLimit == -1 ? false : $count >= $featureLimit;
            }
        }

        if ($feature == 'contacts_limit') {
            $count = Contact::where('organization_id', $organizationId)->whereNull('deleted_at')->count();

            if($subscription->status === 'trial' && $subscription->valid_until > now()){
                $limit = optional(Setting::where('key', 'trial_limits')->first())->value;
                $usageLimit = $limit ? json_decode($limit, true)['contacts'] ?? '-1' : '-1';

                return $usageLimit == -1 ? false : $count >= $usageLimit;
            }

            if ($subscriptionPlan) {
                return $featureLimit == -1 ? false : $count >= $featureLimit;
            }
        }

        if ($feature == 'campaign_limit') {
            $count = Campaign::where('organization_id', $organizationId)->count();

            if($subscription->status === 'trial' && $subscription->valid_until > now()){
                $limit = optional(Setting::where('key', 'trial_limits')->first())->value;
                $usageLimit = $limit ? json_decode($limit, true)['campaigns'] ?? '-1' : '-1';

                return $usageLimit == -1 ? false : $count >= $usageLimit;
            }

            if ($subscriptionPlan) {
                return $featureLimit == -1 ? false : $count >= $featureLimit;
            }
        }

        if ($feature == 'message_limit') {
            $count = Chat::where('organization_id', $organizationId)->whereNull('deleted_at')->count();

            if($subscription->status === 'trial' && $subscription->valid_until > now()){
                $limit = optional(Setting::where('key', 'trial_limits')->first())->value;
                $usageLimit = $limit ? json_decode($limit, true)['messages'] ?? '-1' : '-1';

                return $usageLimit == -1 ? false : $count >= $usageLimit;
            }

            if ($subscriptionPlan) {
                return $featureLimit == -1 ? false : $count >= $featureLimit;
            }
        }

        if ($feature == 'team_limit') {
            $count = Team::where('organization_id', $organizationId)->count();

            if($subscription->status === 'trial' && $subscription->valid_until > now()){
                $limit = optional(Setting::where('key', 'trial_limits')->first())->value;
                $usageLimit = $limit ? json_decode($limit, true)['users'] ?? '-1' : '-1';

                return $usageLimit == -1 ? false : $count >= $usageLimit;
            }

            if ($subscriptionPlan) {
                return $featureLimit == -1 ? false : $count >= $featureLimit;
            }
        }

        return false;
    }

    public static function isSubscriptionLimitReachedForInboundMessages($organizationId)
    {
        $subscription = Subscription::with('plan')->where('organization_id', $organizationId)->first();

        // If no subscription is found, assume the limit is reached
        if (!$subscription) {
            return true;
        }

        // If no subscription is found, assume the limit is reached
        if(isset($subscription->plan->metadata)){
            $subscriptionMetadata = json_decode($subscription->plan->metadata, true);
            
            // Check if receiving messages after expiration is allowed
            if(isset($subscriptionMetadata['receive_messages_after_expiration']) && $subscriptionMetadata['receive_messages_after_expiration']){
                return false;
            }
        }

        // Check if the subscription has expired
        if($subscription->valid_until < now()){
            return true;
        }

        return false;
    }
}