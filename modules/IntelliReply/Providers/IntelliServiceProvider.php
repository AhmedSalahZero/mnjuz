<?php

namespace Modules\IntelliReply\Providers;

use Illuminate\Support\ServiceProvider;


class IntelliServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->register(RouteServiceProvider::class);
    }
}