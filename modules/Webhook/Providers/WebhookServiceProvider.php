<?php

namespace Modules\Webhook\Providers;

use Illuminate\Support\ServiceProvider;


class WebhookServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->register(RouteServiceProvider::class);
    }
}