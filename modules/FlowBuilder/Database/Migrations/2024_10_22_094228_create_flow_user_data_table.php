<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateFlowUserDataTable extends Migration
{
    public function up()
    {
        Schema::create('flow_user_data', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('contact_id');  // Reference to the user interacting with the flow
            $table->foreignId('flow_id')->constrained('flows')->onDelete('cascade');
            $table->unsignedBigInteger('current_step');
            $table->timestamps();

            $table->index('contact_id');
        });
    }

    public function down()
    {
        Schema::dropIfExists('flow_user_data');
    }
}
