<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * تتبّع خفيف لنشاط الموظفين لقياس الأداء: كل موظف/منظمة/يوم يجمع الثواني
     * النشطة (heartbeat) وآخر نبضة. يُشتق منها "آخر نشاط" و"الوقت النشط".
     */
    public function up(): void
    {
        if (Schema::hasTable('agent_activity')) {
            return;
        }

        Schema::create('agent_activity', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('user_id');
            $table->date('activity_date');
            $table->unsignedInteger('active_seconds')->default(0);
            $table->timestamp('last_ping_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'user_id', 'activity_date'], 'uniq_agent_activity_day');
            $table->index(['organization_id', 'activity_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_activity');
    }
};
