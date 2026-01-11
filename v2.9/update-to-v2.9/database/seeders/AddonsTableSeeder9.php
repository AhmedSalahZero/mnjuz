<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Addon;

class AddonsTableSeeder9 extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $rows = [
            [
                'category' => 'payments',
                'name' => 'Pabbly Subscriptions',
                'logo' => 'pabbly.png',
                'description' => 'Pabbly Subscriptions automates recurring billing and payment processing.',
                'metadata' => NULL,
                'license' => 'regular',
                'version' => '1.0',
                'update_available' => 0,
                'status' => 0,
            ],
            [
                'category' => 'plugins',
                'name' => 'Woocommerce',
                'logo' => 'woocommerce.png',
                'description' => 'Keep your woocommerce customers informed and connected with order updates and live chat.',
                'metadata' => NULL,
                'license' => 'regular',
                'version' => '1.0',
                'update_available' => 0,
                'status' => 0,
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
