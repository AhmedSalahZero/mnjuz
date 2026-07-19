<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_categories', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 50)->unique();
            $table->unsignedBigInteger('organization_id');
            $table->string('name');
            $table->timestamps();

            $table->index('organization_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_categories');
    }
};
