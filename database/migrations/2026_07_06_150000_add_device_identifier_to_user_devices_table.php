<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_devices', function (Blueprint $table) {
            $table->string('device_identifier')->nullable()->after('device_category');
            $table->index('device_identifier');
        });
    }

    public function down(): void
    {
        Schema::table('user_devices', function (Blueprint $table) {
            $table->dropIndex(['device_identifier']);
            $table->dropColumn('device_identifier');
        });
    }
};
