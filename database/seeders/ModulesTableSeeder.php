<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ModulesTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $modules = [
            [
                'name' => 'customers',
                'actions' => 'view, create, edit, delete',
            ],
            [
                'name' => 'organizations',
                'actions' => 'view, create, edit, delete',
            ],
            [
                'name' => 'billing',
                'actions' => 'view',
            ],
            [
                'name' => 'support',
                'actions' => 'view, create, assign',
            ],
            [
                'name' => 'team',
                'actions' => 'view, create, edit, delete',
            ],
            [
                'name' => 'roles',
                'actions' => 'view, create, edit, delete',
            ],
            [
                'name' => 'subscription_plans',
                'actions' => 'view, create, edit, delete',
            ],
            [
                'name' => 'settings',
                'actions' => 'general, timezone, broadcast_driver, payment_gateways, smtp, email_templates, billing, tax_rates, coupons, frontend',
            ],
        ];

        foreach ($modules as $module) {
            // Check if the module already exists by name
            $existingModule = DB::table('modules')->where('name', $module['name'])->exists();

            if (!$existingModule) {
                // Insert only if the module name does not exist
                DB::table('modules')->insert($module);
            }
        }
    }
}