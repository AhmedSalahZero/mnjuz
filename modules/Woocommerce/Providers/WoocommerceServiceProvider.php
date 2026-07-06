<?php

namespace Modules\Woocommerce\Providers;

use Illuminate\Support\ServiceProvider;


class WoocommerceServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->register(RouteServiceProvider::class);
    }
}