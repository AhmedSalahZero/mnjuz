<?php

namespace App\Helpers;
use App\Models\Addon;

use App\Models\Setting;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use Cache;
use Carbon\Carbon;
use DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Session;
use Money\Currencies\ISOCurrencies;
use Money\Currency;
use Money\Formatter\IntlMoneyFormatter;
use Money\Money;
use NumberFormatter;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;
use Symfony\Component\Mime\Part\HtmlPart;

class CustomHelper {
    public static function config($key){
        $config = Setting::where('key', $key)->first();

        if($config){
            return $config->value;
        } else {
            return NULL;
        }
    }

    public static function isModuleEnabled($module, $organizationId = NULL){
        $addon = Addon::where('name', $module)
            ->where('status', 1)
            ->where('is_active', 1)
            ->exists();

        $orgId = $organizationId != NULL ? $organizationId : session()->get('current_organization');

        $subscription = Subscription::with('plan')
            ->where('organization_id', $orgId)
            ->first();

        if($addon){
            if($subscription->status === 'trial' && $subscription->valid_until > now()){
                return true; //Allow user to test features during trial period
            }

            // Ensure a subscription and its plan exist
            if (!$subscription || !$subscription->plan) {
                return false; // Subscription or plan not found
            }

            $plan = SubscriptionPlan::where('id', $subscription->plan->id)->first();

            if (!$plan) {
                return false; // Plan not found
            }

            $metadata = json_decode($plan->metadata, true);

            if (isset($metadata['addons']) && is_array($metadata['addons'])) {
                // Return true if the module exists and is set to true, otherwise false
                return isset($metadata['addons'][$module]) && $metadata['addons'][$module] === true;
            }
        }

        return false; // Default to false if addons key or module is not found
    }
}
