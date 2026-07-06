<?php

namespace Modules\FlowBuilder\Providers;

use Illuminate\Support\ServiceProvider;


class FlowBuilderServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->register(RouteServiceProvider::class);
        
        // Register config files
        $this->publishes([
            __DIR__ . '/../config/actions.php' => config_path('FlowBuilder/actions.php'),
        ], 'flowbuilder-config');
        
        // Merge config
        $this->mergeConfigFrom(__DIR__ . '/../config/actions.php', 'FlowBuilder.actions');
    }
}