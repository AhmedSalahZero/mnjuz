<?php

namespace App\Console\Commands;

use App\Models\ChatImport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * حذف ملفات الاستيراد المنتهية.
 *
 * ملف المحادثات يُحذف فور اكتمال الاستيراد، أمّا الفاشل والملغى فيبقى ليُستأنف
 * منه، وتقرير الأخطاء يبقى ليُنزَّل. بعد مدّة الاحتفاظ لا يعود أحدٌ إليها،
 * وملف من مئتي ميغابايت لكل محاولة يملأ القرص.
 */
class PruneChatImports extends Command
{
    protected $signature = 'chat-imports:prune {--days= : أيام الاحتفاظ (الافتراضي من config/chat_import.php)}';

    protected $description = 'Delete uploaded files and error reports of finished chat imports';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?: config('chat_import.retention_days', 7));
        $disk = Storage::disk('local');
        $pruned = 0;

        ChatImport::query()
            ->whereNotIn('status', ChatImport::ACTIVE_STATUSES)
            ->where('updated_at', '<', now()->subDays(max(1, $days)))
            ->where(fn ($q) => $q->whereNotNull('file_path')->orWhereNotNull('errors_file_path'))
            ->chunkById(200, function ($imports) use ($disk, &$pruned) {
                foreach ($imports as $import) {
                    foreach (['file_path', 'errors_file_path'] as $column) {
                        if ($import->{$column}) {
                            $disk->delete($import->{$column});
                        }
                    }

                    $import->forceFill(['file_path' => null, 'errors_file_path' => null])->saveQuietly();
                    $pruned++;
                }
            });

        $this->info("Pruned files of {$pruned} chat import(s).");

        return self::SUCCESS;
    }
}
