<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contact_categories', function (Blueprint $table) {
            $table->string('background_color', 20)->default('#22c55e')->after('name');
            $table->string('text_color', 20)->default('#ffffff')->after('background_color');
        });

        // Ensure existing rows have defaults (in case of old schema / null values).
        DB::table('contact_categories')->whereNull('background_color')->update([
            'background_color' => '#22c55e',
        ]);
        DB::table('contact_categories')->whereNull('text_color')->update([
            'text_color' => '#ffffff',
        ]);
    }

    public function down(): void
    {
        Schema::table('contact_categories', function (Blueprint $table) {
            $table->dropColumn(['background_color', 'text_color']);
        });
    }
};

