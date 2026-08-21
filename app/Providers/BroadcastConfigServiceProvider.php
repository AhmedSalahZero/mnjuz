<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Config;
use App\Models\Setting;
use App\Services\Broadcasting\BroadcastProvider;

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
        if (!env('ENABLE_DATABASE_CONFIG', false)) {
            return;
        }

        // كان هنا ضبطٌ لمفاتيح Pusher مفردةً من جدول الإعدادات. صار عبر
        // BroadcastProvider ليتشارك المصدر نفسه مع بقية المواضع، وليحمل
        // العنوان والمنفذ — وهما ما يجعل التبديل إلى Reverb ممكناً.
        $driver = trim((string) (Setting::where('key', 'broadcast_driver')->value('value') ?? ''));
        if ($driver !== '') {
            Config::set('broadcasting.default', $driver);
        }

        BroadcastProvider::apply();
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
