<?php

use App\Models\Organization;
use App\Models\Team;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->boolean('has_working_hours')->default(false)
            // ->after('is_assignable')
            ;
        });

        Schema::create('team_working_hours', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Team::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(Organization::class)->constrained()->cascadeOnDelete();
            $table->enum('day_of_week', ['MONDAY', 'TUESDAY', 'WEDNESDAY', 'THURSDAY', 'FRIDAY', 'SATURDAY', 'SUNDAY']);
            $table->time('start_time');
            $table->time('end_time');
            $table->boolean('is_active')->default(true);
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();

            // Index for querying available team members at a specific time
            $table->index(['organization_id', 'day_of_week', 'is_active'], 'team_hours_availability_index');
            $table->index(['team_id', 'day_of_week'], 'team_hours_team_day_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('team_working_hours');

        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn('has_working_hours');
        });
    }
};
