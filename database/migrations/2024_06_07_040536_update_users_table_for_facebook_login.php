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
        Schema::table('users', function (Blueprint $table) {
            // Drop the unique constraint on the email field
            $table->dropUnique('users_email_unique');
            
            // Add the facebook_id field, making it nullable and unique
            $table->string('facebook_id')->nullable()->unique()->after('email');
            
            // Add a composite unique constraint on email and deleted_at
            $table->unique(['email', 'deleted_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Drop the composite unique constraint on email and deleted_at
            $table->dropUnique(['email', 'deleted_at']);
            
            // Drop the facebook_id field
            $table->dropColumn('facebook_id');
            
            // Restore the unique constraint on the email field
            $table->unique('email');
        });
    }
};
