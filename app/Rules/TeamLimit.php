<?php

namespace App\Rules;

use Illuminate\Contracts\Validation\Rule;
use App\Models\User;
use App\Services\SubscriptionService;

class TeamLimit implements Rule
{
    protected $ignoreId;

    /** المنشأة صراحةً: طلبات تطبيق الجوال بلا جلسة. */
    protected $organizationId;

    public function __construct($ignoreId = null, $organizationId = null)
    {
        $this->ignoreId = $ignoreId;
        $this->organizationId = $organizationId;
    }
    
    public function passes($attribute, $value)
    {
        $organizationId = $this->organizationId ?? session()->get('current_organization');

        return !SubscriptionService::isSubscriptionFeatureLimitReached($organizationId, 'team_limit');
    }

    public function message()
    {
        return __('You have reached your limit of team members. Please upgrade your account to add more!');
    }
}
