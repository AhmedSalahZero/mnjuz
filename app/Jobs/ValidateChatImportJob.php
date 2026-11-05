<?php

namespace App\Jobs;

use App\Models\ChatImport;
use App\Services\ActivityLogger;
use App\Services\ChatImport\ChatImportService;
use App\Services\ChatImport\ChatImportValidator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * المرحلة الأولى: فحص الملف كلّه دون كتابة شيء.
 *
 * محاولة واحدة: الفحص قراءة فقط، وإن سقط العامل في منتصفه فإعادة المحاولة
 * تجد الحالة «validating» وتنتهي — فتُعلَّم فاشلة عبر failed() بدل أن تبقى
 * معلّقة.
 */
class ValidateChatImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(public int $chatImportId)
    {
        $this->onQueue(config('chat_import.queue', 'imports'));
    }

    public function handle(): void
    {
        $import = ChatImport::with('user')->find($this->chatImportId);

        if (!$import || $import->status !== ChatImport::PENDING_VALIDATION) {
            return;
        }

        App::setLocale($import->locale ?: config('app.locale'));

        $import->forceFill(['status' => ChatImport::VALIDATING, 'progress_bytes' => 0])->save();

        $report = (new ChatImportValidator($import))->run(function (int $bytes) use ($import) {
            DB::table('chat_imports')->where('id', $import->id)->update(['progress_bytes' => $bytes, 'updated_at' => now()]);

            return DB::table('chat_imports')->where('id', $import->id)->value('status') !== ChatImport::CANCELLING;
        });

        $import->refresh();

        if ($report->cancelled || $import->status === ChatImport::CANCELLING) {
            $import->forceFill(['status' => ChatImport::CANCELLED, 'finished_at' => now()])->save();
            ChatImportService::deleteUploadedFile($import);

            return;
        }

        $import->forceFill([
            'total_rows' => $report->totalRows,
            'progress_bytes' => $import->file_size,
            'error_count' => $report->errorCount,
            'errors' => $report->errorsPreview,
            'errors_file_path' => $report->errorsFilePath,
            'error_message' => $report->fileError,
            'ignored_columns' => $report->ignoredColumns,
            'new_messages_estimate' => $report->isValid() ? $report->newMessages() : 0,
            'new_contacts_estimate' => $report->isValid() ? $report->newContacts : 0,
        ]);

        if (!$report->isValid()) {
            $import->forceFill(['status' => ChatImport::INVALID, 'finished_at' => now()])->save();
            // الملف المرفوض لا يُستأنف منه شيء — يبقى تقرير أخطائه وحده.
            ChatImportService::deleteUploadedFile($import);
            ChatImportService::log($import, ActivityLogger::CHAT_IMPORT_FAILED, ['reason' => 'invalid', 'errors' => $report->errorCount]);

            return;
        }

        $import->forceFill(['status' => ChatImport::QUEUED])->save();

        // آخر سطر: على طابور sync (الاختبارات) يعمل الاستيراد هنا فوراً، ولا
        // يجوز أن يُكتب بعده شيء يطمس حالته.
        ImportChatHistoryJob::dispatch($import->id);
    }

    public function failed(Throwable $e): void
    {
        Log::error('ValidateChatImportJob failed', ['chat_import_id' => $this->chatImportId, 'exception' => $e]);

        $import = ChatImport::with('user')->find($this->chatImportId);
        if (!$import || !in_array($import->status, [ChatImport::PENDING_VALIDATION, ChatImport::VALIDATING, ChatImport::CANCELLING], true)) {
            return;
        }

        App::setLocale($import->locale ?: config('app.locale'));

        $import->forceFill([
            'status' => ChatImport::FAILED,
            'error_message' => __('The file could not be checked. Please try again or contact support.'),
            'finished_at' => now(),
        ])->save();

        ChatImportService::deleteUploadedFile($import);
        ChatImportService::log($import, ActivityLogger::CHAT_IMPORT_FAILED, ['reason' => 'validation_error']);
    }
}
