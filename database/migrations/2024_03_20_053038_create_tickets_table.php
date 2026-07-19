<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTicketsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->char('uuid', 50)->nullable(false);
            $table->string('reference', 128)->nullable(false);
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('category_id');
            $table->string('subject', 1024)->nullable(false);
            $table->string('message', 1024)->nullable(false);
            $table->enum('priority', ['critical', 'high', 'medium', 'low'])->nullable();
            $table->unsignedBigInteger('assigned_to')->nullable();
            $table->enum('status', ['open', 'pending', 'resolved', 'closed'])->nullable(false)->default('pending');
            $table->unsignedBigInteger('closed_by')->nullable();
            $table->timestamps();

            // Foreign key constraints
            $table->foreign('user_id')->references('id')->on('users');
            $table->foreign('category_id')->references('id')->on('ticket_categories');
            $table->foreign('assigned_to')->references('id')->on('users');
            $table->foreign('closed_by')->references('id')->on('users');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('tickets');
    }
}
