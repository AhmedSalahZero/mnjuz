<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Addon;

class AddonsTableSeeder5 extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $rows = [
            [
                'category' => 'two factor authentication',
                'name' => 'Google Authenticator',
                'logo' => 'google_authenticator.png',
                'description' => 'Google Authenticator enhances security with two-factor authentication.',
                'metadata' => '{"input_fields": [{"element":"toggle","type":"checkbox","name":"google_auth_active","label":"Enable\/disable Google Authenticator","class":"col-span-2"}]}',
                'license' => 'regular',
                'version' => '1.0',
                'update_available' => 0,
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
