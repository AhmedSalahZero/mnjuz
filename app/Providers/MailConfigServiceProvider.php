<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Models\Setting;
use Helper;

class MailConfigServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application services.
     *
     * @return void
     */
    public function boot()
    {
        if (env('ENABLE_DATABASE_CONFIG', false)) {
            $mailConfig = Setting::where('key', 'mail_config')->value('value');
            $mailConfig = json_decode($mailConfig, true);

            config([
                'mail.driver'     => $mailConfig['driver'] ?? null,
                'mail.host'       => $mailConfig['host'] ?? null,
                'mail.port'       => $mailConfig['port'] ?? null,
                'mail.username'   => $mailConfig['username'] ?? null,
                'mail.password'   => $mailConfig['password'] ?? null,
                'mail.from.address' => $mailConfig['from_address'] ?? null,
                'mail.from.name' => $mailConfig['from_name'] ?? null,
                'mail.reply_to.address' => $mailConfig['reply_to_address'] ?? null,
                'mail.reply_to.name' => $mailConfig['reply_to_name'] ?? null,
                // Add other mail configuration settings here
            ]);
        }
    }

    /**
     * Register the application services.
     *
     * @return void
     */
    public function register()
    {
        //
    }
}
