<?php

namespace App\Services\ChatImport;

use App\Models\ChatImport;
use App\Models\Organization;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * ما يتشاركه المتحكّم والوظائف حول الاستيراد.
 */
final class ChatImportService
{
    /** مجلّد ملفات الاستيراد على قرص local. */
    public static function directory(int $organizationId): string
    {
        return 'chat-imports/' . $organizationId;
    }

    /**
     * فحص سريع لحظة اكتمال الرفع: ترميز وأعمدة.
     *
     * لا ينتظر المستخدم الطابور ليعرف أنه رفع الملف الخطأ — ملف بلا عمود
     * «phone» يُرفض في الطلب نفسه.
     *
     * @return array<int, string> الأعمدة التي ستُتجاهل
     * @throws ChatImportException
     */
    public static function inspectHeader(string $absolutePath): array
    {
        $reader = CsvReader::open($absolutePath);
        $map = new HeaderMap($reader->header());
        $reader->close();

        if (!$map->isValid()) {
            throw new ChatImportException(implode(' ', $map->errors()));
        }

        return $map->ignored();
    }

    /** المنطقة الزمنية للمنشأة — تُقرأ مرّة وقت الرفع وتُحفظ مع الاستيراد. */
    public static function timezoneFor(int $organizationId): string
    {
        $metadata = json_decode((string) Organization::whereKey($organizationId)->value('metadata'), true);
        $timezone = is_array($metadata) ? ($metadata['timezone'] ?? null) : null;

        if (!is_string($timezone) || $timezone === '') {
            return 'UTC';
        }

        try {
            new \DateTimeZone($timezone);

            return $timezone;
        } catch (Throwable) {
            return 'UTC';
        }
    }

    /** عدّادات جهات الاتصال من سجلّها: الجهة الواحدة تظهر في دفعات كثيرة. */
    public static function refreshContactCounters(ChatImport $import): void
    {
        $counts = DB::table('chat_import_contacts')
            ->where('chat_import_id', $import->id)
            ->selectRaw('SUM(contact_created = 1) AS created, SUM(contact_created = 0) AS matched')
            ->first();

        $import->forceFill([
            'contacts_created' => (int) ($counts->created ?? 0),
            'contacts_matched' => (int) ($counts->matched ?? 0),
        ])->save();
    }

    public static function deleteUploadedFile(ChatImport $import): void
    {
        if ($import->file_path) {
            Storage::disk('local')->delete($import->file_path);
            $import->forceFill(['file_path' => null])->save();
        }
    }

    public static function fileExists(ChatImport $import): bool
    {
        return $import->file_path !== null && Storage::disk('local')->exists($import->file_path);
    }

    /** سجلّ النشاط من الطابور: لا مستخدم مسجّل هناك، فيُمرَّر صراحةً. */
    public static function log(ChatImport $import, string $event, array $properties = []): void
    {
        $user = $import->user;
        $userName = $user ? (trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: $user->email) : null;

        ActivityLogger::log(
            $event,
            $import->original_name,
            'chat_import',
            $import->id,
            array_merge(['uuid' => $import->uuid], $properties),
            (int) $import->organization_id,
            (int) $import->user_id,
            $userName
        );
    }
}
