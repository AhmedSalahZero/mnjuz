<?php

namespace Tests\Unit\ChatImport;

use App\Services\ChatImport\ChatImportException;
use App\Services\ChatImport\CsvReader;
use Tests\TestCase;

/**
 * القارئ يجب أن يقرأ ما يُصدّره الناس فعلاً: BOM، وفواصل مختلفة، ونصوص
 * متعدّدة الأسطر، ويُستأنف من منتصف الملف دون أن يفقد سجلّاً أو يكرّره.
 */
class CsvReaderTest extends TestCase
{
    /** @var array<int, string> */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    private function file(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'chat-import-');
        file_put_contents($path, $contents);
        $this->paths[] = $path;

        return $path;
    }

    /** @return array<int, array<int, string|null>> */
    private function all(CsvReader $reader): array
    {
        $records = [];
        while (($record = $reader->read()) !== null) {
            $records[] = $record;
        }

        return $records;
    }

    public function test_it_strips_the_bom_from_the_first_header(): void
    {
        $reader = CsvReader::open($this->file("\xEF\xBB\xBFphone,text\n966501234567,hi\n"));

        $this->assertSame(['phone', 'text'], $reader->header());
        $this->assertSame([['966501234567', 'hi']], $this->all($reader));
    }

    public function test_it_detects_the_delimiter_from_the_header(): void
    {
        $this->assertSame(';', CsvReader::open($this->file("phone;text\n1;a\n"))->delimiter());
        $this->assertSame("\t", CsvReader::open($this->file("phone\ttext\n1\ta\n"))->delimiter());
        $this->assertSame(',', CsvReader::open($this->file("phone,text\n1,a\n"))->delimiter());
        // فاصلة داخل علامات التنصيص لا تُحسب.
        $this->assertSame(';', CsvReader::detectDelimiter("\"a,b,c\";phone;text\n"));
    }

    public function test_multi_line_quoted_text_is_one_record(): void
    {
        $reader = CsvReader::open($this->file("phone,text\n1,\"line one\n\nline \"\"three\"\"\"\n2,next\n"));

        $this->assertSame([['1', "line one\n\nline \"three\""], ['2', 'next']], $this->all($reader));
    }

    public function test_a_backslash_before_a_quote_does_not_swallow_the_next_record(): void
    {
        // بالهروب الافتراضي «\» كان هذا السجلّ يبتلع ما بعده: ظهر نص رسالة في
        // عمود الرقم في ملف حقيقي (51,410 رقماً بدل 51,402).
        $reader = CsvReader::open($this->file("phone,text\n1,\"path C:\\\\\"\n2,\"second\"\n3,third\n"));

        $records = $this->all($reader);

        $this->assertCount(3, $records);
        $this->assertSame(['1', '2', '3'], array_column($records, 0));
        $this->assertSame('path C:\\\\', $records[0][1]);
    }

    public function test_blank_lines_are_skipped(): void
    {
        $reader = CsvReader::open($this->file("phone,text\n\n1,a\n\n\n2,b\n"));

        $this->assertSame([['1', 'a'], ['2', 'b']], $this->all($reader));
    }

    public function test_windows_line_endings_are_handled(): void
    {
        $reader = CsvReader::open($this->file("phone,text\r\n1,a\r\n2,\"b\r\nc\"\r\n"));

        $this->assertSame([['1', 'a'], ['2', "b\r\nc"]], $this->all($reader));
    }

    public function test_seeking_to_a_saved_offset_resumes_exactly(): void
    {
        $reader = CsvReader::open($this->file("phone,text\n1,\"multi\nline\"\n2,b\n3,c\n"));

        $reader->read();
        $offset = $reader->offset();
        $rest = $this->all($reader);

        $resumed = CsvReader::open($this->paths[0]);
        $resumed->seek($offset);

        $this->assertSame([['2', 'b'], ['3', 'c']], $rest);
        $this->assertSame($rest, $this->all($resumed));

        // موضع قبل البيانات (0) يعني أوّلها لا سطر العناوين.
        $fromStart = CsvReader::open($this->paths[0]);
        $fromStart->seek(0);
        $this->assertSame(['1', "multi\nline"], $fromStart->read());
    }

    public function test_a_non_utf8_file_is_rejected(): void
    {
        // «أحمد» بترميز Windows-1256 — ما يحفظه Excel العربي بـ «CSV» العادي.
        $this->expectException(ChatImportException::class);
        $this->expectExceptionMessage('UTF-8');

        CsvReader::open($this->file("phone,name\n966501234567,\xC3\xCD\xE3\xCF\n"));
    }

    public function test_an_arabic_character_cut_at_the_sample_edge_is_not_a_false_alarm(): void
    {
        // العيّنة 256 ك.ب؛ نجعل حرفاً عربياً (بايتان) ينقطع عند حدّها.
        $prefix = "phone,text\n1,";
        $padding = str_repeat('a', 256 * 1024 - strlen($prefix) - 1);
        $reader = CsvReader::open($this->file($prefix . $padding . "ع\n"));

        $this->assertSame(['phone', 'text'], $reader->header());
    }

    public function test_an_empty_file_is_rejected(): void
    {
        $this->expectException(ChatImportException::class);

        CsvReader::open($this->file(''));
    }

    public function test_a_missing_file_is_rejected(): void
    {
        $this->expectException(ChatImportException::class);

        CsvReader::open('/nonexistent/chat-import.csv');
    }
}
