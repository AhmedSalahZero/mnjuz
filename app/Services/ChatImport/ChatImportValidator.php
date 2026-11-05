<?php

namespace App\Services\ChatImport;

use App\Models\ChatImport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * فحص الملف كلّه قبل أن يُكتب منه شيء.
 *
 * الاستيراد صارم: صفّ واحد خاطئ يرفض الملف، فلا يجد المستخدم نصف محادثاته
 * مستورداً ونصفها مفقوداً. لذلك يمرّ الفحص على كل صفّ ويجمع كل خطأ في تقرير
 * واحد يُصلَح الملف منه دفعةً واحدة.
 *
 * ويحسب ما سيُضاف فعلاً — رسائل جديدة بعد استبعاد المكرّر، وجهات اتصال
 * جديدة — ليُقاس بحدود الباقة قبل البدء لا في منتصفه.
 */
final class ChatImportValidator
{
    private const PROGRESS_EVERY = 2000;

    private const DB_CHECK_CHUNK = 1000;

    /** سقف أسطر تقرير الأخطاء: ملف خاطئ كلّه لا يُنتج تقريراً بحجمه. */
    public const MAX_ERRORS_IN_FILE = 50000;

    /** @var resource|null */
    private $errorsHandle = null;

    private ValidationReport $report;

    public function __construct(private readonly ChatImport $import)
    {
    }

    /**
     * @param callable(int): bool $tick يُستدعى بالموضع الحاليّ بالبايت؛ يُرجع false لإيقاف الفحص (إلغاء).
     */
    public function run(callable $tick): ValidationReport
    {
        $this->report = new ValidationReport();

        try {
            $this->scan($tick);
        } finally {
            if (is_resource($this->errorsHandle)) {
                fclose($this->errorsHandle);
            }
        }

        return $this->report;
    }

    private function scan(callable $tick): void
    {
        $report = $this->report;
        $organizationId = (int) $this->import->organization_id;

        try {
            $reader = CsvReader::open(Storage::disk('local')->path((string) $this->import->file_path));
        } catch (ChatImportException $e) {
            $this->fileError($e->getMessage());

            return;
        }

        $map = new HeaderMap($reader->header());
        $report->ignoredColumns = $map->ignored();

        if (!$map->isValid()) {
            foreach ($map->errors() as $message) {
                $this->fileError($message);
            }

            return;
        }

        $parser = new RowParser(
            (string) $this->import->timezone,
            $this->import->default_country,
            (int) config('chat_import.max_text', 65535)
        );
        $runs = new RunTracker();
        $maxRows = (int) config('chat_import.max_rows', 1_000_000);
        $width = $map->requiredWidth();

        /** @var array<string, true> $seen */
        $seen = [];
        $pending = [];
        /** @var array<string, true> $phones */
        $phones = [];
        $rowNumber = 1;

        while (($record = $reader->read()) !== null) {
            $rowNumber++;
            $report->totalRows++;

            if ($report->totalRows > $maxRows) {
                $report->totalRows--;
                $this->fileError(__('The file has more than :max messages. Split it into smaller files.', ['max' => number_format($maxRows)]));
                break;
            }

            if ($report->totalRows % self::PROGRESS_EVERY === 0 && !$tick($reader->offset())) {
                $report->cancelled = true;

                return;
            }

            if (count($record) < $width) {
                $this->rowError(['row' => $rowNumber, 'column' => '-', 'value' => '', 'message' => __('The row has fewer columns than the header.')]);
                continue;
            }

            $parsed = $parser->parse($map->extract($record), $rowNumber);

            if (is_array($parsed)) {
                foreach ($parsed as $error) {
                    $this->rowError($error);
                }
                continue;
            }

            // الملف مرفوض بعد أوّل خطأ: نكمل المرور لجمع الأخطاء، ونكفّ عن
            // حساب المكرّر وجهات الاتصال — لن يُستعمل.
            if ($report->errorCount > 0) {
                continue;
            }

            $fingerprint = MessageFingerprint::make($organizationId, $parsed, $runs->occurrence($parsed));
            $key = MessageFingerprint::binary($fingerprint);

            if (isset($seen[$key])) {
                $report->inFileDuplicates++;
            } else {
                $seen[$key] = true;
                $pending[] = $fingerprint;

                if (count($pending) >= self::DB_CHECK_CHUNK) {
                    $report->existingDuplicates += $this->countExisting($pending);
                    $pending = [];
                }
            }

            $phones[$parsed->phone] = true;
        }

        $tick($reader->size());

        if ($report->totalRows === 0 && $report->fileError === null) {
            $this->fileError(__('The file has no messages.'));

            return;
        }

        if (!$report->isValid()) {
            return;
        }

        $report->existingDuplicates += $this->countExisting($pending);

        $phoneList = array_keys($phones);
        unset($seen, $phones);

        $existing = (new ContactResolver($organizationId))->lookup($phoneList);
        $report->newContacts = count($phoneList) - count($existing);

        $limitError = ChatImportLimits::check($organizationId, $report->newContacts, $report->newMessages());
        if ($limitError !== null) {
            $this->fileError($limitError);
        }
    }

    /** @param array<int, string> $fingerprints */
    private function countExisting(array $fingerprints): int
    {
        if ($fingerprints === []) {
            return 0;
        }

        // بلا شرط deleted_at: الفهرس الفريد chats_wam_id_unique على العمود نفسه،
        // فرسالة حذفها المستخدم تمنع إعادة إدراجها — وهي على أي حال حُذفت عمداً.
        return DB::table('chats')->whereIn('wam_id', $fingerprints)->count();
    }

    private function fileError(string $message): void
    {
        $this->report->fileError = $this->report->fileError === null
            ? $message
            : $this->report->fileError . ' ' . $message;

        $this->rowError(['row' => '-', 'column' => '-', 'value' => '', 'message' => $message]);
    }

    /** @param array{row: int|string, column: string, value: string, message: string} $error */
    private function rowError(array $error): void
    {
        $report = $this->report;
        $report->errorCount++;

        if (count($report->errorsPreview) < (int) config('chat_import.errors_preview', 200)) {
            $report->errorsPreview[] = $error;
        }

        if ($report->errorCount > self::MAX_ERRORS_IN_FILE) {
            return;
        }

        $handle = $this->errorsHandle();
        if ($handle !== null) {
            fputcsv($handle, [$error['row'], $error['column'], self::safeCell($error['value']), $error['message']], ',', '"', '');
        }
    }

    /**
     * القيمة كما جاءت من الملف تُفتح في Excel: «=HYPERLINK(…)» تُنفَّذ صيغةً.
     * نُبطلها بفاصلة عليا، ونترك الأرقام كما هي («+966…» و«-5» ليست صيغاً).
     */
    public static function safeCell(string $value): string
    {
        return preg_match('/^(?:[=@\t\r]|[+\-](?![\d\s]))/', $value) ? "'" . $value : $value;
    }

    /** @return resource|null */
    private function errorsHandle()
    {
        if ($this->errorsHandle !== null) {
            return $this->errorsHandle;
        }

        $relative = 'chat-imports/' . $this->import->organization_id . '/' . $this->import->uuid . '-errors.csv';
        $disk = Storage::disk('local');
        $disk->put($relative, '');

        $handle = fopen($disk->path($relative), 'wb');
        if ($handle === false) {
            return null;
        }

        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, [__('Row'), __('Column'), __('Value'), __('Error')], ',', '"', '');

        $this->errorsHandle = $handle;
        $this->report->errorsFilePath = $relative;

        return $handle;
    }
}
