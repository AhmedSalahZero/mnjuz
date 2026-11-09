<?php

namespace Tests\Unit\Carousel;

use App\Services\Carousel\CarouselDefinition;
use PHPUnit\Framework\TestCase;

/**
 * قراءة قالب الـ carousel من مصدريه: ما أنشأناه نحن (UPPERCASE) وما جاء
 * من مزامنة Meta (lowercase أحياناً، ومعه id وstatus وروابط scontent).
 */
class CarouselDefinitionTest extends TestCase
{
    public static function inAppPayload(): array
    {
        return [
            'name' => 'shoes',
            'language' => 'ar',
            'category' => 'MARKETING',
            'components' => [
                ['type' => 'BODY', 'text' => 'Hi {{1}}', 'example' => ['body_text' => [['Sara']]]],
                ['type' => 'CAROUSEL', 'cards' => [
                    ['components' => [
                        ['type' => 'HEADER', 'format' => 'IMAGE', 'example' => ['header_handle' => ['4::a']]],
                        ['type' => 'BODY', 'text' => 'Shoe {{1}}', 'example' => ['body_text' => [['red']]]],
                        ['type' => 'BUTTONS', 'buttons' => [
                            ['type' => 'QUICK_REPLY', 'text' => 'Buy'],
                            ['type' => 'URL', 'text' => 'Open', 'url' => 'https://x.test/p/{{1}}', 'example' => ['sku1']],
                        ]],
                    ]],
                    ['components' => [
                        ['type' => 'HEADER', 'format' => 'IMAGE', 'example' => ['header_handle' => ['4::b']]],
                        ['type' => 'BODY', 'text' => 'Bag {{1}}', 'example' => ['body_text' => [['blue']]]],
                        ['type' => 'BUTTONS', 'buttons' => [
                            ['type' => 'QUICK_REPLY', 'text' => 'Buy'],
                            ['type' => 'URL', 'text' => 'Open', 'url' => 'https://x.test/p/{{1}}', 'example' => ['sku2']],
                        ]],
                    ]],
                ]],
            ],
        ];
    }

    public function test_reads_the_payload_we_created(): void
    {
        $definition = CarouselDefinition::fromMetadata(json_encode(self::inAppPayload()));

        $this->assertSame('Hi {{1}}', $definition['body']['text']);
        $this->assertSame(1, $definition['body']['variables']);
        $this->assertSame('IMAGE', $definition['format']);
        $this->assertCount(2, $definition['cards']);
        $this->assertSame(['text' => 'Bag {{1}}', 'variables' => 1], $definition['cards'][1]['body']);
        $this->assertSame([
            ['type' => 'QUICK_REPLY', 'text' => 'Buy', 'url' => null, 'has_variable' => false, 'example' => null, 'phone_number' => null],
            ['type' => 'URL', 'text' => 'Open', 'url' => 'https://x.test/p/{{1}}', 'has_variable' => true, 'example' => 'sku1', 'phone_number' => null],
        ], $definition['cards'][0]['buttons']);
    }

    /** المزامنة تحفظ كائن Meta كما هو: lowercase، ومعه id وstatus وscontent. */
    public function test_reads_a_synced_meta_object(): void
    {
        $synced = [
            'id' => '123',
            'status' => 'APPROVED',
            'name' => 'synced',
            'language' => 'en',
            'category' => 'MARKETING',
            'components' => [
                ['type' => 'body', 'text' => 'Hello'],
                ['type' => 'carousel', 'cards' => [
                    ['components' => [
                        ['type' => 'header', 'format' => 'video', 'example' => ['header_handle' => ['https://scontent.whatsapp.net/v/x.mp4']]],
                        ['type' => 'buttons', 'buttons' => [['type' => 'phone_number', 'text' => 'Call', 'phone_number' => '+966500000000']]],
                    ]],
                    ['components' => [
                        ['type' => 'header', 'format' => 'video', 'example' => ['header_handle' => ['https://scontent.whatsapp.net/v/y.mp4']]],
                        ['type' => 'buttons', 'buttons' => [['type' => 'phone_number', 'text' => 'Call', 'phone_number' => '+966500000000']]],
                    ]],
                ]],
            ],
        ];

        $this->assertTrue(CarouselDefinition::isCarousel((object) $synced));

        $definition = CarouselDefinition::fromMetadata($synced);

        $this->assertSame('VIDEO', $definition['format']);
        $this->assertSame(0, $definition['body']['variables']);
        $this->assertNull($definition['cards'][0]['body']);
        $this->assertSame('PHONE_NUMBER', $definition['cards'][1]['buttons'][0]['type']);
        $this->assertFalse($definition['cards'][1]['buttons'][0]['has_variable']);
    }

    public function test_a_url_without_a_variable_has_no_variable(): void
    {
        $payload = self::inAppPayload();
        $payload['components'][1]['cards'][0]['components'][2]['buttons'][1]['url'] = 'https://x.test/shop';

        $definition = CarouselDefinition::fromMetadata($payload);

        $this->assertFalse($definition['cards'][0]['buttons'][1]['has_variable']);
        $this->assertTrue($definition['cards'][1]['buttons'][1]['has_variable']);
    }

    public function test_standard_templates_are_not_carousels(): void
    {
        $standard = json_encode(['components' => [
            ['type' => 'HEADER', 'format' => 'IMAGE'],
            ['type' => 'BODY', 'text' => 'Hi'],
            ['type' => 'BUTTONS', 'buttons' => [['type' => 'QUICK_REPLY', 'text' => 'Yes']]],
        ]]);

        $this->assertFalse(CarouselDefinition::isCarousel($standard));
        $this->assertNull(CarouselDefinition::fromMetadata($standard));
    }

    public function test_garbage_and_null_are_not_carousels(): void
    {
        foreach ([null, '', 'not json', '[]', '{"components":"x"}', '{"components":[{"type":"CAROUSEL","cards":[]}]}', 42] as $value) {
            $this->assertFalse(CarouselDefinition::isCarousel($value), var_export($value, true));
            $this->assertNull(CarouselDefinition::fromMetadata($value));
        }
    }

    public function test_counts_distinct_variables(): void
    {
        $this->assertSame(0, CarouselDefinition::countVariables('no vars'));
        $this->assertSame(2, CarouselDefinition::countVariables('{{1}} and {{2}} and {{1}} again'));
        $this->assertSame(1, CarouselDefinition::countVariables('spaced {{ 1 }}'));
    }
}
