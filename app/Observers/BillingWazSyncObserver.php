<?php

namespace App\Observers;

use App\Jobs\SyncWazBillingJob;
use App\Models\BillingInvoice;
use App\Models\BillingPayment;

/**
 * يدفع كل فاتورة ودفعة جديدة إلى طابور المزامنة مع واز أعمال.
 *
 * عبر Observer لا بتعديل كل بوابة دفع: الدفعات تُنشأ في خمسة معالجات
 * (MyFatoorah، Stripe، PayPal، Flutterwave، وغيرها)، ونقطة الإنشاء واحدة
 * فيلتقطها المراقب جميعاً بلا تكرار ولا نسيان بوابة.
 */
class BillingWazSyncObserver
{
    public function created(BillingInvoice|BillingPayment $model): void
    {
        $job = $model instanceof BillingInvoice
            ? SyncWazBillingJob::forInvoice((int) $model->id)
            : SyncWazBillingJob::forPayment((int) $model->id);

        // afterCommit لأن الإنشاء يجري داخل معاملة؛ بدونه قد يقرأ العامل
        // صفّاً لم يُثبَّت بعد.
        dispatch($job)->afterCommit();
    }
}
