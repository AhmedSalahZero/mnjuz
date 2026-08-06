<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * فصل معرّف فاتورة بوابة الدفع عن مفتاح فاتورتنا.
 *
 * `billing_payments.invoice_id` مفتاح أجنبي إلى `billing_invoices`، لكن معالج
 * ماي فاتورة كان يكتب فيه `InvoiceId` القادم من البوابة (أرقام بعشرات
 * الملايين مقابل فواتيرنا ذات الخانتين). فكل بحث عن فاتورة الدفعة يرجع
 * فارغاً — ولهذا لم تصل دفعة واحدة إلى منصة الفوترة.
 *
 * هنا: عمود مستقلّ للمعرّف الخارجي، ثم إعادة ربط الدفعات السابقة بفواتيرها
 * الحقيقية عبر تطابق زمن الحركتين — الدفعة وفاتورتها تُنشآن في معاملة واحدة
 * فتحملان الطابع الزمني نفسه.
 */
return new class extends Migration
{
    /** فارق مسموح بالثواني بين حركة الدفعة وحركة الفاتورة المقابلة. */
    private const MATCH_WINDOW = 5;

    public function up(): void
    {
        if (!Schema::hasColumn('billing_payments', 'gateway_invoice_id')) {
            Schema::table('billing_payments', function (Blueprint $table) {
                $table->string('gateway_invoice_id', 64)->nullable()->after('transaction_id')->index();
            });
        }

        $moved = $this->moveForeignIds();
        $relinked = $this->relinkToLocalInvoices();

        Log::info('Separated gateway invoice ids from billing payments', [
            'moved_to_gateway_column' => $moved,
            'relinked_to_local_invoice' => $relinked,
        ]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('billing_payments', 'gateway_invoice_id')) {
            Schema::table('billing_payments', function (Blueprint $table) {
                $table->dropColumn('gateway_invoice_id');
            });
        }
    }

    /**
     * نقل كل invoice_id لا يقابله صفّ في billing_invoices إلى العمود الجديد.
     */
    private function moveForeignIds(): int
    {
        $moved = 0;

        DB::table('billing_payments')
            ->whereNotNull('invoice_id')
            ->orderBy('id')
            ->chunkById(500, function ($payments) use (&$moved) {
                $ids = $payments->pluck('invoice_id')->unique()->all();
                $known = DB::table('billing_invoices')->whereIn('id', $ids)->pluck('id')->all();
                $known = array_flip($known);

                foreach ($payments as $payment) {
                    if (isset($known[(int) $payment->invoice_id])) {
                        continue; // معرّف سليم يشير لفاتورتنا — لا يُمسّ.
                    }

                    DB::table('billing_payments')
                        ->where('id', $payment->id)
                        ->update([
                            'gateway_invoice_id' => $payment->invoice_id,
                            'invoice_id' => null,
                        ]);

                    $moved++;
                }
            });

        return $moved;
    }

    /**
     * ربط الدفعات بفواتيرها المحلية.
     *
     * `billing_invoices` بلا أعمدة زمنية، فنستدلّ بحركات الفوترة: لكل دفعة
     * حركة، ولكل فاتورة حركة، وتُنشآن معاً في نفس المعاملة. نطابق داخل نافذة
     * ضيّقة ولنفس المنشأة، ونتجاهل ما لا يطابق بدل التخمين.
     */
    private function relinkToLocalInvoices(): int
    {
        $relinked = 0;

        $paymentTransactions = DB::table('billing_transactions')
            ->where('entity_type', 'payment')
            ->orderBy('id')
            ->get(['organization_id', 'entity_id', 'created_at']);

        $unlinked = DB::table('billing_payments')
            ->whereNull('invoice_id')
            ->pluck('id')
            ->flip();

        foreach ($paymentTransactions as $row) {
            if (!isset($unlinked[$row->entity_id]) || !$row->created_at) {
                continue;
            }

            $invoiceTransaction = DB::table('billing_transactions')
                ->where('entity_type', 'invoice')
                ->where('organization_id', $row->organization_id)
                ->whereBetween('created_at', [
                    date('Y-m-d H:i:s', strtotime($row->created_at) - self::MATCH_WINDOW),
                    date('Y-m-d H:i:s', strtotime($row->created_at) + self::MATCH_WINDOW),
                ])
                ->first(['entity_id']);

            if (!$invoiceTransaction) {
                continue;
            }

            $invoiceExists = DB::table('billing_invoices')
                ->where('id', $invoiceTransaction->entity_id)
                ->exists();

            if (!$invoiceExists) {
                continue;
            }

            DB::table('billing_payments')
                ->where('id', $row->entity_id)
                ->update(['invoice_id' => $invoiceTransaction->entity_id]);

            $relinked++;
        }

        return $relinked;
    }
};
