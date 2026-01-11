<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Addon;

class AddonsTableSeeder7 extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $rows = [
            [
                'name' => 'Razorpay',
                'metadata' => '{"input_fields": [{"element": "input", "type": "text", "name": "razorpay_key_id", "label": "Key ID", "class": "col-span-2"}, {"element": "input", "type": "text", "name": "razorpay_secret_key", "label": "Secret Key", "class": "col-span-2"},{"element": "input", "type": "text", "name": "razorpay_webhook_secret", "label": "Webhook secret", "class": "col-span-2"}]}',
            ],
            [
                'name' => 'Google Authenticator',
                'metadata' => NULL,
            ],
            [
                'name' => 'Google Recaptcha',
                'metadata' => '{"input_fields": [{"element": "input", "type": "password", "name": "recaptcha_site_key", "label": "Recaptcha site key", "class": "col-span-2"}, {"element": "input", "type": "password", "name": "recaptcha_secret_key", "label": "Recaptcha secret key", "class": "col-span-2"}]}',
            ],
            [
                'name' => 'AI Assistant',
                'metadata' => NULL,
            ],
            [
                'name' => 'Webhooks',
                'metadata' => NULL,
            ],
            [
                'name' => 'Flow builder',
                'metadata' => NULL,
            ],
            [
                'name' => 'Embedded Signup',
                'metadata' => '{"input_fields":[{"element":"input","type":"text","name":"whatsapp_client_id","label":"App ID","class":"col-span-1"},{"element":"input","type":"password","name":"whatsapp_client_secret","label":"App secret","class":"col-span-1"},{"element":"input","type":"text","name":"whatsapp_config_id","label":"Config ID","class":"col-span-2"},{"element":"input","type":"password","name":"whatsapp_access_token","label":"Access token","class":"col-span-2"}]}'
            ]
        ];

        foreach ($rows as $row) {
            $addon = Addon::where('name', $row['name'])->where('status', 1)->first();

            if ($addon) {
                // Check if a record with the same name exists
                $addon->update([
                    'metadata' => $row['metadata'],
                ]);
            }
        }
    }
}
