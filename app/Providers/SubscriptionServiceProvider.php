<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class SubscriptionServiceProvider extends ServiceProvider
{

    public function boot()
    {
        $this->app->singleton(SubscriptionService::class, function () {
            return new SubscriptionService();
        });
    }
}
