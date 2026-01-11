<?php

namespace Modules\Pabbly\Providers;

use Illuminate\Support\ServiceProvider;


class PabblyServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->register(RouteServiceProvider::class);
    }
}   