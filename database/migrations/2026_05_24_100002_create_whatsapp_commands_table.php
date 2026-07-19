<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_commands', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->string('command_name', 30);
            $table->string('command_description', 256);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->index(['organization_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_commands');
    }
};
