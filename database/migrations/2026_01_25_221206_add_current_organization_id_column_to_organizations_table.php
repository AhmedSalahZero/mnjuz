<?php

use App\Models\Addon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('current_organization_id')->comment('The current organization id for the user for mobile app')->nullable()->after('id');
			$table->foreign('current_organization_id')->references('id')->on('organizations')->nullOnDelete();
        });
		
		$existingAddon = Addon::where('uuid','746a8b7f-40dc-40b0-924e-9459ad5ed671')->first();
		if ($existingAddon) {
			$addon = $existingAddon->replicate();
			$addon->uuid = Str::uuid();
			$addon->category = 'utility';
			$addon->name = 'Mobile App';
			$addon->logo = 'mobile_app.png';
			$addon->version = '1.0';
			$addon->is_plan_restricted = 1;
			$addon->is_active = 1;
			$addon->description = 'Mobile App allows users to access the application on their mobile devices.';
			$addon->metadata = NULL;
			$addon->status = 1;
			$addon->save();
		}
		
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        
    }
};
