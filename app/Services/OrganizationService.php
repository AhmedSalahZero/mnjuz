<?php

namespace App\Services;

use App\Http\Resources\OrganizationsResource;
use App\Http\Resources\BillingResource;
use App\Http\Resources\UserResource;
use App\Models\BillingCredit;
use App\Models\BillingDebit;
use App\Models\BillingInvoice;
use App\Models\BillingPayment;
use App\Models\BillingTransaction;
use App\Models\Organization;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Team;
use App\Models\Template;
use App\Models\User;
use DB;
use Str;
use Propaganistas\LaravelPhone\PhoneNumber;

class OrganizationService
{
    /**
     * Get all organizations based on the provided request filters.
     *
     * @param Request $request
     * @return mixed
     */
    public function get(object $request, $userId = null)
    {
        $organizations = (new Organization)->listAll($request->query('search'), $userId);

        return OrganizationsResource::collection($organizations);
    }

    /**
     * Retrieve an organization by its UUID.
     *
     * @param string $uuid
     * @return \App\Models\Organization
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function getByUuid($request, $uuid = null)
    {
        $result['plans'] = SubscriptionPlan::where('status', '!=', 'deleted')->get();

        if ($uuid === null) {
            $result['organization'] = null;
            $result['billing'] = null;
            $result['users'] = null;
    
            return $result;
        }

        $organization = Organization::with('subscription.plan')->where('uuid', $uuid)->first();
        $users = (new User)->listAll('user', $request->query('search'), $organization->id);
        $billing = (new BillingTransaction)->listAll($request->query('search'), $organization->id);
        
        $result['organization'] = $organization;
        $result['billing'] = BillingResource::collection($billing);
        $result['users'] = UserResource::collection($users);

        return $result;
    }

    /**
     * Store a new organization based on the provided request data.
     *
     * @param Request $request
     */
    public function store(Object $request)
    {
        return DB::transaction(function () use ($request) {
            if($request->input('create_user') == 1){
                //Create and attach user to organization
                $user = User::create([
                    'first_name' => $request->input('first_name'),
                    'last_name' => $request->input('last_name'),
                    'email' => $request->input('email'),
                    'role' => 'user',
                    'phone' => $request->input('phone') ? phone($request->input('phone'))->formatE164() : null,
                    'address' => json_encode([
                        'street' => $request->input('street'),
                        'city' => $request->input('city'),
                        'state' => $request->input('state'),
                        'zip' => $request->input('zip'),
                        'country' => $request->input('country'),
                    ]),
                    'password' => $request->input('password'),
                    'language' => app()->getLocale(),
                ]);
            } else {
                //Attach existng user to organization
                $user = User::where('email', $request->input('email'))->first();
            }

            $timestamp = now()->format('YmdHis');
            $randomString = Str::random(4);
            $userId = $user->id;

            $organization = Organization::create([
                'name' => $request->input('name'),
                'identifier' => $timestamp . $userId . $randomString,
                'address' => json_encode([
                    'street' => $request->street,
                    'city' => $request->city,
                    'state' => $request->state,
                    'zip' => $request->zip,
                    'country' => $request->country,
                ]),
                'created_by' => auth()->user()->id,
            ]);

            Team::create([
                'organization_id' => $organization->id,
                'user_id' => $user->id,
                'role' => 'owner',
                'status' => 'active',
                'created_by' => auth()->user()->id,
            ]);

            $plan = SubscriptionPlan::where('uuid', $request->plan)->first();
            $config = Setting::where('key', 'trial_period')->first();
            $has_trial = isset($config->value) && $config->value > 0 ? true : false;

            Subscription::create([
                'organization_id' => $organization->id,
                'status' => $has_trial ? 'trial' : 'active',
                'plan_id' => $plan ? $plan->id : NULL,
                'start_date' => now(),
                'valid_until' => $has_trial ? date('Y-m-d H:i:s', strtotime('+' . $config->value . ' days')) : now(),
            ]);

            return $organization;
        });
    }

    /**
     * Update organization.
     *
     * @param Request $request
     * @param string $uuid
     * @return \App\Models\Organization
     */
    public function update($request, $uuid)
    {
        $organization = Organization::where('uuid', $uuid)->firstOrFail();

        $organization->update([
            'name' => $request->input('name'),
            'address' => json_encode([
                'street' => $request->street,
                'city' => $request->city,
                'state' => $request->state,
                'zip' => $request->zip,
                'country' => $request->country,
            ]),
        ]);

        $subscription = Subscription::where('organization_id', $organization->id)->first();
        $plan = SubscriptionPlan::where('uuid', $request->plan)->first();

        if($subscription){
            $subscription->update([
                'plan_id' => $plan->id
            ]);
        } else {
            $config = Setting::where('key', 'trial_period')->first();
            $has_trial = isset($config->value) && $config->value > 0 ? true : false;
            
            Subscription::create([
                'organization_id' => $organization->id,
                'status' => $has_trial ? 'trial' : 'active',
                'plan_id' => $plan->id,
                'start_date' => now(),
                'valid_until' => $has_trial ? date('Y-m-d H:i:s', strtotime('+' . $config->value . ' days')) : now(),
            ]);
        }

        return $organization;
    }

    public function storeTransaction($request, $uuid){
        return DB::transaction(function () use ($request, $uuid) {
            $organization = Organization::where('uuid', $uuid)->firstOrFail();
    
            $modelClass = match ($request->type) {
                'credit' => BillingCredit::class,
                'debit' => BillingDebit::class,
                'payment' => BillingPayment::class,
            };

            $transactionData = [
                'organization_id' => $organization->id,
                'amount' => $request->amount,
            ];
            
            if (in_array($type, ['credit', 'debit'])) {
                $entryData['description'] = $request->description;
            }
            
            if ($type === 'payment') {
                $entryData['processor'] = $request->method;
            }
    
            $entry = $modelClass::create($entryData);
    
            $transaction = BillingTransaction::create([
                'organization_id' => $organization->id,
                'entity_type' => $request->type,
                'entity_id' => $entry->id,
                'description' => $request->type === 'payment' ? $request->method . ' Transaction' : $request->description,
                'amount' => $request->amount,
                'created_by' => auth()->user()->id
            ]);
    
            return $transaction;
        });
    }

    public function destroy($uuid){
        // Find the organization by its UUID
        $organization = Organization::where('uuid', $uuid)->first();

        if ($organization) {
            // Delete all teams associated with the organization
            Team::where('organization_id', $organization->id)->delete();
            
            // Delete the organization
            $organization->delete();

            // Return true to indicate successful deletion
            return true;
        }

        // Return false if the organization does not exist
        return false;
    }
}