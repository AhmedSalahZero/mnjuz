<?php

namespace App\Services\ChatImport;

/**
 * قارئ CSV يُستأنف من موضع في الملف.
 *
 * ثلاثة أمور تحكم تصميمه:
 *
 * ١. الهروب فارغ (RFC 4180). fgetcsv يعدّ «\» هروباً افتراضياً، فنصٌّ
 *    ينتهي بشرطة مائلة قبل علامة التنصيص يُبتلع معه الفاصل وسطرٌ من الملف
 *    التالي. هذا وقع فعلاً على ملف حقيقي: ظهرت جُمل من نصوص الرسائل في عمود
 *    الرقم (51,410 رقماً بدل 51,402).
 *
 * ٢. الموضع بالبايت لا برقم السطر. النص متعدّد الأسطر، فرقم السطر لا يدلّ
 *    على السجلّ؛ أمّا ftell قبل كل سجلّ فيعيد القراءة منه بالضبط.
 *
 * ٣. الفاصل يُكتشف من سطر العناوين: Excel بلغات أوروبية يحفظ «;».
 */
final class CsvReader
{
    public const DELIMITERS = [',', ';', "\t"];

    private const BOM = "\xEF\xBB\xBF";

    /** @var resource */
    private $handle;

    private string $delimiter;

    /** @var array<int, string> */
    private array $header;

    private int $dataOffset;

    private int $size;

    /**
     * @param resource $handle
     * @param array<int, string> $header
     */
    private function __construct($handle, string $delimiter, array $header, int $dataOffset, int $size)
    {
        $this->handle = $handle;
        $this->delimiter = $delimiter;
        $this->header = $header;
        $this->dataOffset = $dataOffset;
        $this->size = $size;
    }

    /** @throws ChatImportException */
    public static function open(string $absolutePath): self
    {
        if (!is_file($absolutePath) || !is_readable($absolutePath)) {
            throw new ChatImportException(__('The uploaded file could not be found. Please upload it again.'));
        }

        $size = (int) filesize($absolutePath);
        if ($size === 0) {
            throw new ChatImportException(__('The file is empty.'));
        }

        $handle = fopen($absolutePath, 'rb');
        if ($handle === false) {
            throw new ChatImportException(__('The uploaded file could not be found. Please upload it again.'));
        }

        $start = fread($handle, 3) === self::BOM ? 3 : 0;

        if (!self::isUtf8(self::sample($handle, $start))) {
            fclose($handle);
            throw new ChatImportException(__('The file is not UTF-8 encoded. In Excel choose "Save as" then "CSV UTF-8 (Comma delimited)".'));
        }

        fseek($handle, $start);
        $firstLine = (string) fgets($handle);
        $delimiter = self::detectDelimiter($firstLine);

        fseek($handle, $start);
        $header = fgetcsv($handle, null, $delimiter, '"', '');

        if ($header === false || $header === [null]) {
            fclose($handle);
            throw new ChatImportException(__('The file has no header row.'));
        }

        $header = array_map(fn ($value) => trim((string) $value), $header);

        return new self($handle, $delimiter, $header, (int) ftell($handle), $size);
    }

    /** @return array<int, string> */
    public function header(): array
    {
        return $this->header;
    }

    public function delimiter(): string
    {
        return $this->delimiter;
    }

    /** موضع أوّل سجلّ بيانات بعد العناوين. */
    public function dataOffset(): int
    {
        return $this->dataOffset;
    }

    public function size(): int
    {
        return $this->size;
    }

    /** الموضع الحاليّ: بداية السجلّ التالي. */
    public function offset(): int
    {
        return (int) ftell($this->handle);
    }

    /** الانتقال إلى بداية سجلّ — موضعٌ قبل البيانات يُعاد إلى أوّلها. */
    public function seek(int $offset): void
    {
        fseek($this->handle, max($offset, $this->dataOffset));
    }

    /**
     * السجلّ التالي، أو null عند نهاية الملف. الأسطر الفارغة تُتخطّى.
     *
     * @return array<int, string|null>|null
     */
    public function read(): ?array
    {
        while (true) {
            $record = fgetcsv($this->handle, null, $this->delimiter, '"', '');

            if ($record === false) {
                return null;
            }

            if ($record === [null] || (count($record) === 1 && trim((string) $record[0]) === '')) {
                continue;
            }

            return $record;
        }
    }

    public function close(): void
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    /**
     * الفاصل الأكثر ظهوراً خارج علامات التنصيص في سطر العناوين.
     * عند التعادل تفوز الفاصلة.
     */
    public static function detectDelimiter(string $line): string
    {
        $counts = array_fill_keys(self::DELIMITERS, 0);
        $inQuotes = false;
        $length = strlen($line);

        for ($i = 0; $i < $length; $i++) {
            $char = $line[$i];

            if ($char === '"') {
                $inQuotes = !$inQuotes;
                continue;
            }

            if (!$inQuotes && isset($counts[$char])) {
                $counts[$char]++;
            }
        }

        $best = ',';
        foreach ($counts as $delimiter => $count) {
            if ($count > $counts[$best]) {
                $best = $delimiter;
            }
        }

        return $best;
    }

    /**
     * عيّنة من أوّل الملف لفحص الترميز، بلا حرف مقطوع في آخرها.
     *
     * @param resource $handle
     */
    private static function sample($handle, int $start): string
    {
        fseek($handle, $start);
        $sample = (string) fread($handle, 256 * 1024);

        // حرف عربي من بايتين قد ينقطع عند حدّ العيّنة فيبدو الملف كلّه تالفاً.
        // نحذف ما بعد آخر بايت يبدأ حرفاً (أو بايت ASCII).
        for ($i = strlen($sample) - 1, $stop = max(0, strlen($sample) - 4); $i >= $stop; $i--) {
            $byte = ord($sample[$i]);
            if ($byte < 0x80) {
                break;
            }
            if ($byte >= 0xC0) {
                $sample = substr($sample, 0, $i);
                break;
            }
        }

        return $sample;
    }

    private static function isUtf8(string $value): bool
    {
        return mb_check_encoding($value, 'UTF-8');
    }
}
