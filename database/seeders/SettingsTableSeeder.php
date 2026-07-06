<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Str;

class SettingsTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        // Define the settings data
        $settings = [
            ['key' => 'address', 'value' => null],
            ['key' => 'allow_facebook_login', 'value' => '0'],
            ['key' => 'allow_google_login', 'value' => '0'],
            ['key' => 'aws_access_key', 'value' => null],
            ['key' => 'aws_bucket', 'value' => null],
            ['key' => 'aws_default_region', 'value' => null],
            ['key' => 'aws_secret_key', 'value' => null],
            ['key' => 'billing_address', 'value' => null],
            ['key' => 'billing_city', 'value' => null],
            ['key' => 'billing_country', 'value' => null],
            ['key' => 'billing_name', 'value' => null],
            ['key' => 'billing_phone_1', 'value' => null],
            ['key' => 'billing_phone_2', 'value' => null],
            ['key' => 'billing_postal_code', 'value' => null],
            ['key' => 'billing_state', 'value' => null],
            ['key' => 'billing_tax_id', 'value' => null],
            ['key' => 'broadcast_driver', 'value' => 'pusher'],
            ['key' => 'company_name', 'value' => null],
            ['key' => 'currency', 'value' => 'USD'],
            ['key' => 'date_format', 'value' => 'd-M-y'],
            ['key' => 'default_image_api', 'value' => null],
            ['key' => 'email', 'value' => null],
            ['key' => 'facebook_login', 'value' => null],
            ['key' => 'favicon', 'value' => null],
            ['key' => 'google_analytics_status', 'value' => '0'],
            ['key' => 'google_analytics_tracking_id', 'value' => null],
            ['key' => 'google_login', 'value' => null],
            ['key' => 'google_maps_api_key', 'value' => null],
            ['key' => 'invoice_prefix', 'value' => null],
            ['key' => 'is_tax_inclusive', 'value' => '1'],
            ['key' => 'logo', 'value' => null],
            ['key' => 'mail_config', 'value' => null],
            ['key' => 'phone', 'value' => null],
            ['key' => 'pusher_app_cluster', 'value' => null],
            ['key' => 'pusher_app_id', 'value' => null],
            ['key' => 'pusher_app_key', 'value' => null],
            ['key' => 'pusher_app_secret', 'value' => null],
            ['key' => 'recaptcha_active', 'value' => '0'],
            ['key' => 'recaptcha_secret_key', 'value' => null],
            ['key' => 'recaptcha_site_key', 'value' => null],
            ['key' => 'smtp_email_active', 'value' => '0'],
            ['key' => 'socials', 'value' => null],
            ['key' => 'storage_system', 'value' => 'local'],
            ['key' => 'time_format', 'value' => 'H:i'],
            ['key' => 'timezone', 'value' => 'UTC'],
            ['key' => 'title', 'value' => null],
            ['key' => 'trial_period', 'value' => '20'],
            ['key' => 'verify_email', 'value' => '0'],
            ['key' => 'app_environment', 'value' => 'local'],
            ['key' => 'available_version', 'value' => null],
            ['key' => 'is_update_available', 'value' => 0],
            ['key' => 'last_update_check', 'value' => now()],
            ['key' => 'release_date', 'value' => null],
            ['key' => 'version', 'value' => null],
            ['key' => 'whatsapp_callback_token', 'value' => now()->format('YmdHis') . Str::random(4)]
        ];

        foreach ($settings as $setting) {
            Setting::firstOrCreate(
                ['key' => $setting['key']], // Search by key
                ['value' => $setting['value']]
            );
        }
    }
}