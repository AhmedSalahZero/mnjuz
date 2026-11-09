<?php

namespace Tests\Unit\ChatImport;

use App\Services\ChatImport\ParsedRow;
use App\Services\ChatImport\RowParser;
use DateTimeImmutable;
use DateTimeZone;
use Tests\TestCase;

class RowParserTest extends TestCase
{
    private function parser(?string $country = null, int $maxText = 65535): RowParser
    {
        return new RowParser('Asia/Riyadh', $country, $maxText, new DateTimeImmutable('2026-11-04 12:00:00', new DateTimeZone('UTC')));
    }

    private function row(array $overrides = []): array
    {
        return array_merge([
            'phone' => '966501234567',
            'name' => 'Sara',
            'sent_at' => '2026-05-23T19:29:38+03:00',
            'direction' => 'in',
            'text' => 'hello',
        ], $overrides);
    }

    private function parsed(array $overrides = [], ?string $country = null): ParsedRow
    {
        $result = $this->parser($country)->parse($this->row($overrides), 2);
        $this->assertInstanceOf(ParsedRow::class, $result, json_encode($result, JSON_UNESCAPED_UNICODE));

        return $result;
    }

    /** @return array<int, string> */
    private function errorColumns(array $overrides, ?string $country = null): array
    {
        $result = $this->parser($country)->parse($this->row($overrides), 7);
        $this->assertIsArray($result, 'expected the row to be rejected');

        return array_column($result, 'column');
    }

    public function test_a_valid_row_is_normalised(): void
    {
        $row = $this->parsed();

        $this->assertSame(2, $row->row);
        $this->assertSame('+966501234567', $row->phone);
        $this->assertSame('Sara', $row->name);
        $this->assertSame('2026-05-23 16:29:38', $row->sentAtUtc);
        $this->assertSame('inbound', $row->direction);
        $this->assertSame('hello', $row->text);
        $this->assertNull($row->originalType);
    }

    /** @dataProvider validPhones */
    public function test_phone_formats(string $raw, string $expected): void
    {
        $this->assertSame($expected, $this->parsed(['phone' => $raw])->phone);
    }

    public static function validPhones(): array
    {
        return [
            'digits only (wa_id)' => ['966501234567', '+966501234567'],
            'with plus' => ['+966501234567', '+966501234567'],
            'with 00' => ['00966501234567', '+966501234567'],
            'spaced and dashed' => ['+966 50-123 4567', '+966501234567'],
            'arabic-indic digits' => ['٩٦٦٥٠١٢٣٤٥٦٧', '+966501234567'],
            'egypt' => ['201001234567', '+201001234567'],
        ];
    }

    public function test_local_numbers_need_a_default_country(): void
    {
        $this->assertSame(['phone'], $this->errorColumns(['phone' => '555770473']));
        $this->assertSame(['phone'], $this->errorColumns(['phone' => '0501234567']));

        $this->assertSame('+966555770473', $this->parsed(['phone' => '555770473'], 'SA')->phone);
        $this->assertSame('+966501234567', $this->parsed(['phone' => '0501234567'], 'SA')->phone);
        // الرقم الدولي الصحيح لا تغيّره الدولة الافتراضية.
        $this->assertSame('+201001234567', $this->parsed(['phone' => '201001234567'], 'SA')->phone);
    }

    public function test_a_local_number_is_not_mistaken_for_another_country(): void
    {
        // بقراءتها دولياً هذه أرقام صالحة في كوبا وسورينام وباراغواي.
        $this->assertSame('+966537675751', $this->parsed(['phone' => '537675751'], 'SA')->phone);
        $this->assertSame('+966597588205', $this->parsed(['phone' => '597588205'], 'SA')->phone);
        $this->assertSame('+966595908589', $this->parsed(['phone' => '595908589'], 'SA')->phone);
        $this->assertSame('+966537675751', $this->parsed(['phone' => '0537675751'], 'SA')->phone);
    }

    public function test_an_explicit_country_code_is_kept(): void
    {
        $this->assertSame('+597588205', $this->parsed(['phone' => '+597588205'], 'SA')->phone);
        $this->assertSame('+597588205', $this->parsed(['phone' => '00597588205'], 'SA')->phone);
        $this->assertSame('+966501234567', $this->parsed(['phone' => '966501234567'], 'SA')->phone);
        $this->assertSame('+966501234567', $this->parsed(['phone' => '9660501234567'], 'SA')->phone);
    }

    public function test_a_missing_digit_is_never_filled_in(): void
    {
        $this->assertSame(['phone'], $this->errorColumns(['phone' => '96600647244'], 'SA'));
        $this->assertSame(['phone'], $this->errorColumns(['phone' => '9665314916119'], 'SA'));
    }

    /** @return array{row: int, column: string, value: string, message: string, message_ar?: string} */
    private function phoneError(string $phone, ?string $country = 'SA'): array
    {
        return $this->parser($country)->parse($this->row(['phone' => $phone]), 7)[0];
    }

    public function test_a_bad_number_says_why_in_both_languages(): void
    {
        $short = $this->phoneError('96600647244');
        $this->assertSame('The number is too short: Saudi Arabia numbers have 9 digits after the country code.', $short['message']);
        $this->assertStringContainsString('الرقم ناقص', $short['message_ar']);
        $this->assertStringContainsString('السعودية', $short['message_ar']);

        $long = $this->phoneError('9665314916119');
        $this->assertSame('The number is too long: Saudi Arabia numbers have 9 digits after the country code.', $long['message']);
        $this->assertStringContainsString('أطول من المطلوب', $long['message_ar']);

        $mismatch = $this->phoneError('966858647403');
        $this->assertSame('The number does not match the numbers of Saudi Arabia.', $mismatch['message']);
        $this->assertStringContainsString('لا يطابق', $mismatch['message_ar']);
    }

    public function test_the_reason_follows_the_country_the_number_belongs_to(): void
    {
        // كود صريح: تُقاس على مصر لا على الدولة الافتراضية.
        $this->assertStringContainsString('Egypt', $this->phoneError('+20100')['message']);
        // بلا كود: تُقاس على الدولة الافتراضية.
        $this->assertStringContainsString('too long', $this->phoneError('097433977744')['message']);
        // كود صريح بلا دولة افتراضية.
        $this->assertStringContainsString('too short', $this->phoneError('+96653330991', null)['message']);
    }

    public function test_a_number_with_no_known_country_asks_for_the_code(): void
    {
        $key = 'Invalid phone number. Include the country code, e.g. 966501234567.';

        $error = $this->phoneError('555770473', null);

        $this->assertSame(__($key), $error['message']);
        $this->assertSame(trans($key, [], 'ar'), $error['message_ar']);
    }

    public function test_only_a_bad_number_makes_a_row_skippable(): void
    {
        $badBoth = $this->parser('SA')->parse($this->row(['phone' => '96600647244', 'sent_at' => 'yesterday']), 7);
        $reason = RowParser::skipReason($badBoth);
        $this->assertSame('phone', $reason['column']);
        $this->assertSame('96600647244', $reason['value']);

        $badDate = $this->parser('SA')->parse($this->row(['sent_at' => 'yesterday']), 7);
        $this->assertNull(RowParser::skipReason($badDate));
    }

    public function test_bad_phones_are_explained(): void
    {
        $messages = fn (string $phone) => array_column($this->parser()->parse($this->row(['phone' => $phone]), 3), 'message');

        $this->assertSame([__('Phone number is required.')], $messages(''));
        $this->assertStringContainsString('9.66E+11', $messages('9.66E+11')[0]);
        $this->assertSame([__('The phone number contains invalid characters.')], $messages('9665abc'));
        $this->assertSame([__('Invalid phone number. Include the country code, e.g. 966501234567.')], $messages('097433977744'));
        $this->assertSame([__('Invalid phone number. Include the country code, e.g. 966501234567.')], $messages('9665314916119'));
    }

    /** @dataProvider validDates */
    public function test_date_formats(string $raw, string $utc): void
    {
        $this->assertSame($utc, $this->parsed(['sent_at' => $raw])->sentAtUtc);
    }

    public static function validDates(): array
    {
        return [
            'iso with offset' => ['2026-05-23T19:29:38+03:00', '2026-05-23 16:29:38'],
            'iso zulu' => ['2026-05-23T16:29:38Z', '2026-05-23 16:29:38'],
            'compact offset' => ['2026-05-23T19:29:38+0300', '2026-05-23 16:29:38'],
            'fractional seconds' => ['2026-05-23T19:29:38.123+03:00', '2026-05-23 16:29:38'],
            'no offset uses the organization zone' => ['2026-05-23 19:29:38', '2026-05-23 16:29:38'],
            'no seconds' => ['2026-05-23 19:29', '2026-05-23 16:29:00'],
            'slashes, year first' => ['2026/5/23 19:29:38', '2026-05-23 16:29:38'],
            'unix seconds' => ['1779553778', '2026-05-23 16:29:38'],
            'unix milliseconds' => ['1779553778000', '2026-05-23 16:29:38'],
        ];
    }

    public function test_bad_dates_are_rejected(): void
    {
        foreach (['', 'yesterday', '23/05/2026 19:29', '05/06/2026 10:00', '2026-02-30 10:00:00', '2026-05-23', '2026-05-23 25:00:00'] as $raw) {
            $this->assertSame(['sent_at'], $this->errorColumns(['sent_at' => $raw]), "«{$raw}» should be rejected");
        }

        $this->assertSame([__('The date is in the future.')], array_column($this->parser()->parse($this->row(['sent_at' => '2027-01-01 00:00:00']), 2), 'message'));
        $this->assertSame([__('The date is too old.')], array_column($this->parser()->parse($this->row(['sent_at' => '2005-01-01 00:00:00']), 2), 'message'));
        // هامش الساعة غير المتزامنة: خمس دقائق في المستقبل مقبولة.
        $this->parsed(['sent_at' => '2026-11-04T12:05:00Z']);
    }

    /** @dataProvider directions */
    public function test_direction_aliases(string $raw, string $expected): void
    {
        $this->assertSame($expected, $this->parsed(['direction' => $raw])->direction);
    }

    public static function directions(): array
    {
        return [
            ['in', 'inbound'], ['IN', 'inbound'], ['incoming', 'inbound'], ['received', 'inbound'], ['وارد', 'inbound'],
            ['out', 'outbound'], ['Outbound', 'outbound'], ['sent', 'outbound'], ['صادر', 'outbound'],
        ];
    }

    public function test_unknown_direction_is_rejected(): void
    {
        $this->assertSame(['direction'], $this->errorColumns(['direction' => 'sideways']));
        $this->assertSame(['direction'], $this->errorColumns(['direction' => '']));
    }

    public function test_text_rules(): void
    {
        $this->assertSame(['text'], $this->errorColumns(['text' => "  \n "]));
        $this->assertIsArray($this->parser(null, 5)->parse($this->row(['text' => 'too long']), 2));

        $row = $this->parsed(['text' => "  line one\r\nline two  "]);
        $this->assertSame("line one\nline two", $row->text);
    }

    public function test_media_placeholders_are_recognised(): void
    {
        $this->assertSame('image', $this->parsed(['text' => '[image] caption'])->originalType);
        $this->assertSame('document', $this->parsed(['text' => '[document] file.pdf'])->originalType);
        $this->assertSame('sticker', $this->parsed(['text' => '[sticker]'])->originalType);
        // اسم قالب بين قوسين ليس نوع وسائط.
        $this->assertNull($this->parsed(['text' => '[appointment_reminder_same_day] HBR'])->originalType);
    }

    public function test_names(): void
    {
        $this->assertNull($this->parsed(['name' => '.'])->name);
        $this->assertNull($this->parsed(['name' => ' - '])->name);
        $this->assertNull($this->parsed(['name' => null])->name);
        $this->assertSame('بوينتات', $this->parsed(['name' => '  بوينتات '])->name);
        $this->assertSame(128, mb_strlen($this->parsed(['name' => str_repeat('ن', 300)])->name));
    }

    public function test_every_error_in_a_row_is_reported(): void
    {
        $result = $this->parser()->parse(['phone' => 'x', 'name' => null, 'sent_at' => 'x', 'direction' => 'x', 'text' => ''], 9);

        $this->assertSame(['phone', 'sent_at', 'direction', 'text'], array_column($result, 'column'));
        $this->assertSame([9, 9, 9, 9], array_column($result, 'row'));
    }

    public function test_invalid_utf8_is_rejected(): void
    {
        $this->assertSame(['name'], $this->errorColumns(['name' => "\xC3\xCD"]));
    }
}
