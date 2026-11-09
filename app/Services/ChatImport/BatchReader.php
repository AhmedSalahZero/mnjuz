<?php

namespace App\Services\ChatImport;

/**
 * يقرأ الملف دفعاتٍ للكتابة، ويعرف لكل دفعة موضع ما بعدها.
 *
 * الدفعة تقف عند حدّ الصفوف أو البايتات، لكنها لا تقطع أبداً سلسلة صفوف
 * متتالية لنفس الرقم في نفس الثانية: ترتيب الرسالة في سلسلتها جزء من
 * بصمتها، وشريحة تُستأنف من منتصف السلسلة كانت ستعدّ من الصفر فتخرج بصمات
 * مختلفة — رسائل مكرّرة أو مفقودة.
 */
final class BatchReader
{
    /** @var array{0: array<int, string|null>, 1: int, 2: ParsedRow}|null السجلّ الذي قُرئ ولم يدخل دفعة بعد */
    private ?array $pending = null;

    private RunTracker $runs;

    public function __construct(
        private readonly CsvReader $reader,
        private readonly HeaderMap $map,
        private readonly RowParser $parser,
        private readonly int $organizationId,
        private int $nextRowNumber,
    ) {
        $this->runs = new RunTracker();
    }

    /**
     * @return array{rows: array<int, array{row: ParsedRow, fingerprint: string}>, end_offset: int, skipped: int}|null
     *
     * @throws ChatImportException صفّ لم يُقرأ — الفحص كان يجب أن يرفضه.
     */
    public function next(int $maxRows, int $maxBytes): ?array
    {
        $rows = [];
        $skipped = 0;
        $bytes = 0;
        $lastRunKey = null;
        $endOffset = $this->reader->offset();

        while (true) {
            if ($this->pending !== null) {
                [$record, $start, $parsed] = $this->pending;
                $this->pending = null;
            } else {
                $start = $this->reader->offset();
                $record = $this->reader->read();

                if ($record === null) {
                    $endOffset = $this->reader->offset();
                    break;
                }

                $parsed = $this->parse($record);

                if ($parsed === null) {
                    $this->nextRowNumber++;
                    $skipped++;
                    continue;
                }
            }

            $full = count($rows) >= $maxRows || $bytes >= $maxBytes;

            if ($full && $parsed->runKey() !== $lastRunKey) {
                $this->pending = [$record, $start, $parsed];
                $endOffset = $start;
                break;
            }

            $this->nextRowNumber++;
            $rows[] = [
                'row' => $parsed,
                'fingerprint' => MessageFingerprint::make($this->organizationId, $parsed, $this->runs->occurrence($parsed)),
            ];
            $bytes += strlen($parsed->text);
            $lastRunKey = $parsed->runKey();
        }

        return $rows === [] ? null : ['rows' => $rows, 'end_offset' => $endOffset, 'skipped' => $skipped];
    }

    /**
     * @param array<int, string|null> $record
     * @return ParsedRow|null null لصفّ رقمه خاطئ: تخطّاه الفحص وكتبه في التقرير.
     */
    private function parse(array $record): ?ParsedRow
    {
        // السجلّ يُقرأ قبل أن يُضاف، فرقمه هو رقم الصفّ التالي.
        $row = $this->nextRowNumber;

        if (count($record) < $this->map->requiredWidth()) {
            throw new ChatImportException(__('Row :row could not be read: :error', [
                'row' => $row,
                'error' => __('The row has fewer columns than the header.'),
            ]));
        }

        $parsed = $this->parser->parse($this->map->extract($record), $row);

        if (is_array($parsed)) {
            if (RowParser::skipReason($parsed) !== null) {
                return null;
            }

            throw new ChatImportException(__('Row :row could not be read: :error', [
                'row' => $row,
                'error' => $parsed[0]['message'] ?? '',
            ]));
        }

        return $parsed;
    }
}
