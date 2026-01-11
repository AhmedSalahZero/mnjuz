<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Config;
use App\Models\Setting;

class BroadcastConfigServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot()
    {
        if (env('ENABLE_DATABASE_CONFIG', false)) {
            $broadcastSettings = $this->getPusherSettings();

            Config::set('broadcasting.default', $broadcastSettings['broadcast_driver']);
            Config::set('broadcasting.connections.pusher.key', $broadcastSettings['pusher_app_key']);
            Config::set('broadcasting.connections.pusher.secret', $broadcastSettings['pusher_app_secret']);
            Config::set('broadcasting.connections.pusher.app_id', $broadcastSettings['pusher_app_id']);
            Config::set('broadcasting.connections.pusher.options.cluster', $broadcastSettings['pusher_app_cluster']);
            // Set other pusher options as needed

            // You can set other configuration values here
        }

    }

    /**
     * Fetch Pusher settings from the database.
     *
     * @return array
     */
    private function getPusherSettings()
    {
        if (env('ENABLE_DATABASE_CONFIG', false)) {
            // Fetch Pusher settings from the database
            // Adjust this query based on your database schema
            $broadcastSettings = Setting::whereIn('key', [
                'broadcast_driver',
                'pusher_app_key',
                'pusher_app_secret',
                'pusher_app_id',
                'pusher_app_cluster',
                // Add other Pusher settings keys as needed
            ])->pluck('value', 'key')->toArray();

            return $broadcastSettings;
        }
    }
}
