<?php

namespace App\Console\Commands;

use App\Models\WebChatVisitor;
use App\Services\Messaging\Drivers\WebChatDriver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * تنظيف المحادثة المباشرة.
 *
 * ١. زوّار ملؤوا النموذج ولم يكتبوا رسالة قطّ (لا جهة اتصال لهم) بعد
 *    مدّة: صفوف لا يراها أحد، وفيها اسم ورقم — بيانات شخصية لا داعي
 *    لإبقائها. الحذف نهائي لأن الحذف الناعم يُبقي البيانات نفسها.
 * ٢. ملفات رفعها الزائر في المجلّد المؤقّت: تُنقل إلى التخزين الدائم في
 *    مهمّة التنزيل، والمؤقّت يبقى احتياطاً لإعادة المحاولة. بعد يوم لا
 *    حاجة إليه.
 */
class PruneWebChat extends Command
{
    protected $signature = 'webchat:prune
                            {--visitor-days=30 : عمر الزائر بلا رسائل قبل حذفه}
                            {--temp-hours=24 : عمر الملف المؤقّت قبل حذفه}';

    protected $description = 'Delete web chat visitors who never sent a message, and stale temporary uploads';

    public function handle(): int
    {
        $visitorDays = max(1, (int) $this->option('visitor-days'));
        $tempHours = max(1, (int) $this->option('temp-hours'));

        $deleted = WebChatVisitor::withTrashed()
            ->whereNull('contact_id')
            ->where('created_at', '<', now()->subDays($visitorDays))
            ->forceDelete();

        $files = $this->pruneTempFiles($tempHours);

        $this->info("Deleted {$deleted} visitor(s) without messages and {$files} temporary file(s).");

        return self::SUCCESS;
    }

    private function pruneTempFiles(int $hours): int
    {
        $disk = Storage::disk(WebChatDriver::TEMP_DISK);

        if (!$disk->exists(WebChatDriver::TEMP_DIRECTORY)) {
            return 0;
        }

        $threshold = now()->subHours($hours)->getTimestamp();
        $count = 0;

        foreach ($disk->allFiles(WebChatDriver::TEMP_DIRECTORY) as $path) {
            if ($disk->lastModified($path) < $threshold) {
                $disk->delete($path);
                $count++;
            }
        }

        return $count;
    }
}
