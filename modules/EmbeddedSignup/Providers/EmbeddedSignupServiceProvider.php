<?php

namespace Modules\EmbeddedSignup\Providers;

use Illuminate\Support\ServiceProvider;


class EmbeddedSignupServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->register(RouteServiceProvider::class);
    }
}   