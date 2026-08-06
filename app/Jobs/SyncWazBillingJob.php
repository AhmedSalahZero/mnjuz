<?php

namespace App\Jobs;

use App\Models\BillingInvoice;
use App\Models\BillingPayment;
use App\Services\WazSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * مزامنة فاتورة أو دفعة مع منصة واز أعمال.
 *
 * في الطابور لا متزامنة عمداً — بخلاف التسجيل. الدفع يقع بعد أخذ المال من
 * العميل، فإيقافه لأن نظام المحاسبة متوقف يخسر عملية بيع مكتملة. الأصح أن
 * ننجح محلياً ثم نعيد المحاولة حتى تنجح المزامنة.
 *
 * المزامنة متماثلة: المعرّف المحفوظ يمنع التكرار عند إعادة المحاولة.
 */
class SyncWazBillingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 5;

    /** تباعد متصاعد ليتجاوز الأعطال المؤقتة دون إغراق المنصة. */
    public $backoff = [60, 300, 900, 3600];

    public $timeout = 60;

    public function __construct(
        private string $type,
        private int $recordId
    ) {
    }

    public static function forInvoice(int $invoiceId): self
    {
        return new self('invoice', $invoiceId);
    }

    public static function forPayment(int $paymentId): self
    {
        return new self('payment', $paymentId);
    }

    public function handle(WazSyncService $sync): void
    {
        if (!$sync->enabled()) {
            return;
        }

        if ($this->type === 'invoice') {
            $invoice = BillingInvoice::with('plan')->find($this->recordId);
            if ($invoice) {
                $sync->syncInvoice($invoice);
            }

            return;
        }

        $payment = BillingPayment::find($this->recordId);
        if (!$payment) {
            return;
        }

        // الدفعة تحتاج فاتورة مُزامَنة أولاً؛ لو لم تُزامَن بعد نُعيد المحاولة
        // لاحقاً بدل أن نتخطّاها نهائياً.
        $invoice = $payment->invoice_id ? BillingInvoice::with('plan')->find($payment->invoice_id) : null;
        if ($invoice && !$invoice->waz_invoice_id && !$invoice->waz_setup_invoice_id) {
            $sync->syncInvoice($invoice);
        }

        $sync->syncPayment($payment);

        // syncPayment تنصرف بصمت إذا لم تكن الفاتورة جاهزة — والدفعة تُنشأ قبل
        // فاتورتها في نفس المعاملة، فقد تصل هذه الوظيفة و`invoice_id` بعدُ
        // فارغ. الانصراف بلا استثناء يعني ألّا تُعاد المحاولة أبداً فتضيع
        // الدفعة من نظام المحاسبة. نُطلقها من جديد بعد مهلة.
        if (!$payment->fresh()?->waz_synced_at && $sync->companyId((int) $payment->organization_id)) {
            Log::info('Waz billing sync: payment not ready, releasing for retry', [
                'payment_id' => $payment->id,
                'invoice_id' => $payment->invoice_id,
            ]);

            $this->release(120);
        }
    }

    public function failed(Throwable $e): void
    {
        Log::error('Waz billing sync failed after all retries', [
            'type' => $this->type,
            'record_id' => $this->recordId,
            'error' => $e->getMessage(),
        ]);
    }
}
