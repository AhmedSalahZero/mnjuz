<?php

namespace App\Providers;

use App\Broadcasting\ResilientPusherBroadcaster;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\ServiceProvider;

class BroadcastServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Broadcast::extend('pusher', function ($app, array $config) {
            $manager = $app->make(BroadcastManager::class);

            return new ResilientPusherBroadcaster($manager->pusher($config));
        });

        Broadcast::routes(['middleware' => ['web', 'auth']]);

        require base_path('routes/channels.php');
    }
}
