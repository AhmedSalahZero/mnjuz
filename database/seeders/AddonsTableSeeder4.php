<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Addon;

class AddonsTableSeeder4 extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $rows = [
            [
                'category' => 'utility',
                'name' => 'Webhooks',
                'logo' => 'webhook_icon.png',
                'description' => 'Webhooks enable real-time data transfer by sending automated notifications on specific events.',
                'metadata' => NULL,
                'status' => 0,
            ],
            [
                'category' => 'utility',
                'name' => 'Flow builder',
                'logo' => 'flow_icon.png',
                'description' => 'Flow Builder automation allows users to visually create and manage messaging workflows.',
                'metadata' => NULL,
                'status' => 0,
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
