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
        Schema::create('chat_notes', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 50)->nullable(false);
            $table->unsignedBigInteger('contact_id');
            $table->text('content')->nullable();
            $table->integer('created_by')->nullable();
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
        Schema::dropIfExists('chat_notes');
    }
};
