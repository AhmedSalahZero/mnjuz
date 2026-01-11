<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Addon;

class AddonsTableSeeder8 extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $rows = [
            [
                'name' => 'Razorpay',
                'metadata' => '{"name": "Razorpay", "input_fields": [{"element": "input", "type": "text", "name": "razorpay_key_id", "label": "Key ID", "class": "col-span-2"}, {"element": "input", "type": "text", "name": "razorpay_secret_key", "label": "Secret Key", "class": "col-span-2"},{"element": "input", "type": "text", "name": "razorpay_webhook_secret", "label": "Webhook secret", "class": "col-span-2"}]}',
            ],
            [
                'name' => 'Google Authenticator',
                'metadata' => '{"name": "GoogleAuthenticator"}',
            ],
            [
                'name' => 'Google Recaptcha',
                'metadata' => '{"name": "GoogleRecaptcha", "input_fields": [{"element": "input", "type": "password", "name": "recaptcha_site_key", "label": "Recaptcha site key", "class": "col-span-2"}, {"element": "input", "type": "password", "name": "recaptcha_secret_key", "label": "Recaptcha secret key", "class": "col-span-2"}]}',
            ],
            [
                'name' => 'AI Assistant',
                'metadata' => '{"name": "IntelliReply"}',
            ],
            [
                'name' => 'Webhooks',
                'metadata' => '{"name": "Webhook"}',
            ],
            [
                'name' => 'Flow builder',
                'metadata' => '{"name": "FlowBuilder"}',
            ],
            [
                'name' => 'Embedded Signup',
                'metadata' => '{"name": "EmbeddedSignup"}'
            ]
        ];

        foreach ($rows as $row) {
            $addon = Addon::where('name', $row['name'])->first();

            if ($addon) {
                // Decode existing metadata or start fresh
                $metadata = $addon->metadata ? json_decode($addon->metadata, true) : [];

                // Extract folder_name from the provided metadata
                $newMetadata = json_decode($row['metadata'], true);
                if (!isset($metadata['name']) && isset($newMetadata['name'])) {
                    $metadata['name'] = $newMetadata['name'];
                }

                // Merge other metadata if it exists
                if (!empty($newMetadata)) {
                    $metadata = array_merge($metadata, $newMetadata);
                }

                // Update the addon metadata
                $addon->update([
                    'metadata' => json_encode($metadata, JSON_PRETTY_PRINT),
                ]);
            }
        }
    }
}