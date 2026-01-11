<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Addon;

class AddonsTableSeeder3 extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $rows = [
            [
                'category' => 'ai',
                'name' => 'AI Assistant',
                'logo' => 'ai.png',
                'description' => 'The AI assistant delivers intelligent, AI-driven responses by utilizing user data for training.',
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
