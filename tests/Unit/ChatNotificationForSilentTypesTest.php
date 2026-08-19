<?php

namespace Tests\Unit;

use App\Services\Chat\ChatBroadcastPayloadBuilder;
use App\Services\Chat\ChatNotificationContentBuilder;
use Tests\TestCase;

/**
 * نصّ الإشعار للأنواع التي كانت صامتة.
 *
 * قبل هذا التغيير كانت الأربعة تسقط في الفرع الافتراضي فيصل الموظّف
 * «💬 Message»، فيفتح المحادثة ولا يجد شيئاً — لأنها لم تكن تُعرض أصلاً.
 * الإشعار الآن يقول ما حدث فعلاً.
 *
 * يرث Tests\TestCase لا PHPUnit مباشرةً: البنّاء يستدعي Lang::get.
 */
class ChatNotificationForSilentTypesTest extends TestCase
{
    private ChatNotificationContentBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = new ChatNotificationContentBuilder(new ChatBroadcastPayloadBuilder());
    }

    private function body(array $metadata, string $locale = 'en'): string
    {
        return $this->builder->buildBody(['metadata' => json_encode($metadata)], $locale);
    }

    // ------------------------------------------------------------ reaction

    public function test_reaction_shows_the_emoji_the_customer_used(): void
    {
        $body = $this->body(['type' => 'reaction', 'reaction' => ['emoji' => '❤️']]);

        $this->assertStringStartsWith('❤️', $body);
        $this->assertStringContainsString('Reacted to a message', $body);
    }

    /** إيموجي فارغ = إزالة تفاعل سابق، لا تفاعل بلا رمز. */
    public function test_removing_a_reaction_reads_differently_from_adding_one(): void
    {
        $removed = $this->body(['type' => 'reaction', 'reaction' => ['emoji' => '']]);
        $added = $this->body(['type' => 'reaction', 'reaction' => ['emoji' => '👍']]);

        $this->assertStringContainsString('Removed a reaction', $removed);
        $this->assertNotSame($added, $removed);
    }

    public function test_reaction_without_a_reaction_block_still_reads_sensibly(): void
    {
        $this->assertStringContainsString('Removed a reaction', $this->body(['type' => 'reaction']));
    }

    // -------------------------------------------------------------- system

    public function test_system_notification_carries_the_actual_body(): void
    {
        $body = $this->body([
            'type' => 'system',
            'system' => ['body' => 'User A changed from 966596771718 to 201273635016', 'type' => 'user_changed_number'],
        ]);

        $this->assertStringContainsString('966596771718', $body);
        $this->assertStringContainsString('201273635016', $body);
    }

    public function test_system_without_a_body_falls_back_to_a_clear_sentence(): void
    {
        $this->assertStringContainsString(
            'Contact changed their number',
            $this->body(['type' => 'system', 'system' => []])
        );
    }

    // ---------------------------------------------------------------- edit

    /** الموظّف يريد النصّ الجديد، لا خبر وقوع تعديل. */
    public function test_edit_notification_shows_the_new_text(): void
    {
        $body = $this->body([
            'type' => 'edit',
            'edit' => ['message' => ['type' => 'text', 'text' => ['body' => 'اهو']]],
        ]);

        $this->assertStringContainsString('اهو', $body);
    }

    public function test_edit_without_a_body_still_says_what_happened(): void
    {
        $this->assertStringContainsString('Edited a message', $this->body(['type' => 'edit', 'edit' => []]));
    }

    // -------------------------------------------------------------- revoke

    public function test_revoke_says_the_message_was_deleted(): void
    {
        $this->assertStringContainsString('Deleted a message', $this->body(['type' => 'revoke']));
    }

    // ------------------------------------- الصفوف القديمة المخزّنة خاماً

    /**
     * 10,517 صفّاً محفوظاً بالفعل بالشكل الخام قبل هذا التغيير. الإشعار يقرأ
     * نفس المفاتيح في الشكلين، فلا يحتاج ترحيل بيانات — وهذا ما يثبته هذا
     * الاختبار: الحمولة الخامّة كاملةً تُنتج نفس النصّ.
     */
    public function test_legacy_raw_rows_produce_the_same_notification(): void
    {
        $raw = [
            'from' => '966564127797',
            'id' => 'wamid.HBgMOTY2NTY0MTI3Nzk3FQIAEhgWM0VCMEI1M0EyRjJERkJEREU3QjBBNwA=',
            'timestamp' => '1761642669',
            'type' => 'reaction',
            'reaction' => ['message_id' => 'wamid.PARENT', 'emoji' => '❤️'],
        ];
        $minimized = ['type' => 'reaction', 'reaction' => ['message_id' => 'wamid.PARENT', 'emoji' => '❤️']];

        $this->assertSame($this->body($minimized), $this->body($raw));
    }

    public function test_legacy_raw_edit_rows_produce_the_same_notification(): void
    {
        $raw = [
            'from' => '966560294085',
            'to' => '966543553452',
            'id' => 'wamid.A',
            'to_user_id' => 'SA.1970102981059672',
            'timestamp' => '1784959284',
            'type' => 'edit',
            'edit' => ['original_message_id' => 'wamid.B', 'message' => ['type' => 'text', 'text' => ['body' => 'اهو']]],
        ];
        $minimized = [
            'type' => 'edit',
            'edit' => ['original_message_id' => 'wamid.B', 'message' => ['type' => 'text', 'text' => ['body' => 'اهو']]],
        ];

        $this->assertSame($this->body($minimized), $this->body($raw));
    }

    // ---------------------------------------- ألّا ينكسر ما كان يعمل

    /**
     * كود إنتاجي: الأنواع التي كانت تُنتج إشعاراً صحيحاً يجب أن تبقى كما هي.
     *
     * @dataProvider untouchedTypes
     */
    public function test_existing_notification_bodies_are_unchanged(array $metadata, string $expected): void
    {
        $this->assertStringContainsString($expected, $this->body($metadata));
    }

    public static function untouchedTypes(): array
    {
        return [
            'نصّ' => [['type' => 'text', 'text' => ['body' => 'مرحباً']], 'مرحباً'],
            'صورة' => [['type' => 'image'], 'Photo'],
            'فيديو' => [['type' => 'video'], 'Video'],
            'ملصق' => [['type' => 'sticker'], 'Sticker'],
            'موقع' => [['type' => 'location'], 'Location'],
            'جهة اتصال' => [['type' => 'contacts'], 'Contact'],
            'ملف' => [['type' => 'document'], 'File'],
        ];
    }

    /** نوع لم تعرفه Meta بعد يبقى على الفرع الافتراضي بلا انهيار. */
    public function test_an_unknown_type_still_falls_back_to_a_generic_line(): void
    {
        $this->assertNotSame('', trim($this->body(['type' => 'order', 'order' => ['catalog_id' => '1']])));
    }

    // ------------------------------------------------------ الترجمة العربية

    public function test_the_new_lines_are_translated_into_arabic(): void
    {
        $arabic = json_decode(file_get_contents(base_path('lang/ar.json')), true);

        foreach ([
            'Reacted to a message',
            'Removed a reaction',
            'Contact changed their number',
            'Edited a message',
            'Deleted a message',
            'This message was deleted',
            'This message type cannot be displayed here.',
        ] as $string) {
            $this->assertArrayHasKey($string, $arabic, "ترجمة عربية مفقودة: {$string}");
            $this->assertNotSame('', trim((string) $arabic[$string]));
        }
    }

    public function test_reaction_notification_resolves_in_arabic(): void
    {
        $body = $this->body(['type' => 'reaction', 'reaction' => ['emoji' => '❤️']], 'ar');

        $this->assertStringContainsString('تفاعل مع رسالة', $body);
    }
}
