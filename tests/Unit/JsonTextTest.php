<?php

namespace Tests\Unit;

use App\Support\JsonText;
use PHPUnit\Framework\TestCase;

class JsonTextTest extends TestCase
{
    public function test_encode_keeps_arabic_raw_instead_of_escaping_it(): void
    {
        $out = JsonText::encode(['body' => 'السلام عليكم']);

        $this->assertSame('{"body":"السلام عليكم"}', $out);
        $this->assertStringNotContainsString('\u', $out);
    }

    public function test_encode_keeps_slashes_raw(): void
    {
        $this->assertSame('{"u":"https://waz.sa/a"}', JsonText::encode(['u' => 'https://waz.sa/a']));
    }

    public function test_encoded_output_parses_to_the_same_value_as_the_escaped_form(): void
    {
        $value = ['type' => 'text', 'text' => ['body' => "مرحباً\nكيف حالك؟ ❤️"], 'n' => 5, 'ok' => true, 'x' => null];

        $this->assertSame(
            json_decode(json_encode($value), true),
            json_decode(JsonText::encode($value), true)
        );
    }

    public function test_reencode_unescapes_a_stored_row(): void
    {
        $stored = json_encode(['type' => 'text', 'text' => ['body' => 'السلام عليكم']]);

        $this->assertStringNotContainsString('السلام', $stored, 'التخزين الحالي مهرَّب — هذا ما نصلحه');
        $this->assertSame('{"type":"text","text":{"body":"السلام عليكم"}}', JsonText::reencodeLossless($stored));
    }

    public function test_reencode_never_grows_a_row(): void
    {
        $values = [['a' => 'ا'], ['u' => 'https://a.b/c'], ['t' => 'مرحبا يا صديقي']];

        foreach ($values as $value) {
            $stored = json_encode($value);
            $new = JsonText::reencodeLossless($stored);

            $this->assertNotNull($new, 'تعذّر تحويل: ' . $stored);
            $this->assertLessThanOrEqual(strlen($stored), strlen($new));
        }
    }

    /** الفرق بين {} و[] يضيع لو فُكّ النصّ في وضع المصفوفات — هذا يحرسه. */
    public function test_reencode_preserves_empty_object_versus_empty_array(): void
    {
        // ASCII خالص ومحفوظ أصلاً: لا شيء ليُغيَّر.
        $this->assertNull(JsonText::reencodeLossless('{"sticker":{},"list":[]}'));

        $stored = json_encode(['caption' => 'صورة', 'sticker' => new \stdClass(), 'list' => []]);
        $this->assertStringNotContainsString('صورة', $stored, 'المخزَّن مهرَّب');

        // {} تبقى كائناً و[] تبقى مصفوفة بعد التحويل.
        $this->assertSame('{"caption":"صورة","sticker":{},"list":[]}', JsonText::reencodeLossless($stored));
    }

    public function test_reencode_returns_null_when_there_is_nothing_to_change(): void
    {
        // ASCII خالص: الصيغتان متطابقتان أصلاً.
        $this->assertNull(JsonText::reencodeLossless('{"a":1,"b":"ok"}'));

        // صفّ كتبه الكود بعد الإصلاح — خام أصلاً، فلا يُعاد تحويله.
        $this->assertNull(JsonText::reencodeLossless('{"a":"ا"}'));
    }

    public function test_reencode_refuses_invalid_json(): void
    {
        $this->assertNull(JsonText::reencodeLossless(''));
        $this->assertNull(JsonText::reencodeLossless('{"a":'));
        $this->assertNull(JsonText::reencodeLossless('not json at all'));
    }

    /** أعداد تتجاوز دقّة PHP تفقد أرقاماً عند الفكّ — البرهان يرفض الصفّ. */
    public function test_reencode_refuses_rows_whose_numbers_would_lose_precision(): void
    {
        $this->assertNull(JsonText::reencodeLossless('{"id":123456789012345678901234567890,"t":"ا"}'));
    }

    /** صفوف كتبها مُنتِج آخر بمسافات ترقيم لا تُثبت الدورة، فتُترك كما هي. */
    public function test_reencode_refuses_rows_it_cannot_reproduce_byte_for_byte(): void
    {
        $this->assertNull(JsonText::reencodeLossless('{"type": "sticker", "sticker": {}}'));
    }

    /** الخاصية الجوهرية: التحويل لا يغيّر القيمة، أياً كان المحتوى. */
    public function test_conversion_never_changes_the_decoded_value(): void
    {
        $samples = [
            ['type' => 'text', 'text' => ['body' => 'أهلاً وسهلاً في Delicieux! ✨🤍']],
            ['type' => 'interactive', 'interactive' => ['button_reply' => ['id' => '1', 'title' => 'نعم']]],
            ['type' => 'image', 'image' => ['caption' => null]],
            ['type' => 'location', 'location' => ['lat' => 24.7136, 'lng' => 46.6753]],
            ['type' => 'text', 'text' => ['body' => "سطر\nوسطر\tوعلامة \" واقتباس"]],
            ['from' => '966502486051', 'timestamp' => '1785345032', 'type' => 'edit'],
        ];

        foreach ($samples as $value) {
            $stored = json_encode($value);
            $new = JsonText::reencodeLossless($stored);

            if ($new === null) {
                continue;
            }

            $this->assertSame(
                json_decode($stored, true),
                json_decode($new, true),
                'تغيّرت القيمة بعد التحويل: ' . $stored
            );
        }
    }
}
