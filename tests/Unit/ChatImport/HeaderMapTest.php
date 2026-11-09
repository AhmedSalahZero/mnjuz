<?php

namespace Tests\Unit\ChatImport;

use App\Services\ChatImport\HeaderMap;
use Tests\TestCase;

class HeaderMapTest extends TestCase
{
    public function test_the_template_header_maps_every_field(): void
    {
        $map = new HeaderMap(['phone', 'name', 'sent_at', 'direction', 'text']);

        $this->assertTrue($map->isValid());
        $this->assertSame(0, $map->index('phone'));
        $this->assertSame(4, $map->index('text'));
        $this->assertSame([], $map->ignored());
    }

    public function test_common_export_names_and_arabic_headers_are_accepted(): void
    {
        $english = new HeaderMap(['Mobile Number', 'Contact Name', 'Timestamp', 'Direction', 'Message']);
        $this->assertTrue($english->isValid(), implode(' ', $english->errors()));

        $arabic = new HeaderMap(['رقم الجوال', 'الاسم', 'التاريخ', 'الاتجاه', 'الرسالة']);
        $this->assertTrue($arabic->isValid(), implode(' ', $arabic->errors()));
        $this->assertSame(4, $arabic->index('text'));
    }

    public function test_name_is_optional_and_extra_columns_are_ignored(): void
    {
        $map = new HeaderMap(['id', 'phone', 'sent_at', 'direction', 'text', 'agent']);

        $this->assertTrue($map->isValid());
        $this->assertNull($map->index('name'));
        $this->assertSame(['id', 'agent'], $map->ignored());
        $this->assertSame(
            ['phone' => '9665', 'sent_at' => 'd', 'direction' => 'in', 'text' => 't', 'name' => null],
            $map->extract(['7', '9665', 'd', 'in', 't', 'x'])
        );
    }

    public function test_a_type_column_is_not_taken_for_the_direction(): void
    {
        // منصّات كثيرة تضع في «type» نوع الرسالة (text/image).
        $map = new HeaderMap(['phone', 'sent_at', 'type', 'text']);

        $this->assertFalse($map->isValid());
        $this->assertSame(['type'], $map->ignored());
    }

    public function test_missing_and_duplicate_columns_are_reported(): void
    {
        $map = new HeaderMap(['phone', 'mobile', 'text']);

        $this->assertFalse($map->isValid());
        $errors = implode(' | ', $map->errors());
        $this->assertStringContainsString('"mobile" appears more than once', $errors);
        $this->assertStringContainsString('"sent_at" is missing', $errors);
        $this->assertStringContainsString('"direction" is missing', $errors);
    }

    public function test_error_report_cells_cannot_run_as_excel_formulas(): void
    {
        $safe = fn (string $value) => \App\Services\ChatImport\ChatImportValidator::safeCell($value);

        $this->assertSame("'=HYPERLINK(\"x\")", $safe('=HYPERLINK("x")'));
        $this->assertSame("'@SUM(A1)", $safe('@SUM(A1)'));
        $this->assertSame("'+cmd", $safe('+cmd'));
        $this->assertSame('+966501234567', $safe('+966501234567'), 'phone numbers stay readable');
        $this->assertSame('-5', $safe('-5'));
        $this->assertSame('hello', $safe('hello'));
    }

    public function test_required_width_covers_the_furthest_mapped_column(): void
    {
        $this->assertSame(6, (new HeaderMap(['x', 'phone', 'sent_at', 'direction', 'y', 'text']))->requiredWidth());
    }
}
