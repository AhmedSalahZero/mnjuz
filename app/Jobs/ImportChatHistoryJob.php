<?php

namespace App\Jobs;

use App\Models\ChatImport;
use App\Services\ActivityLogger;
use App\Services\ChatImport\BatchReader;
use App\Services\ChatImport\ChatImportLimits;
use App\Services\ChatImport\ChatImportService;
use App\Services\ChatImport\ChatImportWriter;
use App\Services\ChatImport\ContactResolver;
use App\Services\ChatImport\CsvReader;
use App\Services\ChatImport\HeaderMap;
use App\Services\ChatImport\RowParser;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * المرحلة الثانية: كتابة الرسائل على شرائح.
 *
 * كل شريحة تكتب دفعاتٍ لبضع دقائق ثم تُسلّم ما بقي لشريحة جديدة من موضعها
 * المحفوظ. retry_after على Redis هو 660 ثانية، ووظيفة واحدة لمئتي ألف
 * رسالة كانت ستتجاوزه فيلتقطها عامل ثانٍ وهي ما زالت تعمل.
 *
 * والدفعة الواحدة معاملة واحدة تحفظ موضعها معها: إن سقط العامل في منتصفها
 * تراجعت كلّها، وبدأت المحاولة التالية من أوّلها. والبصمات تجعل إعادة ما
 * كُتب قبل السقوط بلا أثر.
 */
class ImportChatHistoryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 600;

    public int $tries = 5;

    public int $maxExceptions = 3;

    public function __construct(public int $chatImportId)
    {
        $this->onQueue(config('chat_import.queue', 'imports'));
    }

    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(): void
    {
        // شريحتان للاستيراد نفسه لا تعملان معاً (زرّ «استئناف» يُضغط مرّتين).
        // القفل يدويّ لا WithoutOverlapping: ذاك يبقى حتى تنتهي handle()،
        // والشريحة التالية تُرسَل من داخلها — فتجده مقفلاً وتُؤجَّل، وعلى طابور
        // sync لا تعمل أصلاً.
        $lock = Cache::lock('chat-import:' . $this->chatImportId, 650);

        if (!$lock->get()) {
            $this->release(30);

            return;
        }

        try {
            $continue = $this->runSlice();
        } finally {
            $lock->release();
        }

        // بعد فكّ القفل، وآخر سطر: على طابور sync تعمل الشريحة التالية هنا.
        if ($continue) {
            self::dispatch($this->chatImportId);
        }
    }

    /** @return bool هل بقي ما تكتبه شريحة أخرى؟ */
    private function runSlice(): bool
    {
        $import = ChatImport::with('user')->find($this->chatImportId);

        if (!$import) {
            return false;
        }

        App::setLocale($import->locale ?: config('app.locale'));

        if ($import->status === ChatImport::CANCELLING) {
            $this->finishCancelled($import);

            return false;
        }

        if (!in_array($import->status, [ChatImport::QUEUED, ChatImport::IMPORTING], true)) {
            return false;
        }

        if ($import->status === ChatImport::QUEUED && !$this->limitsStillAllow($import)) {
            return false;
        }

        if (!ChatImportService::fileExists($import)) {
            $this->markFailed($import, __('The uploaded file is no longer available. Upload it again to continue.'));

            return false;
        }

        $import->forceFill([
            'status' => ChatImport::IMPORTING,
            'started_at' => $import->started_at ?? now(),
            'error_message' => null,
            'finished_at' => null,
        ])->save();

        $reader = CsvReader::open(Storage::disk('local')->path($import->file_path));
        $reader->seek($import->cursor_offset);

        $batches = new BatchReader(
            $reader,
            new HeaderMap($reader->header()),
            new RowParser((string) $import->timezone, $import->default_country, (int) config('chat_import.max_text', 65535)),
            (int) $import->organization_id,
            $import->processed_rows + 2
        );
        $writer = new ChatImportWriter($import, new ContactResolver((int) $import->organization_id));

        $deadline = microtime(true) + (int) config('chat_import.slice_seconds', 240);
        $sliceRows = config('chat_import.slice_rows');
        $written = 0;

        try {
            while (($batch = $batches->next((int) config('chat_import.batch_rows', 1000), (int) config('chat_import.batch_bytes', 4 * 1024 * 1024))) !== null) {
                if (DB::table('chat_imports')->where('id', $import->id)->value('status') === ChatImport::CANCELLING) {
                    $this->finishCancelled($import->refresh());

                    return false;
                }

                $writer->write($batch['rows'], $batch['end_offset']);
                $written += count($batch['rows']);

                $outOfTime = microtime(true) >= $deadline;
                $sliceFull = $sliceRows !== null && $written >= (int) $sliceRows;

                if ($outOfTime || $sliceFull) {
                    ChatImportService::refreshContactCounters($import->refresh());

                    return true;
                }
            }
        } finally {
            $reader->close();
        }

        $this->finishCompleted($import->refresh());

        return false;
    }

    /**
     * الحدّ يُفحص ثانيةً قبل أوّل كتابة: الرسائل الحيّة لم تتوقّف منذ الفحص،
     * وربما بلغت المنشأة حدّها في الأثناء.
     */
    private function limitsStillAllow(ChatImport $import): bool
    {
        $error = ChatImportLimits::check(
            (int) $import->organization_id,
            max(0, $import->new_contacts_estimate - $import->contacts_created),
            max(0, $import->new_messages_estimate - $import->imported_messages)
        );

        if ($error === null) {
            return true;
        }

        $import->forceFill([
            'status' => ChatImport::FAILED,
            'error_message' => $error,
            'finished_at' => now(),
        ])->save();

        ChatImportService::log($import, ActivityLogger::CHAT_IMPORT_FAILED, ['reason' => 'limit']);

        return false;
    }

    private function finishCompleted(ChatImport $import): void
    {
        ChatImportService::refreshContactCounters($import);

        $import->forceFill([
            'status' => ChatImport::COMPLETED,
            'processed_rows' => $import->total_rows,
            'finished_at' => now(),
        ])->save();

        ChatImportService::deleteUploadedFile($import);
        ChatImportService::log($import, ActivityLogger::CHAT_IMPORT_COMPLETED, [
            'messages' => $import->imported_messages,
            'contacts_created' => $import->contacts_created,
        ]);
    }

    private function finishCancelled(ChatImport $import): void
    {
        ChatImportService::refreshContactCounters($import);

        // الملف يبقى: ما كُتب قبل الإلغاء يمكن التراجع عنه أو استئنافه.
        $import->forceFill(['status' => ChatImport::CANCELLED, 'finished_at' => now()])->save();
    }

    public function failed(Throwable $e): void
    {
        Log::error('ImportChatHistoryJob failed', ['chat_import_id' => $this->chatImportId, 'exception' => $e]);

        $import = ChatImport::with('user')->find($this->chatImportId);
        if (!$import || !in_array($import->status, [ChatImport::QUEUED, ChatImport::IMPORTING, ChatImport::CANCELLING], true)) {
            return;
        }

        App::setLocale($import->locale ?: config('app.locale'));

        $message = $e instanceof \App\Services\ChatImport\ChatImportException
            ? $e->getMessage()
            : __('The import stopped because of an unexpected error. You can resume it or roll it back.');

        $this->markFailed($import, $message);
    }

    private function markFailed(ChatImport $import, string $message): void
    {
        ChatImportService::refreshContactCounters($import);

        $import->forceFill([
            'status' => ChatImport::FAILED,
            'error_message' => $message,
            'finished_at' => now(),
        ])->save();

        ChatImportService::log($import, ActivityLogger::CHAT_IMPORT_FAILED, ['reason' => 'error']);
    }
}
