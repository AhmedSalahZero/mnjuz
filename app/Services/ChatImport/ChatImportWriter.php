<?php

namespace App\Services\ChatImport;

use App\Models\ChatImport;
use App\Support\JsonText;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * كتابة دفعة واحدة من الرسائل المستوردة.
 *
 * الكتابة بـ DB::table لا بـ Chat::create، وذلك مقصود:
 *  - Chat::created يضع latest_chat_created_at على تاريخ الرسالة دون مقارنة،
 *    فتاريخٌ أقدم كان سيُرجع المحادثة إلى أسفل القائمة؛
 *  - ويضع last_inbound_chat_created_at فيفتح نافذة الأربع والعشرين ساعة
 *    لرسالة لم تصل هذه المنصّة أصلاً؛
 *  - وكل رسالة صادرة بمعرّف موظّف تُنهي جلسة FlowBuilder للعميل.
 *
 * والرسالة وسجلّها في chat_logs يُكتبان معاً في معاملة واحدة: رسالة بلا سجلّ
 * لا تظهر في المحادثة، وسجلّ بلا رسالة يُظهر فراغاً.
 */
final class ChatImportWriter
{
    private const INSERT_CHUNK = 250;

    public function __construct(
        private readonly ChatImport $import,
        private readonly ContactResolver $resolver,
    ) {
    }

    /**
     * @param array<int, array{row: ParsedRow, fingerprint: string}> $rows
     * @return array{imported: int, duplicates: int}
     */
    public function write(array $rows, int $endOffset, int $skipped = 0): array
    {
        $phones = [];
        foreach ($rows as $item) {
            $phone = $item['row']->phone;
            if (($phones[$phone] ?? null) === null) {
                $phones[$phone] = $item['row']->name;
            }
        }

        // جهات الاتصال خارج المعاملة: إنشاؤها لا يتراجع إن تكرّرت المعاملة،
        // ويُعاد استعمالها في المحاولة التالية.
        $contacts = $this->resolver->resolveOrCreate($phones, (int) $this->import->user_id);

        [$unique, $duplicates] = $this->withoutDuplicates($rows, $contacts);

        DB::transaction(function () use ($rows, $unique, $duplicates, $contacts, $endOffset, $skipped) {
            $latest = $this->insertMessages($unique, $contacts);
            $ticketsFor = $this->bumpContacts($latest);
            $this->recordContacts($contacts, $ticketsFor);

            DB::table('chat_imports')->where('id', $this->import->id)->update([
                'processed_rows' => DB::raw('processed_rows + ' . (count($rows) + $skipped)),
                'imported_messages' => DB::raw('imported_messages + ' . count($unique)),
                'duplicate_rows' => DB::raw('duplicate_rows + ' . $duplicates),
                'cursor_offset' => $endOffset,
                'updated_at' => now(),
            ]);
        }, 3);

        return ['imported' => count($unique), 'duplicates' => $duplicates];
    }

    /**
     * @param array<int, array{row: ParsedRow, fingerprint: string}> $rows
     * @param array<string, array{id: int, created: bool}> $contacts
     * @return array{0: array<string, ParsedRow>, 1: int}
     */
    private function withoutDuplicates(array $rows, array $contacts): array
    {
        $unique = [];
        $duplicates = 0;

        foreach ($rows as $item) {
            if (isset($unique[$item['fingerprint']])) {
                $duplicates++;
                continue;
            }
            $unique[$item['fingerprint']] = $item['row'];
        }

        // كل البصمات بلا شرط deleted_at: الفهرس الفريد على wam_id يشمل المحذوف.
        foreach (array_chunk(array_keys($unique), 1000) as $chunk) {
            foreach (DB::table('chats')->whereIn('wam_id', $chunk)->pluck('wam_id') as $existing) {
                unset($unique[$existing]);
                $duplicates++;
            }
        }

        $duplicates += $this->dropLiveOverlaps($unique, $contacts);

        return [$unique, $duplicates];
    }

    /**
     * رسائل وصلت هذه المنصّة حيّةً وهي في الملف أيضاً.
     *
     * من ينقل رقمه يكون قد ربطه هنا قبل أن يُصدّر سجلّه من المنصّة القديمة،
     * فالأيام الأخيرة في الملف موجودة عندنا بمعرّفات واتساب حقيقية لا تطابق
     * البصمة. نعدّها الرسالة نفسها حين يتّفق العميل والاتجاه والنص ويتقارب
     * الوقت.
     *
     * @param array<string, ParsedRow> $unique
     * @param array<string, array{id: int, created: bool}> $contacts
     */
    private function dropLiveOverlaps(array &$unique, array $contacts): int
    {
        if ($unique === []) {
            return 0;
        }

        // جهة اتصال أنشأها الاستيراد الآن لا رسائل حيّة لها.
        $contactIds = [];
        $from = PHP_INT_MAX;
        $to = 0;
        foreach ($unique as $row) {
            $contact = $contacts[$row->phone];
            if ($contact['created']) {
                continue;
            }
            $contactIds[$contact['id']] = true;
            $at = strtotime($row->sentAtUtc . ' UTC');
            $from = min($from, $at);
            $to = max($to, $at);
        }

        if ($contactIds === []) {
            return 0;
        }

        $window = (int) config('chat_import.live_overlap_seconds', 60);

        $live = DB::table('chats')
            ->where('organization_id', $this->import->organization_id)
            ->whereIn('contact_id', array_keys($contactIds))
            ->whereNull('chat_import_id')
            ->whereNull('deleted_at')
            ->whereBetween('created_at', [gmdate('Y-m-d H:i:s', $from - $window), gmdate('Y-m-d H:i:s', $to + $window)])
            ->get(['contact_id', 'type', 'metadata', 'created_at']);

        if ($live->isEmpty()) {
            return 0;
        }

        $index = [];
        foreach ($live as $chat) {
            $body = self::bodyOf((string) $chat->metadata);
            if ($body !== null) {
                $index[$chat->contact_id . '|' . $chat->type . '|' . $body][] = strtotime($chat->created_at . ' UTC');
            }
        }

        $dropped = 0;
        foreach ($unique as $fingerprint => $row) {
            $contact = $contacts[$row->phone];
            $times = $index[$contact['id'] . '|' . $row->direction . '|' . $row->text] ?? null;

            if ($times === null || $contact['created']) {
                continue;
            }

            $at = strtotime($row->sentAtUtc . ' UTC');
            foreach ($times as $time) {
                if (abs($time - $at) <= $window) {
                    unset($unique[$fingerprint]);
                    $dropped++;
                    break;
                }
            }
        }

        return $dropped;
    }

    /**
     * @param array<string, ParsedRow> $unique
     * @param array<string, array{id: int, created: bool}> $contacts
     * @return array<int, string> معرّف جهة الاتصال → أحدث رسالة مستوردة لها في الدفعة
     */
    private function insertMessages(array $unique, array $contacts): array
    {
        if ($unique === []) {
            return [];
        }

        $importId = (int) $this->import->id;
        $chatRows = [];
        $latest = [];

        foreach ($unique as $fingerprint => $row) {
            $contactId = $contacts[$row->phone]['id'];

            $chatRows[] = [
                'uuid' => (string) Str::uuid(),
                'organization_id' => $this->import->organization_id,
                'wam_id' => $fingerprint,
                'contact_id' => $contactId,
                // NULL لا معرّف الموظّف: الرسالة لم يرسلها أحد من فريق المنشأة.
                'user_id' => null,
                'type' => $row->direction,
                'metadata' => JsonText::encode($this->metadata($row)),
                'media_id' => null,
                'status' => $row->direction === 'inbound' ? 'delivered' : 'sent',
                'is_read' => 1,
                'created_at' => $row->sentAtUtc,
                'chat_import_id' => $importId,
            ];

            if (!isset($latest[$contactId]) || $row->sentAtUtc > $latest[$contactId]) {
                $latest[$contactId] = $row->sentAtUtc;
            }
        }

        foreach (array_chunk($chatRows, self::INSERT_CHUNK) as $chunk) {
            DB::table('chats')->insert($chunk);
        }

        $ids = [];
        foreach (array_chunk(array_keys($unique), 1000) as $chunk) {
            foreach (DB::table('chats')->where('chat_import_id', $importId)->whereIn('wam_id', $chunk)->get(['id', 'wam_id']) as $chat) {
                $ids[$chat->wam_id] = (int) $chat->id;
            }
        }

        $logRows = [];
        foreach ($unique as $fingerprint => $row) {
            $logRows[] = [
                'contact_id' => $contacts[$row->phone]['id'],
                'entity_type' => 'chat',
                'entity_id' => $ids[$fingerprint],
                // المحادثة تُرتَّب بتاريخ السجلّ لا بتاريخ الرسالة.
                'created_at' => $row->sentAtUtc,
                'updated_at' => $row->sentAtUtc,
            ];
        }

        foreach (array_chunk($logRows, 500) as $chunk) {
            DB::table('chat_logs')->insert($chunk);
        }

        return $latest;
    }

    private function metadata(ParsedRow $row): array
    {
        return [
            'type' => 'text',
            'text' => ['body' => $row->text],
            'imported' => array_filter([
                'source' => 'csv',
                'original_type' => $row->originalType,
                'import' => $this->import->uuid,
            ]),
        ];
    }

    /**
     * يُقدّم latest_chat_created_at ولا يؤخّره، ويُنشئ تذكرة مغلقة لمن لا تذكرة له.
     *
     * @param array<int, string> $latest
     * @return array<int, int> جهات الاتصال التي أُنشئت لها تذكرة
     */
    private function bumpContacts(array $latest): array
    {
        if ($latest === []) {
            return [];
        }

        // ترتيب المعرّفات يجعل أقفال الصفوف بترتيب واحد مع أي معاملة أخرى
        // تحدّث الجهات نفسها، فلا ينشأ قفل متبادل مع webhook يكتب في اللحظة نفسها.
        ksort($latest);

        foreach (array_chunk($latest, 500, true) as $chunk) {
            $cases = '';
            $bindings = [];
            foreach ($chunk as $contactId => $at) {
                $cases .= ' WHEN ? THEN IF(latest_chat_created_at IS NULL OR latest_chat_created_at < ?, ?, latest_chat_created_at)';
                array_push($bindings, $contactId, $at, $at);
            }

            $ids = array_keys($chunk);
            DB::update(
                'UPDATE contacts SET latest_chat_created_at = CASE id' . $cases . ' ELSE latest_chat_created_at END'
                . ' WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')',
                array_merge($bindings, $ids)
            );
        }

        // تذكرة مغلقة لا مفتوحة: ChatService::ensureChatTicketsExist كان سيفتح
        // لكل جهة اتصال بلا تذكرة تذكرة «مفتوحة» — خمسون ألف محادثة قديمة في
        // صندوق الموظّفين. وفي المعاملة نفسها التي ظهرت فيها الجهة في القائمة،
        // فلا تسبقنا صفحة المحادثات إليها.
        $have = DB::table('chat_tickets')->whereIn('contact_id', array_keys($latest))->distinct()->pluck('contact_id')
            ->map(fn ($id) => (int) $id)->all();
        $missing = array_values(array_diff(array_keys($latest), $have));

        if ($missing === []) {
            return [];
        }

        $tickets = [];
        foreach ($missing as $contactId) {
            $tickets[] = [
                'contact_id' => $contactId,
                'assigned_to' => null,
                'status' => 'closed',
                'is_latest' => 1,
                'assigned_seen' => 1,
                'created_at' => $latest[$contactId],
                'updated_at' => $latest[$contactId],
            ];
        }

        DB::table('chat_tickets')->insertOrIgnore($tickets);

        return $missing;
    }

    /**
     * @param array<string, array{id: int, created: bool}> $contacts
     * @param array<int, int> $ticketsFor
     */
    private function recordContacts(array $contacts, array $ticketsFor): void
    {
        $importId = (int) $this->import->id;
        $rows = [];
        $created = [];

        foreach ($contacts as $contact) {
            $rows[] = ['chat_import_id' => $importId, 'contact_id' => $contact['id']];
            if ($contact['created']) {
                $created[] = $contact['id'];
            }
        }

        DB::table('chat_import_contacts')->insertOrIgnore($rows);

        if ($created !== []) {
            DB::table('chat_import_contacts')->where('chat_import_id', $importId)
                ->whereIn('contact_id', $created)->update(['contact_created' => 1]);
        }

        if ($ticketsFor !== []) {
            DB::table('chat_import_contacts')->where('chat_import_id', $importId)
                ->whereIn('contact_id', $ticketsFor)->update(['ticket_created' => 1]);
        }
    }

    /** نص الرسالة الحيّة للمقارنة: النص، أو تعليق الوسائط. */
    private static function bodyOf(string $metadata): ?string
    {
        $data = json_decode($metadata, true);
        if (!is_array($data)) {
            return null;
        }

        $body = $data['text']['body']
            ?? $data['image']['caption']
            ?? $data['video']['caption']
            ?? $data['document']['caption']
            ?? null;

        if (!is_string($body)) {
            return null;
        }

        $body = trim(str_replace(["\r\n", "\r"], "\n", $body));

        return $body === '' ? null : $body;
    }
}
