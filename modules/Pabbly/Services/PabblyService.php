<?php

namespace Modules\Pabbly\Services;

use App\Models\BillingPayment;
use App\Models\BillingTransaction;
use App\Models\Organization;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Services\SubscriptionService;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

class PabblyService
{
    public function __construct()
    {
        $this->base_url = 'https://payments.pabbly.com/api/v1/';

        $settings = Setting::whereIn('key', [
            'pabbly_api_key', 
            'pabbly_secret_key',
            'currency'
        ])->pluck('value', 'key');

        $this->pabbly_api_key = $settings['pabbly_api_key'] ?? null;
        $this->pabbly_secret_key = $settings['pabbly_secret_key'] ?? null;
        $this->currency = $settings['currency'] ?? null;
    }

    public function index(){
        //Run migrations
        $migrateOutput = Artisan::call('module:migrate', [
            'module' => 'Webhook',  // Specify the module name as an argument
        ]);
    }
    
    public function getProductIdByName($name)
    {
        $response = Http::withBasicAuth($this->pabbly_api_key, $this->pabbly_secret_key)
            ->get($this->base_url . 'products');
    
        $result = $response->json();
    
        if (!$response->ok()) {
            return [
                'status' => 'error',
                'message' => $result['message'] ?? 'Unknown error from Pabbly.',
                'http_status' => $response->status(),
                'raw' => $result,
            ];
        }
    
        if ($result['status'] === 'success' && isset($result['data']) && is_array($result['data'])) {
            foreach ($result['data'] as $product) {
                if (isset($product['product_name']) && $product['product_name'] === $name) {
                    return [
                        'status' => 'success',
                        'product_id' => $product['id'],
                    ];
                }
            }
    
            return [
                'status' => 'error',
                'message' => 'Product not found with the given name.',
            ];
        }
    
        return [
            'status' => 'error',
            'message' => $result['message'] ?? 'Unexpected response from Pabbly.',
            'raw' => $result,
        ];
    }


    public function createProduct($name){
        $response = Http::withBasicAuth($this->pabbly_api_key, $this->pabbly_secret_key)->post($this->base_url . 'product/create', [
            "product_name" => $name,
        ]);

        return response()->json($response->json());
    }

    public function updateProduct($name, $productId){
        $response = Http::withBasicAuth($this->pabbly_api_key, $this->pabbly_secret_key)->put($this->base_url . 'product/update/' . $productId, [
            "product_name" => $name,
        ]);

        return response()->json($response->json());
    }

    public function createPlan($plan){
        $settings = Setting::whereIn('key', ['pabbly_product_id'])->pluck('value', 'key');

        if(isset($settings['pabbly_product_id'])){
            $response = Http::withBasicAuth($this->pabbly_api_key, $this->pabbly_secret_key)->post($this->base_url . 'plan/create', [
                "product_id" => $settings['pabbly_product_id'],
                "plan_name" => $plan->name,
                "plan_code" => $plan->uuid, //Unique plan code
                "price" => $plan->price, //Required only if plan type is flat_fee | per_unit | donation | variable
                "currency_code" => $this->currency, //Required
                "billing_cycle" => "lifetime",
                "billing_period" => $plan->period == 'monthly' ? "m" : "y",
                "billing_period_num" => "1",
                "plan_type" => "flat_fee",
                "plan_active" => $plan->status == 'active' ? "true" : "false",
                "redirect_url" => url('billing'),
            ]);

            $result = $response->json();

            if($result['status'] == 'success'){
                $plan = SubscriptionPlan::where('id', $plan->id)->first();
                $metadata = json_decode($plan->metadata, true);
                $metadata['pabbly'] = $result['data'];

                $plan->metadata = json_encode($metadata);
                $plan->save();

                return response()->json($response->json());
            }
        }
    }

    public function updatePlan($plan){
        $settings = Setting::whereIn('key', ['pabbly_product_id'])->pluck('value', 'key');

        if(!isset($settings['pabbly_product_id'])){
            return response()->json([
                'status' => 'error',
                'message' => 'Pabbly product ID not configured. Please configure Pabbly settings first.'
            ], 400);
        }

        try {
            $metadata = json_decode($plan->metadata, true);

            if(isset($metadata['pabbly'])){
                $response = Http::withBasicAuth($this->pabbly_api_key, $this->pabbly_secret_key)
                    ->put($this->base_url . 'plan/update/' . $metadata['pabbly']['id'], [
                        "product_id" => $settings['pabbly_product_id'],
                        "plan_name" => $plan->name,
                        "plan_code" => $plan->uuid, //Unique plan code
                        "price" => $plan->price, //Required only if plan type is flat_fee | per_unit | donation | variable
                        "currency_code" => $this->currency, //Required
                        "billing_cycle" => "lifetime",
                        "billing_period" => $plan->period == 'monthly' ? "m" : "y",
                        "billing_period_num" => "1",
                        "plan_type" => "flat_fee",
                        "plan_active" => $plan->status == 'active' ? "true" : "false",
                        "redirect_url" => url('billing'),
                    ]
                );
            } else {
                $response = Http::withBasicAuth($this->pabbly_api_key, $this->pabbly_secret_key)->post($this->base_url . 'plan/create', [
                    "product_id" => $settings['pabbly_product_id'],
                    "plan_name" => $plan->name,
                    "plan_code" => $plan->uuid, //Unique plan code
                    "price" => $plan->price, //Required only if plan type is flat_fee | per_unit | donation | variable
                    "currency_code" => $this->currency, //Required
                    "billing_cycle" => "lifetime",
                    "billing_period" => $plan->period == 'monthly' ? "m" : "y",
                    "billing_period_num" => "1",
                    "plan_type" => "flat_fee",
                    "plan_active" => $plan->status == 'active' ? "true" : "false",
                    "redirect_url" => url('billing'),
                ]);
            }

            $result = $response->json();

            if($result['status'] == 'success'){
                $plan = SubscriptionPlan::where('id', $plan->id)->first();
                $metadata = json_decode($plan->metadata, true);
                $metadata['pabbly'] = $result['data'];

                $plan->metadata = json_encode($metadata);
                $plan->save();

                return response()->json($response->json());
            } else {
                return response()->json([
                    'status' => 'error',
                    'message' => $result['message'] ?? 'Failed to update plan in Pabbly',
                    'raw' => $result
                ], 400);
            }
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Exception occurred while updating plan: ' . $e->getMessage()
            ], 500);
        }
    }

    public function subscribeToPlan($data){
        if(isset($data)){
            $response = Http::withBasicAuth($this->pabbly_api_key, $this->pabbly_secret_key)->get($this->base_url . 'hostedpage?hostedpage=' . $data);

            $result = $response->json();

            if($result['status'] == 'success'){
                $userId = auth()->user()->id;
                $organizationId = session()->get('current_organization');
                $organization = Organization::where('id', $organizationId)->first();

                if (!$organization) {
                    return response()->json(['message' => 'Organization not found'], 404);
                }

                $orgMetadata = json_decode($organization->metadata, true);
                $newSubscriptionId = $result['data']['subscription']['id'];

                if (
                    isset($orgMetadata['pabbly']['subscription_id']) && 
                    $orgMetadata['pabbly']['subscription_id'] === $newSubscriptionId
                ) {
                    return response()->json(['message' => 'Subscription already exists'], 409);
                }

                $orgMetadata['pabbly']['customer_id'] = $result['data']['subscription']['customer_id'];
                $orgMetadata['pabbly']['subscription_id'] = $result['data']['subscription']['id'];
                $orgMetadata['pabbly']['plan_id'] = $result['data']['subscription']['plan_id'];
                $organization->metadata = json_encode($orgMetadata);
                $organization->save();

                //Set subscription plan
                $subscriptionPlan = SubscriptionPlan::where('uuid', $result['data']['plan']['plan_code'])->first();
                $subscription = Subscription::where('organization_id', $organizationId)->update([
                    'plan_id' => $subscriptionPlan->id
                ]);

                //Add Payment Data
                $payment = BillingPayment::create([
                    'organization_id' => $organizationId,
                    'processor' => $result['data']['subscription']['gateway_type'],
                    'details' => $result['data']['subscription']['id'],
                    'amount' => $result['data']['subscription']['amount']
                ]);

                $transaction = BillingTransaction::create([
                    'organization_id' => $organizationId,
                    'entity_type' => 'payment',
                    'entity_id' => $payment->id,
                    'description' => $result['data']['subscription']['gateway_name'] . ' Payment',
                    'amount' => $result['data']['subscription']['amount'],
                    'created_by' => $userId,
                ]);

                $subscriptionService = new SubscriptionService();
                $subscriptionService->updateSubscriptionPlan($organizationId, $subscriptionPlan->id, $userId);

                return response()->json(['message' => __('Payment processed successfully!')], 200);
            }
        }

        return response()->json(['message' => __('Payment failed to be processed!')], 400);
    }

    public function deletePlan($plan){
        $settings = Setting::whereIn('key', ['pabbly_product_id'])->pluck('value', 'key');

        if(isset($settings['pabbly_product_id'])){
            $metadata = json_decode($plan->metadata, true);

            $response = Http::withBasicAuth($this->pabbly_api_key, $this->pabbly_secret_key)
                ->delete($this->base_url . 'plans/' . $metadata['pabbly']['id']);

            $result = $response->json();

            if($result['status'] == 'success'){
                $plan = SubscriptionPlan::where('id', $plan->id)->first();
                $metadata = json_decode($plan->metadata, true);

                // Remove 'pabbly' key from metadata
                unset($metadata['pabbly']);

                // Save the updated metadata
                $plan->metadata = json_encode($metadata);
                $plan->save();

                return response()->json($result);
            }
        }
    }

    /**
     * Update all subscription plans to support Pabbly
     */
    public function updateAllSubscriptionPlans()
    {   
        // Get all active subscription plans
        $subscriptionPlans = SubscriptionPlan::whereNull('deleted_at')->get();
        
        if ($subscriptionPlans->isEmpty()) {
            \Log::info('No active subscription plans found for Pabbly update');
            return [
                'total' => 0,
                'updated' => 0,
                'errors' => 0,
                'message' => 'No active subscription plans found'
            ];
        }
        
        $updatedCount = 0;
        $errorCount = 0;
        
        foreach ($subscriptionPlans as $plan) {
            try {
                $result = $this->updatePlan($plan);
                
                // Check if the result is a response object and has success status
                if ($result && method_exists($result, 'getData')) {
                    $data = $result->getData(true);
                    if (isset($data['status']) && $data['status'] === 'success') {
                        $updatedCount++;
                    } else {
                        $errorCount++;
                        \Log::warning('Pabbly plan update failed', [
                            'plan_id' => $plan->id,
                            'plan_name' => $plan->name,
                            'response' => $data
                        ]);
                    }
                } else {
                    // If no response returned, it might mean the plan was updated successfully
                    // or there was no product_id configured
                    $settings = Setting::where('key', 'pabbly_product_id')->value('value');
                    if ($settings) {
                        $updatedCount++;
                    } else {
                        $errorCount++;
                        \Log::warning('Pabbly plan update skipped - no product_id configured', [
                            'plan_id' => $plan->id,
                            'plan_name' => $plan->name
                        ]);
                    }
                }
            } catch (\Exception $e) {
                $errorCount++;
                // Log the error for debugging
                \Log::error('Failed to update subscription plan for Pabbly', [
                    'plan_id' => $plan->id,
                    'plan_name' => $plan->name,
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        return [
            'total' => $subscriptionPlans->count(),
            'updated' => $updatedCount,
            'errors' => $errorCount
        ];
    }
}