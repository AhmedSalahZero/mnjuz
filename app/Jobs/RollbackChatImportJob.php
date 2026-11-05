<?php

namespace App\Jobs;

use App\Models\ChatImport;
use App\Services\ActivityLogger;
use App\Services\ChatImport\ChatImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * التراجع عن استيراد: يحذف رسائله وحدها، ثم ما أنشأه ولم يعد له استعمال.
 *
 * الرسائل تُحذف حذفاً ليّناً ويُفرَّغ wam_id: الفهرس الفريد على wam_id يشمل
 * المحذوف، وبقاء البصمة كان سيمنع رفع الملف نفسه بعد تصحيحه.
 *
 * وجهة الاتصال والتذكرة اللتان أنشأهما الاستيراد تُحذفان فقط إن لم يبقَ
 * لهما أثر: عميلٌ راسل المنشأة بعد الاستيراد، أو عمل عليه الفريق بملاحظة أو
 * مجموعة أو حملة، صار عميلاً حقيقياً.
 */
class RollbackChatImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    private const CHUNK = 1000;

    /** ما يدلّ على أن جهة الاتصال مستعملة بعد حذف رسائل الاستيراد. */
    private const TRACES_WITH_SOFT_DELETE = ['chats', 'chat_logs', 'chat_notes'];

    private const TRACES = ['contact_contact_group', 'contact_category_contact', 'campaign_logs'];

    public int $timeout = 600;

    public int $tries = 5;

    public int $maxExceptions = 3;

    public function __construct(public int $chatImportId)
    {
        $this->onQueue(config('chat_import.queue', 'high'));
    }

    public function handle(): void
    {
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

        if ($continue) {
            self::dispatch($this->chatImportId)->delay((int) config('chat_import.slice_gap_seconds', 5));
        }
    }

    private function runSlice(): bool
    {
        $import = ChatImport::with('user')->find($this->chatImportId);

        if (!$import || $import->status !== ChatImport::ROLLING_BACK) {
            return false;
        }

        App::setLocale($import->locale ?: config('app.locale'));

        $deadline = microtime(true) + (int) config('chat_import.slice_seconds', 60);
        $userId = (int) $import->user_id;

        while (true) {
            $ids = DB::table('chats')
                ->where('chat_import_id', $import->id)
                ->whereNull('deleted_at')
                ->orderBy('id')
                ->limit(self::CHUNK)
                ->pluck('id')
                ->all();

            if ($ids === []) {
                break;
            }

            DB::transaction(function () use ($ids, $userId, $import) {
                $now = now()->utc()->toDateTimeString();

                DB::table('chats')->whereIn('id', $ids)->update([
                    'deleted_at' => $now,
                    'deleted_by' => $userId,
                    'wam_id' => null,
                ]);

                DB::table('chat_logs')
                    ->where('entity_type', 'chat')
                    ->whereIn('entity_id', $ids)
                    ->whereNull('deleted_at')
                    ->update(['deleted_at' => $now, 'deleted_by' => $userId]);

                DB::table('chat_imports')->where('id', $import->id)->update([
                    'rolled_back_messages' => DB::raw('rolled_back_messages + ' . count($ids)),
                    'updated_at' => now(),
                ]);
            }, 3);

            if (microtime(true) >= $deadline) {
                return true;
            }
        }

        $this->cleanUpContacts($import);

        $import->refresh()->forceFill([
            'status' => ChatImport::ROLLED_BACK,
            'finished_at' => now(),
        ])->save();

        ChatImportService::deleteUploadedFile($import);
        ChatImportService::log($import, ActivityLogger::CHAT_IMPORT_ROLLED_BACK, ['messages' => $import->rolled_back_messages]);

        return false;
    }

    private function cleanUpContacts(ChatImport $import): void
    {
        DB::table('chat_import_contacts')
            ->where('chat_import_id', $import->id)
            ->chunkById(self::CHUNK, function ($rows) {
                $contactIds = $rows->pluck('contact_id')->map(fn ($id) => (int) $id)->all();

                // آخر رسالة باقية لكل جهة — من الرسائل وحدها لا من سجلّ التذاكر والملاحظات.
                DB::update(
                    'UPDATE contacts c LEFT JOIN ('
                    . ' SELECT contact_id, MAX(created_at) AS latest FROM chat_logs'
                    . " WHERE entity_type = 'chat' AND deleted_at IS NULL AND contact_id IN (" . implode(',', $contactIds) . ')'
                    . ' GROUP BY contact_id'
                    . ') s ON s.contact_id = c.id'
                    . ' SET c.latest_chat_created_at = s.latest'
                    . ' WHERE c.id IN (' . implode(',', $contactIds) . ')'
                );

                $inUse = $this->contactsInUse($contactIds);

                $ticketContacts = array_diff($rows->where('ticket_created', 1)->pluck('contact_id')->map(fn ($id) => (int) $id)->all(), $inUse);
                if ($ticketContacts !== []) {
                    // تذكرة أعادت فتحها رسالة حقيقية بعد الاستيراد ليست تذكرة الاستيراد بعد الآن.
                    DB::table('chat_tickets')
                        ->whereIn('contact_id', $ticketContacts)
                        ->where('status', 'closed')
                        ->whereNull('assigned_to')
                        ->delete();
                }

                $createdContacts = array_diff($rows->where('contact_created', 1)->pluck('contact_id')->map(fn ($id) => (int) $id)->all(), $inUse);
                if ($createdContacts !== []) {
                    DB::table('contacts')
                        ->whereIn('id', $createdContacts)
                        ->whereNull('deleted_at')
                        ->update(['deleted_at' => now()->utc()->toDateTimeString(), 'latest_chat_created_at' => null]);
                }
            });
    }

    /**
     * @param array<int, int> $contactIds
     * @return array<int, int> الجهات التي بقي لها أثر بعد حذف رسائل الاستيراد
     */
    private function contactsInUse(array $contactIds): array
    {
        $inUse = [];

        foreach (self::TRACES_WITH_SOFT_DELETE as $table) {
            $inUse[] = DB::table($table)->whereIn('contact_id', $contactIds)->whereNull('deleted_at')->distinct()->pluck('contact_id')->all();
        }

        foreach (self::TRACES as $table) {
            $inUse[] = DB::table($table)->whereIn('contact_id', $contactIds)->distinct()->pluck('contact_id')->all();
        }

        return array_values(array_unique(array_map('intval', array_merge(...$inUse))));
    }

    public function failed(Throwable $e): void
    {
        Log::error('RollbackChatImportJob failed', ['chat_import_id' => $this->chatImportId, 'exception' => $e]);

        $import = ChatImport::with('user')->find($this->chatImportId);
        if (!$import || $import->status !== ChatImport::ROLLING_BACK) {
            return;
        }

        App::setLocale($import->locale ?: config('app.locale'));

        // يعود إلى «فشل» فيبقى زرّ التراجع متاحاً لمحاولة ثانية.
        $import->forceFill([
            'status' => ChatImport::FAILED,
            'error_message' => __('Rolling back the import stopped because of an unexpected error. Try again.'),
            'finished_at' => now(),
        ])->save();
    }
}
