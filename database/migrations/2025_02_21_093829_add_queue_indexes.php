<?php

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
        Schema::table('jobs', function (Blueprint $table) {
            $table->index(['queue', 'available_at']);
            $table->index(['attempts']);
        });
    }
    
    public function down(): void
    {
        Schema::table('campaign_logs', function (Blueprint $table) {
            $table->index(['status']);
            $table->index(['campaign_id']);
        });
    }
};
