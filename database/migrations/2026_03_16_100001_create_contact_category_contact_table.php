<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_category_contact', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained()->onDelete('cascade');
            $table->foreignId('contact_category_id')->constrained()->onDelete('cascade');
            $table->timestamps();

            $table->unique(['contact_id', 'contact_category_id']);
            $table->index(['contact_category_id', 'contact_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_category_contact');
    }
};
