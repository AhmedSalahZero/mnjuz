<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * تنظيف الحالة `played` من الصفوف القائمة.
 *
 * واتساب يرسلها حين يشغّل المستلم رسالة صوتية، وتطبيق الموبايل يرفض الردّ
 * كلّه إذا وردت فيه — فتنكسر شاشة المحادثات لا الرسالة وحدها. المنع من
 * الآن يجري في ProcessMessageStatusJob قبل الحفظ، وهذه للصفوف السابقة.
 *
 * على دفعات لأن chats جدول ضخم (ملايين الصفوف في الإنتاج) وتحديثه دفعة
 * واحدة يقفل الصفوف طويلاً.
 */
return new class extends Migration
{
    private const CHUNK = 1000;

    public function up(): void
    {
        $chats = $this->rewriteChats('played', 'delivered');
        $logs = $this->rewriteLogs('played', 'delivered');

        Log::info('Normalized played message status', [
            'chats' => $chats,
            'chat_status_logs' => $logs,
        ]);
    }

    /**
     * لا رجعة: `played` لم تعد تُحفظ أصلاً، وإعادتها تكسر التطبيق من جديد.
     */
    public function down(): void
    {
    }

    private function rewriteChats(string $from, string $to): int
    {
        $total = 0;

        do {
            $affected = DB::table('chats')
                ->where('status', $from)
                ->limit(self::CHUNK)
                ->update(['status' => $to]);

            $total += $affected;
        } while ($affected > 0);

        return $total;
    }

    /**
     * سجلّ الحالات يحفظ بلاغ الويب هوك كما وصل (JSON)، فنُبدّل قيمة status
     * داخله بدل الصفّ كلّه — بقية الحقول (id, recipient_id, timestamp) مرجع
     * تدقيق لا يصحّ المساس به.
     */
    private function rewriteLogs(string $from, string $to): int
    {
        $total = 0;
        $lastId = 0;

        // التنقّل بالمعرّف لا بالإزاحة: الصفوف التي نتخطّاها تبقى مطابقة
        // للبحث، فبدونه تعود الدفعة نفسها بلا نهاية.
        do {
            $rows = DB::table('chat_status_logs')
                ->where('id', '>', $lastId)
                ->where('metadata', 'like', '%"' . $from . '"%')
                ->orderBy('id')
                ->limit(self::CHUNK)
                ->get(['id', 'metadata']);

            foreach ($rows as $row) {
                $lastId = (int) $row->id;
                $metadata = json_decode((string) $row->metadata, true);

                if (!is_array($metadata) || ($metadata['status'] ?? null) !== $from) {
                    // مطابقة نصّية عابرة لا حالة فعلية — نتخطّاها دون تعديل.
                    continue;
                }

                $metadata['status'] = $to;

                DB::table('chat_status_logs')
                    ->where('id', $row->id)
                    ->update(['metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE)]);

                $total++;
            }
        } while ($rows->count() === self::CHUNK);

        return $total;
    }
};
