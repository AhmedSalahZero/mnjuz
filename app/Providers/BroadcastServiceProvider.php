<?php

namespace App\Providers;

use App\Broadcasting\ResilientPusherBroadcaster;
use Illuminate\Broadcasting\BroadcastManager;
use App\Http\Controllers\BroadcastAuthController;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;
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

        // المسار نفسه الذي يسجّله Broadcast::routes، بمتحكّمٍ يوقّع بسرّ مزوّد
        // المنشأة صاحبة القناة لا مزوّد المنصّة (BroadcastAuthController).
        Route::match(['get', 'post'], 'broadcasting/auth', BroadcastAuthController::class)
            ->middleware(['web', 'auth']);

        require base_path('routes/channels.php');
    }
}
