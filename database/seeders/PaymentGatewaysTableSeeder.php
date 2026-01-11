<?php

namespace Database\Seeders;

use App\Models\PaymentGateway;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class PaymentGatewaysTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        // Define the data to be seeded
        $gateways = [
            [
                'name' => 'Paypal',
                'metadata' => NULL,
                'is_active' => 0,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            [
                'name' => 'Stripe',
                'metadata' => NULL,
                'is_active' => 0,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            [
                'name' => 'Flutterwave',
                'metadata' => NULL,
                'is_active' => 0,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
        ];

        foreach ($gateways as $gateway) {
            PaymentGateway::firstOrCreate(
                ['name' => $gateway['name']], // Search criteria
                $gateway
            );
        }
    }
}