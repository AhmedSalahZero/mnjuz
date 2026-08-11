<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * يفعّل ميزة سجلّ النشاط (activity_log) في باقات الاشتراك.
 *
 * الميزة أُضيفت بعد إنشاء الباقات القائمة، وisSubscriptionFeatureEnabled يُرجع
 * false لأي باقة لا تحوي المفتاح — وهو السلوك الآمن. فبدون هذا الأمر يلزم
 * فتح كل باقة في لوحة الأدمن وتفعيلها يدوياً.
 *
 * لا يمسّ أي مفتاح آخر في metadata: نفكّ الحقل ونضيف المفتاح ونعيد الترميز
 * بنفس الرايات التي يكتب بها SubscriptionPlanService، فيبقى الباقي كما هو.
 * وأي باقة بيانات وصفها ليست JSON صالحاً تُتخطّى دون مساس.
 */
class EnableActivityLogOnPlans extends Command
{
    protected $signature = 'plans:enable-activity-log
                            {--plan=* : معرّفات باقات بعينها (الافتراضي: الكل)}
                            {--disable : أطفئ الميزة بدل تفعيلها}
                            {--with-trashed : اشمل الباقات المحذوفة}
                            {--dry-run : اعرض ما سيحدث دون كتابة}';

    protected $description = 'Turn the activity_log plan feature on (or off) across subscription plans';

    private const FEATURE = 'activity_log';

    public function handle(): int
    {
        $disable = (bool) $this->option('disable');
        $dryRun = (bool) $this->option('dry-run');
        $target = $disable ? 0 : 1;
        $ids = array_filter((array) $this->option('plan'));

        $query = DB::table('subscription_plans');
        if (!$this->option('with-trashed')) {
            $query->whereNull('deleted_at');
        }
        if ($ids) {
            $query->whereIn('id', $ids);
        }

        $plans = $query->orderBy('id')->get(['id', 'name', 'metadata']);

        if ($plans->isEmpty()) {
            $this->warn('لا توجد باقات مطابقة.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%s %s في %d باقة%s',
            $disable ? 'إطفاء' : 'تفعيل',
            self::FEATURE,
            $plans->count(),
            $dryRun ? ' [معاينة]' : ''
        ));

        $changed = 0;
        $already = 0;
        $skipped = 0;
        $rows = [];

        foreach ($plans as $plan) {
            $metadata = json_decode((string) $plan->metadata, true);

            if (!is_array($metadata)) {
                $skipped++;
                $rows[] = [$plan->id, $plan->name, '—', 'تُخطّيت: metadata ليست JSON صالحاً'];
                continue;
            }

            $before = $metadata[self::FEATURE] ?? null;
            $isOn = $before === true || $before === 1 || $before === '1' || $before === 'enabled';

            if ($isOn === (bool) $target) {
                $already++;
                $rows[] = [$plan->id, $plan->name, var_export($before, true), 'لا تغيير'];
                continue;
            }

            $metadata[self::FEATURE] = $target;

            if (!$dryRun) {
                DB::table('subscription_plans')
                    ->where('id', $plan->id)
                    ->update(['metadata' => json_encode($metadata), 'updated_at' => now()]);
            }

            $changed++;
            $rows[] = [$plan->id, $plan->name, var_export($before, true), $dryRun ? "سيصير {$target}" : "صار {$target}"];
        }

        $this->newLine();
        $this->table(['#', 'الباقة', 'قبل', 'النتيجة'], $rows);

        $this->info(sprintf('غُيّرت: %d | كانت كذلك أصلاً: %d | تُخطّيت: %d', $changed, $already, $skipped));

        if ($dryRun) {
            $this->warn('معاينة فقط — لم يُكتب شيء. أعد التشغيل بلا ‎--dry-run‎ للتنفيذ.');
        } elseif ($changed > 0) {
            $this->comment('الميزة تُقرأ من الباقة في كل طلب، فالأثر فوري بلا حاجة لمسح ذاكرة مؤقتة.');
        }

        return self::SUCCESS;
    }
}
