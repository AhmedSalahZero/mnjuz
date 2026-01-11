<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class ModifyAmountColumnsInBillingTables extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('billing_credits', function (Blueprint $table) {
            $table->decimal('amount', 19, 4)->change();
        });

        Schema::table('billing_debits', function (Blueprint $table) {
            $table->decimal('amount', 19, 4)->change();
        });

        Schema::table('billing_invoices', function (Blueprint $table) {
            $table->decimal('subtotal', 19, 4)->change();
            $table->decimal('coupon_amount', 19, 4)->change();
            $table->decimal('total', 19, 4)->change();
        });

        Schema::table('billing_items', function (Blueprint $table) {
            $table->decimal('amount', 19, 4)->change();
        });

        Schema::table('billing_payments', function (Blueprint $table) {
            $table->decimal('amount', 19, 4)->change();
        });

        Schema::table('billing_tax_rates', function (Blueprint $table) {
            $table->decimal('rate', 19, 4)->change();
            $table->decimal('amount', 19, 4)->change();
        });

        Schema::table('billing_transactions', function (Blueprint $table) {
            $table->decimal('amount', 19, 4)->change();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('billing_credits', function (Blueprint $table) {
            $table->decimal('amount', 13, 2)->change();  // Adjust as per original definition
        });

        Schema::table('billing_debits', function (Blueprint $table) {
            $table->decimal('amount', 13, 2)->change();  // Adjust as per original definition
        });

        Schema::table('billing_invoices', function (Blueprint $table) {
            $table->decimal('subtotal', 13, 2)->change();  // Adjust as per original definition
            $table->decimal('coupon_amount', 13, 2)->change();  // Adjust as per original definition
            $table->decimal('total', 13, 2)->change();  // Adjust as per original definition
        });

        Schema::table('billing_items', function (Blueprint $table) {
            $table->decimal('amount', 13, 2)->change();  // Adjust as per original definition
        });

        Schema::table('billing_payments', function (Blueprint $table) {
            $table->decimal('amount', 13, 2)->change();  // Adjust as per original definition
        });

        Schema::table('billing_tax_rates', function (Blueprint $table) {
            $table->decimal('rate', 13, 2)->change();  // Adjust as per original definition
            $table->decimal('amount', 13, 2)->change();  // Adjust as per original definition
        });

        Schema::table('billing_transactions', function (Blueprint $table) {
            $table->decimal('amount', 13, 2)->change();  // Adjust as per original definition
        });
    }
}
