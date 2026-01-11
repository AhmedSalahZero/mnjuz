<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class DropSeoFromPagesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->dropColumn('seo');
            $table->timestamp('created_at')->nullable();
        });

        Schema::table('billing_invoices', function (Blueprint $table) {
            $table->decimal('coupon_amount', 23, 2)->nullable()->default(0)->change();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->string('seo')->nullable();
            $table->dropColumn('created_at');
        });

        Schema::table('billing_invoices', function (Blueprint $table) {
            $table->decimal('coupon_amount', 23, 2)->nullable(false)->default(0)->change();
        });
    }
}