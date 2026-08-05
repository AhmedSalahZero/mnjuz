<?php

namespace App\Console\Commands;

use App\Exceptions\WazBusinessException;
use App\Models\BillingInvoice;
use App\Models\BillingPayment;
use App\Services\WazSyncService;
use Illuminate\Console\Command;

/**
 * إصدار فواتير واز للفواتير المحلية السابقة للربط.
 *
 * الفواتير الجديدة تُزامَن تلقائياً عبر BillingWazSyncObserver، أما ما أُنشئ
 * قبل تفعيل الربط فلا نظير له في واز — فلا يظهر لصاحبه زرّ «عرض الفاتورة».
 * هذا الأمر يسدّ تلك الفجوة مرة واحدة.
 *
 * ينشئ مستندات مالية حقيقية في المنصة المحاسبية، لذلك يتوقف افتراضياً عند
 * كل منشأة للتأكيد ويحتاج --force لتخطّي ذلك.
 */
class BackfillWazInvoices extends Command
{
    protected $signature = 'waz:backfill-invoices
        {--org= : منشأة واحدة بمعرّفها بدل كل المنشآت المربوطة}
        {--payments : مزامنة الدفعات المرتبطة بها أيضاً}
        {--dry-run : عرض ما سيُرسَل دون إرساله}
        {--force : بلا سؤال تأكيد}';

    protected $description = 'Create Waz Business invoices for local invoices that predate the integration';

    public function handle(WazSyncService $waz): int
    {
        if (!$waz->enabled()) {
            $this->error('الربط مع واز أعمال معطّل. راجع WAZ_BUSINESS_URL و WAZ_BUSINESS_API_TOKEN.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        $invoices = BillingInvoice::query()
            ->whereNull('waz_invoice_id')
            ->whereNull('waz_setup_invoice_id')
            ->when($this->option('org'), fn ($q, $org) => $q->where('organization_id', (int) $org))
            ->orderBy('organization_id')
            ->orderBy('id')
            ->get();

        // المنشأة غير المربوطة لا وجهة لفاتورتها، فنستبعدها قبل السؤال.
        $invoices = $invoices->filter(fn ($invoice) => $waz->companyId((int) $invoice->organization_id) !== null);

        if ($invoices->isEmpty()) {
            $this->info('لا توجد فواتير غير مزامَنة لمنشآت مربوطة.');

            return self::SUCCESS;
        }

        $synced = 0;
        $failed = 0;

        foreach ($invoices->groupBy('organization_id') as $organizationId => $group) {
            $companyId = $waz->companyId((int) $organizationId);
            $this->line("منشأة #{$organizationId} ← شركة واز #{$companyId}: {$group->count()} فاتورة");

            foreach ($group as $invoice) {
                $this->line(sprintf(
                    '  فاتورة #%d — اشتراك %s، تأسيس %s',
                    $invoice->id,
                    number_format((float) $invoice->subtotal, 2),
                    number_format((float) ($invoice->setup_fee ?? 0), 2)
                ));
            }

            if ($dryRun) {
                continue;
            }

            if (!$this->option('force') && !$this->confirm("إصدار فواتير واز لهذه المنشأة؟", false)) {
                continue;
            }

            foreach ($group as $invoice) {
                try {
                    $waz->syncInvoice($invoice);
                    $invoice->refresh();
                    $this->info(sprintf(
                        '  ✓ فاتورة #%d ← واز اشتراك %s تأسيس %s',
                        $invoice->id,
                        $invoice->waz_invoice_id ?: '—',
                        $invoice->waz_setup_invoice_id ?: '—'
                    ));
                    $synced++;

                    if ($this->option('payments')) {
                        $this->backfillPayments($waz, (int) $invoice->id);
                    }
                } catch (WazBusinessException $e) {
                    $this->error("  ✗ فاتورة #{$invoice->id}: {$e->getMessage()}");
                    $failed++;
                }
            }
        }

        if ($dryRun) {
            $this->comment('تشغيل تجريبي — لم يُرسل شيء.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->info("تمّت مزامنة {$synced} فاتورة" . ($failed ? "، وفشلت {$failed}" : ''));

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * الدفعات المسجَّلة على فاتورة سبق ألّا يكون لها نظير في واز — تُرسَل الآن
     * لتظهر الفاتورة مدفوعة هناك كما هي عندنا.
     */
    private function backfillPayments(WazSyncService $waz, int $invoiceId): void
    {
        $payments = BillingPayment::where('invoice_id', $invoiceId)
            ->whereNull('waz_synced_at')
            ->get();

        foreach ($payments as $payment) {
            try {
                $waz->syncPayment($payment);
                $this->info("    ✓ دفعة #{$payment->id} بمبلغ {$payment->amount}");
            } catch (WazBusinessException $e) {
                $this->error("    ✗ دفعة #{$payment->id}: {$e->getMessage()}");
            }
        }
    }
}
