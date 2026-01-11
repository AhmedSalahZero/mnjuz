<?php

namespace Modules\Razorpay\Providers;

use Illuminate\Support\ServiceProvider;


class RazorpayServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->register(RouteServiceProvider::class);
    }
}   