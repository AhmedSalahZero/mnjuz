<?php

namespace App\Http\Middleware;

use Closure;
use App\Helpers\SubscriptionHelper;
use App\Services\SubscriptionService;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Illuminate\Support\Facades\Auth;

class CheckSubscriptionStatus
{
    public function handle($request, Closure $next)
    {
        // Check if the user is logged in
        if (Auth::check()) {
            $user = Auth::user();

            // Check if the user role is 'user'
            if ($user->role === 'user') {
                $subscriptionService = new SubscriptionService();
                $organizationId = session()->get('current_organization');
                //$activate = $subscriptionService::activateSubscriptionIfInactiveAndExpiredWithCredits($organizationId);

                // Check subscription has been activated and it is inactive
                if (!$subscriptionService::isSubscriptionActive($organizationId)) {
                    return to_route('user.billing.index');
                }
            }
        }

        // Subscription is active or user role is not 'user', proceed to the next page
        return $next($request);
    }
}
