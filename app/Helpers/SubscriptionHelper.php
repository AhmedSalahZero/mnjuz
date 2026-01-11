<?php 

namespace App\Helpers;

use App\Models\Setting;
use App\Models\Subscription;
use Carbon\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class SubscriptionHelper
{
    public static function status(string $organizationId)
    {
        $subscription = Subscription::where('organization_id', $organizationId)->first();

        if($subscription){
            return $subscription->status;
        } else {
            return 'trial';
        }
    }

    public static function info(string $organizationId)
    {
        return Subscription::where('organization_id', $organizationId)->first();
    }

    public static function isActive(string $organizationId)
    {
        $subscription = Subscription::where('organization_id', $organizationId)->first();

        if ($subscription && $subscription->valid_until >= now()) {
            return true;
        }
    
        return false;
    }
}

