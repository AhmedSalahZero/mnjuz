<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateBillingInvoicesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('billing_invoices', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->char('uuid', 50)->unique();
            $table->integer('organization_id');
            $table->integer('plan_id');
            $table->decimal('subtotal', 13, 2)->default(0.00);
            $table->integer('coupon_id')->nullable();
            $table->decimal('coupon_amount', 13, 10)->default(0.0000000000);
            $table->decimal('tax', 23, 10)->default(0.0000000000);
            $table->enum('tax_type', ['inclusive', 'exclusive']);
            $table->decimal('total', 13, 10)->default(0.0000000000);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('billing_invoices');
    }
}
