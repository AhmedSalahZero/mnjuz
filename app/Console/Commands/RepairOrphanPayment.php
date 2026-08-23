<?php

namespace App\Console\Commands;

use App\Jobs\SyncWazBillingJob;
use App\Models\BillingInvoice;
use App\Models\BillingPayment;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Services\SubscriptionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * إصلاح دفعة سُدّدت ولم تُنتج فاتورة ولا فعّلت اشتراكاً.
 *
 * يقع هذا حين يختلف الحسابان: يُحصَّل مبلغ مخفَّض عند البوّابة، ثم يُطلَب
 * السعر كاملاً عند التفعيل لأن الكوبون كان في الجلسة وقد ضاعت. الفرق يجعل
 * `amountDue != 0` فيخرج التفعيل صامتاً — والعميل دفع وبقي في تجربته.
 *
 * الأمر يعالج ما وقع قبل الإصلاح. للقراءة أوّلاً: لا يكتب شيئاً بلا --apply،
 * ولا يلمس دفعةً لها فاتورة أصلاً.
 */
class RepairOrphanPayment extends Command
{
    protected $signature = 'billing:repair-payment
        {payment : رقم الدفعة في billing_payments}
        {--plan= : رقم الباقة إن تعذّر استنتاجها}
        {--coupon= : كود الكوبون الذي طُبّق وقت الدفع}
        {--charged= : المبلغ المحصَّل فعلاً حين يتعذّر استرجاعه بالحساب}
        {--apply : التنفيذ الفعلي — بدونه عرضٌ فقط}';

    protected $description = 'إصدار فاتورة وتفعيل اشتراك لدفعة سُدّدت ولم تُعالَج';

    public function handle(): int
    {
        $payment = BillingPayment::find((int) $this->argument('payment'));

        if (!$payment) {
            $this->error('لا توجد دفعة بهذا الرقم.');

            return self::FAILURE;
        }

        if ($payment->invoice_id) {
            $this->info("الدفعة #{$payment->id} لها فاتورة #{$payment->invoice_id} — لا شيء يُصلَح.");

            return self::SUCCESS;
        }

        if ($payment->payment_status !== 'paid') {
            $this->error("حالة الدفعة «{$payment->payment_status}» لا «paid» — لا تُفعَّل خدمة بدفعة غير مسدّدة.");

            return self::FAILURE;
        }

        $planId = $this->resolvePlanId($payment);

        if (!$planId) {
            $this->error('تعذّر تحديد الباقة. مرّرها بـ--plan=');

            return self::FAILURE;
        }

        $plan = SubscriptionPlan::find($planId);

        if (!$plan) {
            $this->error("لا توجد باقة #{$planId}.");

            return self::FAILURE;
        }

        $coupon = $this->option('coupon') ?: null;
        $details = SubscriptionService::calculateSubscriptionBillingDetails(
            (int) $payment->organization_id,
            $planId,
            $coupon
        );

        $this->report($payment, $plan, $details, $coupon);

        $due = (float) str_replace(',', '', (string) $details['amountDue']);

        if (abs($due) > 0.009) {
            // الفارق قد يكون سعراً تغيّر بعد الدفع، فلا يُسترجَع بالحساب مهما
            // مرّرنا من كوبونات. عندها نصدّق ما حُصِّل فعلاً — لكن بإعلان صريح
            // من المشغّل، لا استنتاجاً: المبلغ يُكتب في فاتورة رسمية.
            $charged = $this->option('charged');

            if ($charged === null) {
                $this->newLine();
                $this->error('المتبقّي ليس صفراً — التفعيل سيُرفض كما رُفض أوّل مرّة.');
                $this->line('  إن كان الفارق خصماً طُبّق وقت الدفع، مرّر الكوبون بـ--coupon=');
                $this->line('  وإن تغيّر السعر بعد الدفع، أعلِن المحصَّل بـ--charged=');

                return self::FAILURE;
            }

            if (abs((float) $charged - (float) $payment->amount) > 0.009) {
                $this->error('‏--charged لا يطابق مبلغ الدفعة (' . $payment->amount . ') — لا تُصدَر فاتورة بمبلغ لم يُحصَّل.');

                return self::FAILURE;
            }

            $this->newLine();
            $this->warn('سيُعتمد المحصَّل فعلاً (' . $charged . ') بدل الحساب المعاد.');
        }

        if (!$this->option('apply')) {
            $this->newLine();
            $this->warn('عرضٌ فقط. أضِف --apply للتنفيذ.');

            return self::SUCCESS;
        }

        return $this->apply($payment, $planId);
    }

    // ------------------------------------------------------ التنفيذ

    private function apply(BillingPayment $payment, int $planId): int
    {
        $invoice = DB::transaction(function () use ($payment, $planId) {
            $issued = SubscriptionService::updateSubscriptionPlan(
                (int) $payment->organization_id,
                $planId,
                $this->ownerId($payment),
                $this->option('coupon') ?: null
            );

            if (!$issued instanceof BillingInvoice) {
                return null;
            }

            $payment->invoice_id = $issued->id;
            $payment->save();

            return $issued;
        });

        if (!$invoice) {
            $this->error('لم تصدر فاتورة — راجع storage/logs، فالسبب صار يُسجَّل الآن.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->info("✅ صدرت الفاتورة #{$invoice->id} ورُبطت بالدفعة #{$payment->id}");

        $subscription = Subscription::where('organization_id', $payment->organization_id)->first();
        $this->line("  الاشتراك : {$subscription?->status} | خطة #{$subscription?->plan_id} | حتى {$subscription?->valid_until}");

        // المزامنة تُنفَّذ الآن لا في الطابور.
        //
        // الأمر إصلاحٌ يدوي يُشغَّل من حيث اتّفق — وقد لا يكون طابور المُشغِّل هو
        // طابور الخادم، فتضيع المهمّة بصمت وتبقى الفاتورة خارج نظام المحاسبة.
        // وفشلها لا يُلغي التفعيل: الخدمة سُلّمت والمحاسبة تُعاد وحدها.
        try {
            \Illuminate\Support\Facades\Bus::dispatchSync(SyncWazBillingJob::forInvoice($invoice->id));
            \Illuminate\Support\Facades\Bus::dispatchSync(SyncWazBillingJob::forPayment($payment->id));
            $this->line('  ✅ تمّت المزامنة مع واز.');
        } catch (\Throwable $e) {
            $this->warn('  ⚠️ تعذّرت المزامنة مع واز: ' . $e->getMessage());
            $this->line('     التفعيل والفاتورة سليمان. أعِد المزامنة لاحقاً بـ:');
            $this->line("     php artisan billing:repair-payment {$payment->id} --apply");
        }

        return self::SUCCESS;
    }

    // ------------------------------------------------------ العرض

    /** @param array<string, mixed> $details */
    private function report(BillingPayment $payment, SubscriptionPlan $plan, array $details, ?string $coupon): void
    {
        $subscription = Subscription::where('organization_id', $payment->organization_id)->first();

        $this->newLine();
        $this->table(['البند', 'القيمة'], [
            ['الدفعة', '#' . $payment->id . '  ' . number_format((float) $payment->amount, 2) . ' ' . $payment->currency],
            ['المنظّمة', '#' . $payment->organization_id],
            ['الاشتراك الآن', ($subscription?->status ?? '—') . ' | خطة ' . ($subscription?->plan_id ?? 'NULL')],
            ['الباقة المطلوبة', '#' . $plan->id . ' ' . $plan->name . ' (' . $plan->price . ')'],
            ['رسوم التأسيس', $details['setupFee'] ?? '0'],
            ['الإجمالي', $details['netAmount'] ?? '?'],
            ['رصيد الحساب', $details['accountBalance'] ?? '?'],
            ['الكوبون', $coupon ?: '—'],
            ['المتبقّي', $details['amountDue'] ?? '?'],
        ]);
    }

    // ---------------------------------------------------- مساعدات

    /** الباقة من الخيار، أو من نيّة الشراء المحفوظة مع الدفعة. */
    private function resolvePlanId(BillingPayment $payment): ?int
    {
        if ($this->option('plan')) {
            return (int) $this->option('plan');
        }

        // المرجع المحفوظ لدى البوّابة بصيغة org_user_plan.
        $reference = (string) ($payment->details ?? '');
        if (preg_match('/^\d+_\d+_(\d+)$/', $reference, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    private function ownerId(BillingPayment $payment): int
    {
        return (int) (DB::table('teams')
            ->where('organization_id', $payment->organization_id)
            ->whereNull('deleted_at')
            ->orderByRaw("FIELD(role, 'owner', 'manager')")
            ->value('user_id') ?? 0);
    }
}
