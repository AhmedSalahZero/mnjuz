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
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('organization_id');
            $table->string('source', 128);
            $table->string('title', 128)->nullable();
            $table->text('content')->nullable();
            $table->json('embeddings')->nullable();
            $table->string('status', 128);
            $table->timestamps();
        });

        // Modify the contacts table to add is_ai_active column
        Schema::table('contacts', function (Blueprint $table) {
            $table->boolean('ai_assistance_enabled')->default(0)->after('is_favorite');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop the is_ai_active column from the contacts table
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn('ai_assistance_enabled');
        });

        Schema::dropIfExists('documents');
    }
};
