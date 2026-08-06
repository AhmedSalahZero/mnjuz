<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Services\SubscriptionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * تجديد الاشتراكات المنتهية من رصيد الحساب.
 *
 * الخصم من الرصيد كان يجري في لحظة الدفع فقط، وبشرط أن يكون الاشتراك منتهياً
 * تلك اللحظة. فمن يشحن رصيده قبل انتهاء اشتراكه — وهو الشائع، إذ يدفع العميل
 * حين يتذكّر لا حين ينتهي — لا يُستهلك رصيده أبداً: يمرّ تاريخ الانتهاء ولا
 * شيء يُعيد الفحص، فيتوقّف حسابه ورصيده كافٍ.
 *
 * كان الفحص الدوري في CheckSubscriptionStatus لكنه مُعطَّل بتعليق، وحتى لو
 * أُعيد فهو لا يعمل إلا حين يفتح العميل المنصة — والرسائل الواردة لا تنتظر
 * دخوله. لذلك أمر مجدوَل.
 */
class RenewSubscriptionsFromCredits extends Command
{
    protected $signature = 'subscriptions:renew-from-credits
        {--org= : منشأة واحدة بمعرّفها}
        {--dry-run : عرض من سيُجدَّد دون تنفيذ}';

    protected $description = 'Renew expired subscriptions for organizations whose account balance covers the plan';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $expired = Subscription::query()
            ->whereNotNull('plan_id')
            ->where('valid_until', '<', now())
            ->when($this->option('org'), fn ($q, $org) => $q->where('organization_id', (int) $org))
            ->orderBy('organization_id')
            ->get(['organization_id', 'plan_id', 'valid_until']);

        if ($expired->isEmpty()) {
            $this->info('لا اشتراكات منتهية.');

            return self::SUCCESS;
        }

        $renewed = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($expired as $subscription) {
            $organizationId = (int) $subscription->organization_id;

            try {
                // المستحقّ صفراً يعني أن الرصيد والأرصدة المرحّلة تغطّي الباقة.
                $details = SubscriptionService::calculateSubscriptionBillingDetails(
                    $organizationId,
                    $subscription->plan_id
                );

                if ((float) str_replace(',', '', $details['amountDue']) != 0.0) {
                    $skipped++;
                    continue;
                }

                $this->line(sprintf(
                    '  منشأة #%d — باقة %s، رصيد %s، انتهى %s',
                    $organizationId,
                    $subscription->plan_id,
                    $details['accountBalance'],
                    $subscription->valid_until
                ));

                if ($dryRun) {
                    $renewed++;
                    continue;
                }

                $invoice = SubscriptionService::activateSubscriptionIfInactiveAndExpiredWithCredits($organizationId);

                if ($invoice) {
                    $this->info(sprintf('    ✓ جُدّد — فاتورة #%d', $invoice->id));
                    Log::info('Subscription renewed from account balance', [
                        'organization_id' => $organizationId,
                        'invoice_id' => $invoice->id,
                        'plan_id' => $subscription->plan_id,
                    ]);
                    $renewed++;
                } else {
                    $skipped++;
                }
            } catch (\Throwable $e) {
                // منشأة واحدة معطوبة لا تُسقط البقية.
                $failed++;
                $this->error("  ✗ منشأة #{$organizationId}: {$e->getMessage()}");
                Log::error('Subscription renewal from credits failed', [
                    'organization_id' => $organizationId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->newLine();
        $this->info($dryRun
            ? "تشغيل تجريبي: {$renewed} ستُجدَّد، {$skipped} رصيدها لا يكفي."
            : "جُدّد {$renewed}، تُخطّي {$skipped}" . ($failed ? "، فشل {$failed}" : '') . '.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
