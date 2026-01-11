<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Models\Setting;
use Helper;

class RecaptchaServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application services.
     *
     * @return void
     */
    public function boot()
    {
        if (env('ENABLE_DATABASE_CONFIG', false)) {
            $recaptchaSecretKey = Setting::where('key', 'recaptcha_secret_key')->value('value');
            $recaptchaSiteKey = Setting::where('key', 'recaptcha_site_key')->value('value');

            // Retrieve reCAPTCHA keys from a configuration file, database, or any other source
            $siteKey = env('RECAPTCHA_SITE_KEY', '');
            $secretKey = env('RECAPTCHA_SECRET_KEY', '');

            // Set reCAPTCHA keys in the configuration
            config(['services.recaptcha.site_key' => $siteKey]);
            config(['services.recaptcha.secret_key' => $secretKey]);
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
