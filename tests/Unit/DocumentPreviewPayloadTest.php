<?php

namespace Tests\Unit;

use App\Http\Resources\ContactListResource;
use PHPUnit\Framework\TestCase;

/**
 * ما يصل معاينة قائمة المحادثات عن المستندات.
 *
 * المورد يقصّ الحمولة عمداً — القائمة تحمل آلاف الصفوف ولا تحتمل حمولة كاملة
 * لكل صفّ. لكن المستند كان يُقصّ إلى `{"type":"document"}` فقط، فلا تبقى في
 * المتصفّح معلومة تُشتقّ منها صيغته: ظهر كل مستند «Unknown ملف».
 *
 * الوارد والصادر يحفظان حقلين مختلفين — الوارد filename والصادر mime_type —
 * فالمعاينة تحتاجهما معاً.
 */
class DocumentPreviewPayloadTest extends TestCase
{
    /** @return array<string, mixed> */
    private function preview(array $metadata): array
    {
        return json_decode(ContactListResource::metadataPreview(json_encode($metadata)), true);
    }

    // ------------------------------------------------------ المستند

    /** الصادر: نحفظ mime_type. */
    public function test_an_outbound_document_keeps_its_mime_type(): void
    {
        $preview = $this->preview([
            'type' => 'document',
            'document' => ['mime_type' => 'application/pdf'],
        ]);

        $this->assertSame('document', $preview['type']);
        $this->assertSame('application/pdf', $preview['document']['mime_type']);
    }

    /** الوارد: نحفظ filename. */
    public function test_an_inbound_document_keeps_its_filename(): void
    {
        $preview = $this->preview([
            'type' => 'document',
            'document' => ['filename' => 'عرض السعر.pdf'],
        ]);

        $this->assertSame('عرض السعر.pdf', $preview['document']['filename']);
    }

    public function test_both_fields_survive_together(): void
    {
        $preview = $this->preview([
            'type' => 'document',
            'document' => ['mime_type' => 'application/pdf', 'filename' => 'x.pdf'],
        ]);

        $this->assertSame('application/pdf', $preview['document']['mime_type']);
        $this->assertSame('x.pdf', $preview['document']['filename']);
    }

    /** ولا نُدخل مفاتيح فارغة تُوهم بوجود معلومة. */
    public function test_absent_details_add_no_empty_keys(): void
    {
        $preview = $this->preview(['type' => 'document', 'document' => []]);

        $this->assertSame(['type' => 'document'], $preview);
    }

    // -------------------------------------------------------- القصّ

    /** اسم ملف طويل لا يُثقل صفوف القائمة. */
    public function test_a_long_filename_is_trimmed(): void
    {
        $preview = $this->preview([
            'type' => 'document',
            'document' => ['filename' => str_repeat('ط', 400) . '.pdf'],
        ]);

        $this->assertSame(
            ContactListResource::PREVIEW_MAX_LENGTH,
            mb_strlen($preview['document']['filename'])
        );
    }

    /** ولا نُسرّب ما لا تحتاجه المعاينة. */
    public function test_unneeded_keys_are_dropped(): void
    {
        $preview = $this->preview([
            'type' => 'document',
            'id' => 'wamid.XXX',
            'document' => [
                'mime_type' => 'application/pdf',
                'sha256' => 'abc',
                'url' => 'https://example.com/secret',
                'caption' => 'تعليق طويل',
            ],
        ]);

        $this->assertSame(['mime_type' => 'application/pdf'], $preview['document']);
        $this->assertArrayNotHasKey('id', $preview);
    }

    // ------------------------------------------------ الأنواع الأخرى

    /** الصورة والفيديو والصوت تبقى مقتضبة كما كانت. */
    public function test_other_media_types_stay_minimal(): void
    {
        foreach (['image', 'video', 'audio', 'sticker', 'location'] as $type) {
            $preview = $this->preview([$type => ['caption' => 'س'], 'type' => $type]);

            $this->assertSame(['type' => $type], $preview, "النوع {$type} تغيّر بلا داعٍ");
        }
    }

    public function test_text_previews_are_unaffected(): void
    {
        $preview = $this->preview(['type' => 'text', 'text' => ['body' => 'مرحباً']]);

        $this->assertSame('مرحباً', $preview['text']['body']);
    }
}
