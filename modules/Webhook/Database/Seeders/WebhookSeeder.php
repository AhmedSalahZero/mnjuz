<?php

namespace Modules\Webhook\Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Addon;

class WebhookSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Addon::where('name', 'Webhooks')->update([
            'metadata' => NULL,
            'status' => 1,
        ]);
    }
}
