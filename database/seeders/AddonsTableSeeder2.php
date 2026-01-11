<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Addon;

class AddonsTableSeeder2 extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $rows = [
            [
                'category' => 'payments',
                'name' => 'Razorpay',
                'logo' => 'razorpay.png',
                'description' => 'Razorpay is a payment platform that simplies payment processing.',
                'metadata' => '{"input_fields": [{"element": "input", "type": "text", "name": "razorpay_key_id", "label": "Key ID", "class": "col-span-2"}, {"element": "input", "type": "text", "name": "razorpay_secret_key", "label": "Secret Key", "class": "col-span-2"},{"element": "input", "type": "text", "name": "razorpay_webhook_secret", "label": "Webhook secret", "class": "col-span-2"},{"element":"toggle","type":"checkbox","name":"razorpay_active","label":"Enable\/disable Razorpay","class":"col-span-2"}]}',
                'status' => 1,
            ],
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
