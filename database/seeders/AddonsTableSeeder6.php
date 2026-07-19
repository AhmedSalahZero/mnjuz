<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Addon;

class AddonsTableSeeder6 extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $rows = [
            [
                'name' => 'Embedded Signup',
                'is_plan_restricted' => 1,
            ],
            [
                'name' => 'AI Assistant',
                'is_plan_restricted' => 1,
            ],
            [
                'name' => 'Webhooks',
                'is_plan_restricted' => 1,
            ],
            [
                'name' => 'Flow builder',
                'is_plan_restricted' => 1,
            ],
        ];

        foreach ($rows as $row) {
            $addon = Addon::where('name', $row['name'])->first();

            if ($addon) {
                // Check if a record with the same name exists
                $addon->update([
                    'is_plan_restricted' => $row['is_plan_restricted'],
                ]);
            }
        }
    }
}
