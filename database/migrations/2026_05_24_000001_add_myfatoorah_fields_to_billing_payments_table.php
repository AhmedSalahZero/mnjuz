<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_payments', function (Blueprint $table) {
            $table->string('transaction_id')->nullable()->after('details');
            $table->string('invoice_id')->nullable()->after('transaction_id');
            $table->string('payment_status')->nullable()->after('invoice_id');
            $table->string('payment_method')->nullable()->after('payment_status');
            $table->string('currency', 3)->default('SAR')->after('payment_method');

            $table->index(['processor', 'transaction_id']);
            $table->index(['processor', 'invoice_id']);
        });
    }

    public function down(): void
    {
        Schema::table('billing_payments', function (Blueprint $table) {
            $table->dropIndex(['processor', 'transaction_id']);
            $table->dropIndex(['processor', 'invoice_id']);

            $table->dropColumn([
                'transaction_id',
                'invoice_id',
                'payment_status',
                'payment_method',
                'currency',
            ]);
        });
    }
};
