<?php

namespace Database\Seeders;

use App\Models\TicketCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TicketCategoriesTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        // Define the ticket categories data
        $categories = [
            ['name' => 'Signup/login issues'],
            ['name' => 'Campaigns issues'],
            ['name' => 'Whatsapp issue'],
            ['name' => 'Template Issues'],
            ['name' => 'Chatbot Issues'],
            ['name' => 'Other'],
        ];

        foreach ($categories as $category) {
            // Insert or update the category
            TicketCategory::firstOrCreate(
                ['name' => $category['name']], // Search by category name
                $category // Insert or update the category data
            );
        }
    }
}