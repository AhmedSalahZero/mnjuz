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
        Schema::create('contact_fields', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->char('uuid', 50)->nullable(false);
            $table->unsignedBigInteger('position')->nullable();
            $table->string('name', 128);
            $table->string('type', 128);
            $table->text('value')->nullable();
            $table->unsignedTinyInteger('required');
            $table->softDeletes();
            $table->integer('deleted_by')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contact_fields');
    }
};
