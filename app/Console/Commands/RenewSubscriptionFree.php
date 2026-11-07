<?php

namespace App\Console\Commands;

use App\Models\BillingInvoice;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * تجديد اشتراك منشأة بلا مقابل: لا دفعة، ولا حركة رصيد، ولا قيد في واز.
 *
 * مدّ التاريخ وحده لا يكفي. حساب الترقية يفترض أن آخر فاتورة موّلت المدة
 * الحالية فيردّ جزءها غير المستهلَك رصيداً، فتتحوّل المدة المجانية إلى رصيد
 * حقيقي عند أول ترقية. لذلك تُسجَّل فاتورة صفرية تصير هي «آخر فاتورة».
 */
class RenewSubscriptionFree extends Command
{
    protected $signature = 'subscriptions:renew-free
        {organization : رقم المنشأة}
        {--months= : عدد الأشهر، والافتراضي مدة الباقة}
        {--dry-run : عرض ما سيحدث دون تنفيذ}';

    protected $description = 'Renew an organization subscription free of charge';

    public function handle(): int
    {
        $organizationId = (int) $this->argument('organization');
        $organization = Organization::find($organizationId);

        if (!$organization) {
            $this->error("لا توجد منشأة برقم {$organizationId}.");

            return self::FAILURE;
        }

        $subscription = Subscription::where('organization_id', $organizationId)->first();
        $plan = $subscription ? SubscriptionPlan::find($subscription->plan_id) : null;

        if (!$plan) {
            $this->error("المنشأة #{$organizationId} بلا باقة تُجدَّد.");

            return self::FAILURE;
        }

        $months = $this->resolveMonths($plan);

        if ($months === null) {
            $this->error('--months يجب أن يكون عدداً صحيحاً موجباً.');

            return self::FAILURE;
        }

        // القيمة الخام لا الـaccessor: هذا يحوّلها لتوقيت المنشأة، والكتابة
        // بها تُزيح التاريخ بفارق التوقيت.
        $currentEnd = Carbon::parse($subscription->getRawOriginal('valid_until'));
        $isRunning = $currentEnd->isFuture();

        // الاشتراك الساري يُمدّ من نهايته كي لا تضيع أيامه المتبقية.
        $newEnd = ($isRunning ? $currentEnd->copy() : Carbon::now())->addMonthsNoOverflow($months);

        $this->line(sprintf('  منشأة #%d — %s', $organizationId, $organization->name));
        $this->line(sprintf('  الباقة: %s', $plan->name));
        $this->line(sprintf('  من: %s UTC', $currentEnd->toDateTimeString()));
        $this->line(sprintf('  إلى: %s UTC', $newEnd->toDateTimeString()));

        if ($this->option('dry-run')) {
            $this->warn('تشغيل تجريبي: لم يتغيّر شيء.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($organizationId, $plan, $isRunning, $newEnd) {
            $this->closeProrationBase($organizationId, $plan->id);

            Subscription::where('organization_id', $organizationId)->update(array_filter([
                'start_date' => $isRunning ? null : Carbon::now()->toDateTimeString(),
                'valid_until' => $newEnd->toDateTimeString(),
                'status' => 'active',
            ]));
        });

        Log::info('Subscription renewed free of charge', [
            'organization_id' => $organizationId,
            'plan_id' => $plan->id,
            'months' => $months,
            'valid_until' => $newEnd->toDateTimeString(),
        ]);

        $this->info('✓ جُدّد بلا مقابل.');

        return self::SUCCESS;
    }

    private function resolveMonths(SubscriptionPlan $plan): ?int
    {
        $option = $this->option('months');

        if ($option === null) {
            return $plan->period === 'monthly' ? 1 : 12;
        }

        return ctype_digit((string) $option) && (int) $option > 0 ? (int) $option : null;
    }

    /**
     * فاتورة صفرية تمنع ردّ قيمة آخر فاتورة رصيداً عند الترقية.
     *
     * لا تُسجَّل لمنشأة بلا فاتورة ذات قيمة: لا شيء يُردّ أصلاً، ووجود فاتورة
     * يُسقط رسوم التأسيس عن أول اشتراك مدفوع.
     *
     * subtotal صفر لا total وحده: مزامنة واز ترجع إلى subtotal حين يكون total
     * صفراً، فتُصدر فاتورة بالمبلغ كاملاً.
     */
    private function closeProrationBase(int $organizationId, int $planId): void
    {
        $lastInvoice = BillingInvoice::where('organization_id', $organizationId)->orderBy('id', 'desc')->first();

        if (!$lastInvoice || (float) $lastInvoice->total <= 0) {
            return;
        }

        BillingInvoice::create([
            'organization_id' => $organizationId,
            'plan_id' => $planId,
            'subtotal' => 0,
            'tax_type' => 'exclusive',
            'total' => 0,
        ]);
    }
}
