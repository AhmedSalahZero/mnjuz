<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Services\ActivityLogger;
use Illuminate\Console\Command;

/**
 * يحذف سجلّات النشاط التي تجاوزت مدّة الاحتفاظ (سبعة أيام).
 *
 * الحذف على دفعات لا بجملة واحدة: جملة DELETE على ملايين الصفوف تحبس الجدول
 * وتُضخّم سجلّ المعاملات، والكتابة في هذا الجدول مستمرّة مع كل فعل مستخدم.
 */
class PruneActivityLogs extends Command
{
    protected $signature = 'activity:prune
                            {--days= : تجاوز مدّة الاحتفاظ الافتراضية}
                            {--chunk=5000 : عدد الصفوف في الدفعة}
                            {--dry-run : اعرض ما سيُحذف دون حذف}';

    protected $description = 'Delete activity log rows older than the retention window';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?: ActivityLogger::RETENTION_DAYS);
        $chunk = max(1, (int) $this->option('chunk'));
        $dryRun = (bool) $this->option('dry-run');

        $cutoff = now()->subDays($days);

        $total = ActivityLog::where('created_at', '<', $cutoff)->count();

        if ($total === 0) {
            $this->info(sprintf('لا شيء أقدم من %s.', $cutoff->toDateTimeString()));

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->warn(sprintf('[معاينة] %s صفّاً أقدم من %s سيُحذف.', number_format($total), $cutoff->toDateTimeString()));

            return self::SUCCESS;
        }

        $deleted = 0;
        do {
            $batch = ActivityLog::where('created_at', '<', $cutoff)->limit($chunk)->delete();
            $deleted += $batch;
        } while ($batch > 0);

        $this->info(sprintf('حُذف %s صفّاً أقدم من %s.', number_format($deleted), $cutoff->toDateTimeString()));

        return self::SUCCESS;
    }
}
