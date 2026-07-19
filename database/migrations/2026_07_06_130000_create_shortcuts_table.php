<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * اختصارات الردود السريعة: يكتب الموظف / في صندوق الرد فتظهر له.
     * scope = personal (خاص بمن أنشأه) أو company (يظهر لكل موظفي المنظمة).
     */
    public function up(): void
    {
        if (Schema::hasTable('shortcuts')) {
            return;
        }

        Schema::create('shortcuts', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->enum('scope', ['personal', 'company'])->default('personal');
            $table->string('command');
            $table->text('message');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'scope']);
            $table->index(['organization_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shortcuts');
    }
};
