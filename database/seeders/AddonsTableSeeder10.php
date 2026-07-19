<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Addon;

class AddonsTableSeeder10 extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $dataToUpdate = [
            'is_plan_restricted' => 1
        ];

        Addon::where('name', 'Woocommerce')->update($dataToUpdate);
    }
}
