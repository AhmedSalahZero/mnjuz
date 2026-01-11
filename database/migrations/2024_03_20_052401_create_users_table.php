<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateUsersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('users', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('first_name', 128);
            $table->string('last_name', 128)->nullable();
            $table->string('email', 191)->unique();
            $table->string('avatar', 191)->nullable();
            $table->string('role', 191)->default('user');
            $table->string('phone', 191)->nullable();
            $table->text('address')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password', 191);
            $table->integer('status')->default(1);
            $table->text('meta')->nullable();
            $table->text('plan')->nullable();
            $table->unsignedBigInteger('plan_id')->nullable();
            $table->date('will_expire')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes(); // Adds a 'deleted_at' column for soft deletes
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('users');
    }
}
