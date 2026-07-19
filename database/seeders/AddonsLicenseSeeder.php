<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AddonsLicenseSeeder extends Seeder
{
    public function run()
    {
        // Get all addons from the database
        $addons = DB::table('addons')->whereNull('license')->get();

        foreach ($addons as $addon) {
            // Determine license based on addon name
            $license = match ($addon->name) {
                'Embedded Signup' => 'extended',
                'Webhooks', 'Flow builder', 'AI Assistant' => 'standalone',
                default => 'regular',
            };

            // Update the addon with the new license value
            DB::table('addons')
                ->where('id', $addon->id)
                ->whereNull('license')
                ->update(['license' => $license]);
        }
    }
}
