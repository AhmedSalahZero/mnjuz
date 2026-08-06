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
use DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

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
            if (empty($request->method)) {
                return (object) [
                    'success' => false,
                    'error' => __('Please select a payment method.'),
                ];
            }

            $resolver = new PaymentPlatformResolver();

            if (! $resolver->isSupported($request->method)) {
                Log::warning('Unsupported payment platform selected', [
                    'method' => $request->method,
                    'organization_id' => $organizationId,
                    'user_id' => $userId,
                ]);

                return (object) [
                    'success' => false,
                    'error' => __('The selected payment method is not available. Please choose another method or contact support.'),
                ];
            }

            try {
                $paymentPlatform = $resolver->resolveService($request->method);
            } catch (\Throwable $e) {
                Log::warning('Payment platform resolution failed', [
                    'method' => $request->method,
                    'organization_id' => $organizationId,
                    'user_id' => $userId,
                    'error' => $e->getMessage(),
                ]);

                return (object) [
                    'success' => false,
                    'error' => $e->getMessage(),
                ];
            }

            session()->put('paymentPlatform', $request->method);

            $amountDue = str_replace(',', '', $billingDetails['amountDue']);
            $amountDue = (float)$amountDue;
            $response = $paymentPlatform->handlePayment($amountDue, $request->plan);

            return $response;
        }
    }

    /**
     * تجديد الاشتراك من رصيد الحساب متى كفى الرصيد.
     *
     * @return \App\Models\BillingInvoice|false الفاتورة الصادرة، أو false إن لم يُجدَّد.
     */
    public static function activateSubscriptionIfInactiveAndExpiredWithCredits($organizationId, $userId = 0)
    {
        $subscription = Subscription::where('organization_id', $organizationId)->first();

        // تُستدعى الآن من أمر مجدوَل يمرّ على كل المنشآت، فصفّ ناقص عند واحدة
        // لا يصحّ أن يُسقط الدفعة كلّها.
        if (!$subscription || !$subscription->plan_id || $subscription->valid_until >= now()) {
            return false;
        }

        $planId = $subscription->plan_id;
        $billingDetails = self::calculateSubscriptionBillingDetails($organizationId, $planId);

        if ($billingDetails['amountDue'] != 0) {
            return false;
        }

        $invoice = self::createBillingInvoice($billingDetails, $organizationId, $planId, $userId);

        $team = Team::where('organization_id', $organizationId)
            ->whereIn('role', ['owner', 'manager'])
            ->orderByRaw("FIELD(role, 'owner', 'manager')")
            ->first();
        $user = $team ? User::where('id', $team->user_id)->first() : null;
        $plan = SubscriptionPlan::where('id', $planId)->first();

        // التجديد تمّ وحُفظ؛ فشل البريد لا يصحّ أن يُلغيه.
        if ($user && $plan) {
            try {
                Email::sendSubscriptionEmail('Subscription Renewal', $user, $plan);
            } catch (\Throwable $e) {
                Log::warning('Subscription renewal email failed', [
                    'organization_id' => $organizationId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $invoice;
    }

    /**
     * @return \App\Models\BillingInvoice|null الفاتورة الصادرة إن صدرت.
     */
    public static function updateSubscriptionPlan($organizationId, $planId, $userId)
    {
        $plan = SubscriptionPlan::where('id', $planId)->first();

        if (!$plan) {
            return null;
        }

        $billingDetails = self::calculateSubscriptionBillingDetails($organizationId, $planId);

        if ($billingDetails['amountDue'] != 0) {
            return null;
        }

        $invoice = self::createBillingInvoice($billingDetails, $organizationId, $planId, $userId);

        $team = Team::where('organization_id', $organizationId)
            ->whereIn('role', ['owner', 'manager'])
            ->orderByRaw("FIELD(role, 'owner', 'manager')")
            ->first();
        $user = $team ? User::where('id', $team->user_id)->first() : null;

        if ($user) {
            try {
                Email::sendSubscriptionEmail('Subscription Plan Purchase', $user, $plan);
            } catch (\Throwable $e) {
                Log::warning('Subscription purchase email failed', [
                    'organization_id' => $organizationId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $invoice;
    }

    public static function createBillingInvoice($billingDetails, $organizationId, $planId, $userId)
    {
        return DB::transaction(function () use ($billingDetails, $organizationId, $planId, $userId) {
            $netAmount = str_replace(',', '', $billingDetails['netAmount']);
            $netAmount = (float)$netAmount;
            $totalTaxAmount = str_replace(',', '', $billingDetails['totalTaxAmount']);
            $totalTaxAmount = (float)$totalTaxAmount;
            $setupFee = str_replace(',', '', (string) ($billingDetails['setupFee'] ?? 0));
            $setupFee = (float) $setupFee;

            // خصم الكوبون كان يُطبَّق على المبلغ المستحق فقط دون أن يُسجَّل على
            // الفاتورة، فتبقى بقيمتها كاملة رغم أن العميل دفع أقل — يختلّ بذلك
            // رصيد حسابه بفارق الخصم، وتصدر فاتورته الرسمية بأكثر مما سدّد.
            $discount = str_replace(',', '', (string) ($billingDetails['coupon']['discount'] ?? 0));
            $discount = min((float) $discount, $netAmount);
            $chargedAmount = round($netAmount - $discount, 2);

            $invoice = BillingInvoice::create([
                'organization_id' => $organizationId,
                'plan_id' => $planId,
                'subtotal' => $netAmount,
                'setup_fee' => $setupFee,
                'coupon_id' => $billingDetails['coupon']['id'] ?? null,
                'coupon_amount' => $discount,
                'tax' => $totalTaxAmount,
                'tax_type' => $billingDetails['isTaxInclusive'] === true ? 'inclusive' : 'exclusive',
                'total' => $chargedAmount,
            ]);

            foreach($billingDetails['taxRates'] as $taxRate){
                $taxRateAmount = str_replace(',', '', $taxRate['amount']);
                $taxrate = BillingTaxRate::create([
                    'invoice_id' => $invoice->id,
                    'rate' => $taxRateAmount,
                    'amount' => $taxRate['percentage'],
                ]);
            }

            // الحركة بالمبلغ المُحصَّل لا بقيمة الفاتورة قبل الخصم، وإلا بقي
            // رصيد العميل مديناً بفارق الكوبون فارتفع استحقاق التجديد التالي.
            $invoiceBillingTransaction = BillingTransaction::create([
                'organization_id' => $organizationId,
                'entity_type' => 'invoice',
                'entity_id' => $invoice->id,
                'description' => 'Invoice',
                'amount' => -$chargedAmount,
                'created_by' => $userId,
            ]);

            $creditNew = str_replace(',', '', (string) ($billingDetails['credit']['new'] ?? 0));
            $creditNew = (float) $creditNew;

            if (abs($creditNew) > 0) {
                BillingCredit::create([
                    'organization_id' => $organizationId,
                    'description' => 'Credit memo',
                    'amount' => abs($creditNew)
                ]);

                $creditBillingTransaction = BillingTransaction::create([
                    'organization_id' => $organizationId,
                    'entity_type' => 'credit',
                    'entity_id' => $invoice->id,
                    'description' => 'Credit memo',
                    'amount' => $creditNew,
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
            // Calculate the unused amount for the current invoiced period as a credit to the user's account.
            // Setup fees are one-time and are not consumed by days, so exclude them from proration.
            $lastInvoice = BillingInvoice::where('organization_id', $organizationId)->orderBy('id', 'desc')->first();
            $lastInvoiceTotal = $lastInvoice
                ? max(0, (float) $lastInvoice->total - (float) $lastInvoice->setup_fee)
                : 0;
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

        // One-time setup fee: charged only on the organization's first ever paid subscription.
        // The signup subscription row never creates a BillingInvoice, so its absence is a reliable signal.
        $isFirstSubscription = !BillingInvoice::where('organization_id', $organizationId)->exists();
        $planMetadata = $selectedSubscriptionPlan ? (json_decode($selectedSubscriptionPlan->metadata, true) ?: []) : [];
        $setupFee = $isFirstSubscription ? (float) ($planMetadata['setup_fee'] ?? 0) : 0.0;

        // Calculate net amount after considering taxes (setup fee is a flat, untaxed one-time charge)
        $netAmount = $grossAmount + $taxCalculationResult['totalTaxAmount'] + $setupFee;

        // Calculate amount due considering credits, debits, taxes and the one-time setup fee.
        // Setup fee is added before subtracting the account balance so that, after payment is
        // recorded as a credit, the recomputed amountDue nets to 0 and the invoice is created.
        $amountDue = $grossAmount + $taxCalculationResult['totalTaxAmount'] + $setupFee - $proratedCreditAmount - $accountBalance;

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
                        // المعرّف يُحفظ على الفاتورة، فبدونه لا يُعرف أي كوبون خُصم.
                        'id' => $couponData->id,
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
            'setupFee' => number_format($setupFee, 2),
            'isFirstSubscription' => $isFirstSubscription,
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
		// return true;
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

    /**
     * Check if a plan feature flag is enabled for the organization (e.g. contact_categories_enabled).
     */
    public static function isSubscriptionFeatureEnabled(string $organizationId, string $featureKey): bool
    {
        $subscription = Subscription::with('plan')->where('organization_id', $organizationId)->first();
        if (!$subscription || !$subscription->plan) {
            return false;
        }
        if ($subscription->valid_until < now()) {
            return false;
        }
        $metadata = json_decode($subscription->plan->metadata, true);
        if (!is_array($metadata) || !array_key_exists($featureKey, $metadata)) {
            return false;
        }
        $value = $metadata[$featureKey];
        return $value === true || $value === 1 || $value === '1' || $value === 'enabled';
    }
}
