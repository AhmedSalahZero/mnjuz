<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Replace the single `current_organization_id` column with two
     * platform-specific columns so that web and mobile sessions can
     * track different active organizations independently.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('current_web_organization_id')
                ->nullable()
                ->after('id')
                ->comment('Active organization for the user on the web dashboard.');

            $table->unsignedBigInteger('current_mobile_organization_id')
                ->nullable()
                ->after('current_web_organization_id')
                ->comment('Active organization for the user on the mobile app.');

            $table->foreign('current_web_organization_id')
                ->references('id')->on('organizations')
                ->nullOnDelete();

            $table->foreign('current_mobile_organization_id')
                ->references('id')->on('organizations')
                ->nullOnDelete();
        });

        // Migrate existing data: every previous value belonged to the
        // mobile flow (the only consumer of this column), so seed
        // current_mobile_organization_id from it.
        if (Schema::hasColumn('users', 'current_organization_id')) {
            DB::statement('UPDATE users SET current_mobile_organization_id = current_organization_id WHERE current_organization_id IS NOT NULL');

            Schema::table('users', function (Blueprint $table) {
                $table->dropForeign(['current_organization_id']);
                $table->dropColumn('current_organization_id');
            });
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('current_organization_id')
                ->nullable()
                ->after('id');

            $table->foreign('current_organization_id')
                ->references('id')->on('organizations')
                ->nullOnDelete();
        });

        DB::statement('UPDATE users SET current_organization_id = COALESCE(current_mobile_organization_id, current_web_organization_id) WHERE current_organization_id IS NULL');

        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['current_web_organization_id']);
            $table->dropColumn('current_web_organization_id');
            $table->dropForeign(['current_mobile_organization_id']);
            $table->dropColumn('current_mobile_organization_id');
        });
    }
};
