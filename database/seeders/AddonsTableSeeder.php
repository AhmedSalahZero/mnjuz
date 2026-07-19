<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Addon;

class AddonsTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $rows = [
            [
                'category' => 'chat',
                'name' => 'Embedded Signup',
                'logo' => 'whatsapp.png',
                'description' => 'An Embedded Signup add-on allows app users to register using their WhatsApp account.',
                'metadata' => NULL,
                'status' => 0,
            ],
            [
                'category' => 'recaptcha',
                'name' => 'Google Recaptcha',
                'logo' => 'google_recaptcha.png',
                'description' => 'Google reCAPTCHA enhances website security by preventing spam and abusive activities.',
                'metadata' => '{"input_fields": [{"element": "input", "type": "password", "name": "recaptcha_site_key", "label": "Recaptcha site key", "class": "col-span-2"}, {"element": "input", "type": "password", "name": "recaptcha_secret_key", "label": "Recaptcha secret key", "class": "col-span-2"}, {"element": "toggle", "type": "checkbox", "name": "recaptcha_active", "label": "Activate recaptcha", "class": "col-span-2"}]}',
                'status' => 1,
            ],
            [
                'category' => 'analytics',
                'name' => 'Google Analytics',
                'logo' => 'google_analytics.png',
                'description' => 'Google Analytics tracks website performance and provides valuable insights for optimization.',
                'metadata' => '{"input_fields": [{"element": "input", "type": "text", "name": "google_analytics_tracking_id", "label": "Google analytics tracking ID", "class": "col-span-2"}]}',
                'status' => 1,
            ],
            [
                'category' => 'maps',
                'name' => 'Google Maps',
                'logo' => 'google_maps.png',
                'description' => 'Google Maps provides interactive maps for whatsapp messages.',
                'metadata' => '{"input_fields": [{"element": "input", "type": "text", "name": "google_maps_api_key", "label": "Google maps API key", "class": "col-span-2"}]}',
                'status' => 1,
            ]
        ];

        foreach ($rows as $row) {
            // Check if a record with the same name exists
            Addon::firstOrCreate(
                ['name' => $row['name']],
                $row 
            );
        }
    }
}
