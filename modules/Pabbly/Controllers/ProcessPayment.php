<?php

namespace Modules\Pabbly\Controllers;

use App\Http\Controllers\Controller as BaseController;
use App\Events\NewPaymentEvent;
use App\Models\BillingPayment;
use App\Models\BillingTransaction;
use App\Models\Organization;
use App\Models\Setting;
use App\Models\SubscriptionPlan;
use App\Services\SubscriptionService;
use Carbon\Carbon;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Pabbly\Services\PabblyService;

class ProcessPayment extends BaseController
{
    public function handlePayment($amount, $planId = NULL)
    {
        $plan = SubscriptionPlan::where('id', $planId)->first();

        $metadata = json_decode($plan->metadata, true);

        if(isset($metadata['pabbly'])){
            return (object) array('success' => true, 'data' => $metadata['pabbly']['checkout_page']);
        } else {
            return (object) array('success' => false, 'error' => __('Something went wrong, please try again!'));
        }
    }

    public function handleWebhook(Request $request)
    {
        $payload = json_decode($request->getContent(), true);

        if ($payload['event_type'] === 'subscription_activate') {
            //Check if subscription id transaction exists
            $paymentExists = BillingPayment::where('details', $payload['data']['id'])->first();

            if(!$paymentExists){
                $transaction = DB::transaction(function () use ($payload) {
                    $customerId = $payload['data']['customer']['id'];
                    $organizationId = Organization::whereNotNull('metadata')
                        ->get()
                        ->firstWhere(function ($organization) use ($customerId) {
                            $metadata = json_decode($organization->metadata, true);
                            return ($metadata['pabbly']['customer_id'] ?? null) === $customerId;
                        })?->id;

                    if (!$organizationId) {
                        Log::warning('Organization not found for Pabbly customer ID: ' . $customerId);
                        return;
                    }

                    $userId = Team::where('organization_id', $organizationId)
                        ->where('role', 'owner')
                        ->value('user_id');

                    $payment = BillingPayment::create([
                        'organization_id' => $organizationId,
                        'processor' => $payload['data']['gateway_type'],
                        'details' => $payload['data']['id'],
                        'amount' => $payload['data']['amount']
                    ]);

                    $transaction = BillingTransaction::create([
                        'organization_id' => $organizationId,
                        'entity_type' => 'payment',
                        'entity_id' => $payment->id,
                        'description' => $payload['data']['gateway_name'] . ' Payment',
                        'amount' => $payload['data']['amount'],
                        'created_by' => $userId,
                    ]);

                    (new SubscriptionService())->activateSubscriptionIfInactiveAndExpiredWithCredits($organizationId, $userId);
                });
            }
        }

        return response()->json(['status' => 'success'], 200);
    }
}