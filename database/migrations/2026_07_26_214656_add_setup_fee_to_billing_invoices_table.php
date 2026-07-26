<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Setup fees are one-time and must not be included in upgrade proration.
     * Store them separately on the invoice so they can be excluded from credit calculations.
     */
    public function up()
    {
        Schema::table('billing_invoices', function (Blueprint $table) {
            $table->decimal('setup_fee', 19, 4)->default(0)->after('subtotal');
        });

        // Backfill: setup fee is charged only on the first invoice per organization.
        $firstInvoiceIds = DB::table('billing_invoices')
            ->select(DB::raw('MIN(id) as id'))
            ->groupBy('organization_id')
            ->pluck('id');

        foreach ($firstInvoiceIds as $invoiceId) {
            $invoice = DB::table('billing_invoices')->where('id', $invoiceId)->first();
            if (!$invoice) {
                continue;
            }

            $plan = DB::table('subscription_plans')->where('id', $invoice->plan_id)->first();
            if (!$plan || blank($plan->metadata)) {
                continue;
            }

            $metadata = json_decode($plan->metadata, true) ?: [];
            $setupFee = (float) ($metadata['setup_fee'] ?? 0);

            if ($setupFee > 0 && (float) $invoice->total > $setupFee) {
                DB::table('billing_invoices')
                    ->where('id', $invoice->id)
                    ->update(['setup_fee' => $setupFee]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down()
    {
        Schema::table('billing_invoices', function (Blueprint $table) {
            $table->dropColumn('setup_fee');
        });
    }
};
