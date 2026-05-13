<?php

use App\Models\Addon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (Addon::where('name', 'Working Hours')->exists()) {
            return;
        }

        Addon::create([
            'uuid' => (string) Str::uuid(),
            'category' => 'utility',
            'name' => 'Working Hours',
            'logo' => 'working_hours.png',
            'description' => 'Define weekly business hours and send an automatic WhatsApp reply outside those hours.',
            'metadata' => null,
            'status' => 1,
            'is_active' => 1,
            'is_plan_restricted' => 1,
            'version' => '1.0',
        ]);
    }

    public function down(): void
    {
        Addon::where('name', 'Working Hours')->delete();
    }
};
