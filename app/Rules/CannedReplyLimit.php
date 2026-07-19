<?php

namespace App\Rules;

use Illuminate\Contracts\Validation\Rule;
use App\Models\User;
use app\Services\SubscriptionService;

class CannedReplyLimit implements Rule
{
    private $organizationId;
    protected $ignoreId;

    public function __construct($organizationId, $ignoreId = null)
    {
        $this->organizationId = $organizationId;
        $this->ignoreId = $ignoreId;
    }
    
    public function passes($attribute, $value)
    {
        return !SubscriptionService::isSubscriptionFeatureLimitReached($this->organizationId, 'canned_replies_limit');
    }

    public function message()
    {
        return __('You have reached your limit. Please upgrade your account!');
    }
}
