<?php

namespace App\Services\Chat;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * تجميع ملف مرفوع على قطع.
 *
 * Cloudflare يقطع أي طلب تجاوز ١٢٥ ثانية — لا أي حجم بعينه. وبسرعة رفع
 * ٠٫٣ ميغابايت/ثانية يعني ذلك أن أي ملف فوق ~٤٠ ميغابايت يفشل مهما أُرسل
 * وحده، ويُسجَّل 499 على الخادم و524 عند العميل: قطعٌ بلا رسالة، يتجمّد معه
 * شريط التقدّم في منتصفه.
 *
 * الحلّ ألّا يطول أي طلب: قطعة صغيرة في كل طلب، والزمن يُقاس بالقطعة لا
 * بالملف. فيعمل الرفع مهما بطؤت الشبكة ومهما كبر الملف.
 *
 * والمسارات تُبنى من معرّف الرفع لا من اسم الملف: الاسم يأتي من المتصفّح،
 * وبناء مسار منه يفتح الباب للكتابة خارج المجلّد المقصود.
 */
class ChunkedUploadService
{
    /** جذر القطع المؤقّتة على قرص local. */
    private const ROOT = 'temp/chunked-uploads';

    /** أقصى عدد قطع للملف الواحد — حارس ضدّ رفع لا ينتهي. */
    public const MAX_CHUNKS = 4000;

    /** عمر القطع اليتيمة قبل تنظيفها بالساعات. */
    public const STALE_HOURS = 24;

    /**
     * مجلّد الرفع، مقيّداً بالمنظّمة والمستخدم.
     *
     * الحصر ضروري: معرّف الرفع يأتي من العميل، فبدونه يستطيع مستخدم أن يكتب
     * في رفع مستخدم آخر بمجرّد تخمين معرّفه.
     */
    public static function directoryFor(int $organizationId, int $userId, string $uploadId): string
    {
        return self::ROOT . '/' . $organizationId . '/' . $userId . '/' . self::sanitizeId($uploadId);
    }

    /** معرّف الرفع: حروف وأرقام وشرطات فقط — لا مسارات ولا نقاط. */
    public static function sanitizeId(string $uploadId): string
    {
        return substr(preg_replace('/[^A-Za-z0-9\-]/', '', $uploadId) ?: 'invalid', 0, 64);
    }

    public static function storeChunk(string $directory, int $index, UploadedFile $chunk): void
    {
        Storage::disk('local')->putFileAs($directory, $chunk, self::chunkName($index));
    }

    /** هل وصلت القطع كلّها؟ */
    public static function hasAllChunks(string $directory, int $total): bool
    {
        return self::receivedCount($directory, $total) === $total;
    }

    public static function receivedCount(string $directory, int $total): int
    {
        $received = 0;

        for ($index = 0; $index < $total; $index++) {
            if (Storage::disk('local')->exists($directory . '/' . self::chunkName($index))) {
                $received++;
            }
        }

        return $received;
    }

    /**
     * دمج القطع في ملف واحد وإرجاع مساره النسبي على قرص local.
     *
     * الدمج بالتدفّق لا بالقراءة الكاملة: ملفٌ من مئة ميغابايت يُقرأ كلّه إلى
     * الذاكرة كان سيُسقط العملية عند أوّل ملف كبير.
     *
     * @return string|null المسار النسبي، أو null إن نقصت قطعة
     */
    public static function assemble(string $directory, int $total, string $extension): ?string
    {
        if (!self::hasAllChunks($directory, $total)) {
            return null;
        }

        $disk = Storage::disk('local');
        $target = 'temp/send-media/' . basename($directory) . ($extension !== '' ? '.' . $extension : '');

        $disk->put($target, '');
        $handle = fopen($disk->path($target), 'wb');

        if ($handle === false) {
            return null;
        }

        try {
            for ($index = 0; $index < $total; $index++) {
                $source = fopen($disk->path($directory . '/' . self::chunkName($index)), 'rb');

                if ($source === false) {
                    return null;
                }

                stream_copy_to_stream($source, $handle);
                fclose($source);
            }
        } finally {
            fclose($handle);
        }

        self::discard($directory);

        return $target;
    }

    /** حذف قطع رفعٍ لم يكتمل. */
    public static function discard(string $directory): void
    {
        Storage::disk('local')->deleteDirectory($directory);
    }

    /**
     * تنظيف الرفعات المتروكة.
     *
     * القطع تبقى على القرص حين يُغلق المستخدم صفحته في منتصف الرفع — ولا شيء
     * يحذفها، فتتراكم حتى يمتلئ القرص ويسقط النظام كلّه لا الرفع وحده.
     *
     * @return int عدد المجلّدات المحذوفة
     */
    public static function pruneStale(int $olderThanHours = self::STALE_HOURS): int
    {
        $disk = Storage::disk('local');

        if (!$disk->exists(self::ROOT)) {
            return 0;
        }

        $threshold = now()->subHours(max(1, $olderThanHours))->getTimestamp();
        $removed = 0;

        // البنية: ROOT/{org}/{user}/{uploadId}
        foreach ($disk->directories(self::ROOT) as $organizationDir) {
            foreach ($disk->directories($organizationDir) as $userDir) {
                foreach ($disk->directories($userDir) as $uploadDir) {
                    if ($disk->lastModified($uploadDir) < $threshold) {
                        $disk->deleteDirectory($uploadDir);
                        $removed++;
                    }
                }
            }
        }

        return $removed;
    }

    private static function chunkName(int $index): string
    {
        // الحشو للقراءة لا للترتيب: الدمج يمرّ على الفهارس عدداً لا على قائمة
        // المجلّد، فالترتيب مضمون بلا حشو. وإنما يجعل تصفّح المجلّد وقت
        // التشخيص مفهوماً.
        return sprintf('%06d.part', $index);
    }
}
