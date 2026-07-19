<?php

namespace Database\Seeders;

use App\Models\Language;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Carbon\Carbon;

class LanguageTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        // Define the data to be seeded
        $languages = [
            [
                'name' => 'English',
                'code' => 'en',
                'status' => 'active',
                'deleted_at' => NULL,
                'deleted_by' => NULL,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            [
                'name' => 'French',
                'code' => 'fr',
                'status' => 'active',
                'deleted_at' => NULL,
                'deleted_by' => NULL,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            [
                'name' => 'Spanish',
                'code' => 'es',
                'status' => 'active',
                'deleted_at' => NULL,
                'deleted_by' => NULL,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
        ];

        foreach ($languages as $language) {
            // Check for existing language by code
            Language::firstOrCreate(
                ['code' => $language['code']],
                $language
            );
        }
    }
}
