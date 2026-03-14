<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('device_tokens', function (Blueprint $table) {
            $table->id();
			$table->string('device_token',400);
			$table->string('device_name')->nullable();
			$table->string('device_type')->nullable();
			$table->string('model_type')->comment('Driver Or Client');
			$table->unsignedBigInteger('model_id');
			// $table->string('country_iso2');
			// $table->string('phone');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('device_tokens');
    }
};
