<?php

namespace Tests\Unit\Carousel;

use App\Services\Carousel\CarouselPayloadBuilder;
use App\Traits\TemplateTrait;
use PHPUnit\Framework\TestCase;

/**
 * حمولات الـ carousel كما تصل Meta: طلب الإنشاء، ومكوّن الإرسال، وما يُحفظ
 * في المحادثة.
 */
class CarouselPayloadBuilderTest extends TestCase
{
    use TemplateTrait;

    private function createInput(): array
    {
        return [
            'name' => 'shoes',
            'language' => 'ar',
            'category' => 'MARKETING',
            'body' => ['text' => 'Hi {{1}}, code {{2}}', 'example' => ['Sara', 'SAVE']],
            'carousel' => [
                'format' => 'IMAGE',
                'cards' => [
                    [
                        'body' => ['text' => 'Shoe {{1}}', 'example' => ['red']],
                        'buttons' => [
                            ['type' => 'QUICK_REPLY', 'text' => 'Buy'],
                            ['type' => 'URL', 'text' => 'Open', 'url' => 'https://x.test/p/{{1}}', 'example' => 'sku1'],
                        ],
                    ],
                    [
                        'body' => ['text' => 'Bag', 'example' => []],
                        'buttons' => [
                            ['type' => 'QUICK_REPLY', 'text' => 'Buy'],
                            ['type' => 'URL', 'text' => 'Open', 'url' => 'https://x.test/p/{{1}}', 'example' => 'sku2'],
                        ],
                    ],
                ],
            ],
        ];
    }

    // ------------------------------------------------ طلب الإنشاء

    public function test_create_payload_matches_meta_shape(): void
    {
        $payload = CarouselPayloadBuilder::createPayload($this->createInput(), ['4::A', '4::B']);

        $this->assertSame([
            'name' => 'shoes',
            'language' => 'ar',
            'category' => 'MARKETING',
            'components' => [
                ['type' => 'BODY', 'text' => 'Hi {{1}}, code {{2}}', 'example' => ['body_text' => [['Sara', 'SAVE']]]],
                ['type' => 'CAROUSEL', 'cards' => [
                    ['components' => [
                        ['type' => 'HEADER', 'format' => 'IMAGE', 'example' => ['header_handle' => ['4::A']]],
                        ['type' => 'BODY', 'text' => 'Shoe {{1}}', 'example' => ['body_text' => [['red']]]],
                        ['type' => 'BUTTONS', 'buttons' => [
                            ['type' => 'QUICK_REPLY', 'text' => 'Buy'],
                            ['type' => 'URL', 'text' => 'Open', 'url' => 'https://x.test/p/{{1}}', 'example' => ['sku1']],
                        ]],
                    ]],
                    ['components' => [
                        ['type' => 'HEADER', 'format' => 'IMAGE', 'example' => ['header_handle' => ['4::B']]],
                        ['type' => 'BODY', 'text' => 'Bag'],
                        ['type' => 'BUTTONS', 'buttons' => [
                            ['type' => 'QUICK_REPLY', 'text' => 'Buy'],
                            ['type' => 'URL', 'text' => 'Open', 'url' => 'https://x.test/p/{{1}}', 'example' => ['sku2']],
                        ]],
                    ]],
                ]],
            ],
        ], $payload);
    }

    /** لا حقل header/footer/buttons على مستوى القالب: Meta ترفضها مع الـ carousel. */
    public function test_create_payload_has_only_body_and_carousel_at_the_top(): void
    {
        $input = $this->createInput();
        $input['header'] = ['format' => 'TEXT', 'text' => 'x'];
        $input['footer'] = ['text' => 'x'];
        $input['buttons'] = [['type' => 'QUICK_REPLY', 'text' => 'x']];

        $payload = CarouselPayloadBuilder::createPayload($input, ['4::A', '4::B']);

        $this->assertSame(['BODY', 'CAROUSEL'], array_column($payload['components'], 'type'));
    }

    public function test_cards_without_text_or_buttons_and_video_and_phone(): void
    {
        $input = $this->createInput();
        $input['body'] = ['text' => 'Plain body'];
        $input['carousel']['format'] = 'VIDEO';
        $input['carousel']['cards'] = [
            ['buttons' => [['type' => 'PHONE_NUMBER', 'text' => 'Call', 'country' => '+966', 'phone_number' => '500000000']]],
            ['buttons' => [['type' => 'url', 'text' => 'Site', 'url' => 'https://x.test/shop']]],
        ];

        $payload = CarouselPayloadBuilder::createPayload($input, ['4::A', '4::B']);

        $this->assertSame(['type' => 'BODY', 'text' => 'Plain body'], $payload['components'][0]);
        $cards = $payload['components'][1]['cards'];
        $this->assertSame('VIDEO', $cards[0]['components'][0]['format']);
        $this->assertSame(['HEADER', 'BUTTONS'], array_column($cards[0]['components'], 'type'));
        $this->assertSame(
            ['type' => 'PHONE_NUMBER', 'text' => 'Call', 'phone_number' => '+966500000000'],
            $cards[0]['components'][1]['buttons'][0]
        );
        // رابط بلا متغيّر لا يحمل example.
        $this->assertSame(
            ['type' => 'URL', 'text' => 'Site', 'url' => 'https://x.test/shop'],
            $cards[1]['components'][1]['buttons'][0]
        );
    }

    // ------------------------------------------------ مكوّن الإرسال

    private function campaignCarousel(): array
    {
        return [
            'format' => 'IMAGE',
            'cards' => [
                [
                    'card_index' => 0,
                    'header' => ['type' => 'IMAGE', 'selection' => 'upload', 'value' => 'https://s3.test/1.jpg', 'chat_media_id' => 5],
                    'body' => ['text' => 'Shoe {{1}}', 'parameters' => [['type' => 'text', 'selection' => 'dynamic', 'value' => 'first name']]],
                    'buttons' => [
                        ['index' => 0, 'type' => 'QUICK_REPLY', 'text' => 'Buy', 'url' => null, 'parameters' => [['selection' => 'static', 'value' => 'card1_buy']]],
                        ['index' => 1, 'type' => 'URL', 'text' => 'Open', 'url' => 'https://x.test/p/{{1}}', 'parameters' => [['selection' => 'static', 'value' => 'sku1']]],
                    ],
                ],
                [
                    'card_index' => 1,
                    'header' => ['type' => 'IMAGE', 'selection' => 'default', 'value' => 'https://s3.test/2.jpg', 'media_id' => '999'],
                    'body' => ['text' => 'Bag', 'parameters' => []],
                    'buttons' => [
                        ['index' => 0, 'type' => 'QUICK_REPLY', 'text' => 'Buy', 'url' => null, 'parameters' => [['selection' => 'static', 'value' => '']]],
                        ['index' => 1, 'type' => 'PHONE_NUMBER', 'text' => 'Call', 'url' => null, 'parameters' => []],
                    ],
                ],
            ],
        ];
    }

    public function test_send_component_matches_meta_shape(): void
    {
        $contact = (object) ['first_name' => 'Sara', 'last_name' => 'Ali', 'email' => null, 'phone' => '+966500000001'];
        $resolve = fn ($parameter) => $this->resolveTemplateTextValue($contact, $parameter);

        $component = CarouselPayloadBuilder::sendComponent(json_decode(json_encode($this->campaignCarousel())), $resolve);

        $this->assertSame([
            'type' => 'carousel',
            'cards' => [
                [
                    'card_index' => 0,
                    'components' => [
                        ['type' => 'header', 'parameters' => [['type' => 'image', 'image' => ['link' => 'https://s3.test/1.jpg']]]],
                        ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => 'Sara']]],
                        ['type' => 'button', 'sub_type' => 'quick_reply', 'index' => 0, 'parameters' => [['type' => 'payload', 'payload' => 'card1_buy']]],
                        ['type' => 'button', 'sub_type' => 'url', 'index' => 1, 'parameters' => [['type' => 'text', 'text' => 'sku1']]],
                    ],
                ],
                [
                    'card_index' => 1,
                    'components' => [
                        // media_id يتقدّم على الرابط إن وُجد.
                        ['type' => 'header', 'parameters' => [['type' => 'image', 'image' => ['id' => '999']]]],
                        // لا body بلا متغيّرات، ولا quick reply بحمولة فارغة، ولا زرّ هاتف.
                    ],
                ],
            ],
        ], $component);
    }

    public function test_video_cards_use_the_video_key(): void
    {
        $carousel = $this->campaignCarousel();
        $carousel['format'] = 'VIDEO';

        $component = CarouselPayloadBuilder::sendComponent($carousel, fn () => 'x');

        $this->assertSame(
            ['type' => 'video', 'video' => ['link' => 'https://s3.test/1.jpg']],
            $component['cards'][0]['components'][0]['parameters'][0]
        );
    }

    /** buildTemplate مع carousel: body ثم carousel، ولا ترويسة ولا أزرار على المستوى الأعلى. */
    public function test_build_template_appends_the_carousel_component(): void
    {
        $metadata = json_decode(json_encode([
            'header' => ['format' => null, 'parameters' => []],
            'body' => ['text' => 'Hi {{1}}', 'parameters' => [['type' => 'text', 'selection' => 'static', 'value' => 'Sara']]],
            'footer' => ['text' => null],
            'buttons' => [],
            'media' => null,
            'carousel' => $this->campaignCarousel(),
        ]));

        $template = $this->buildTemplate('shoes', 'ar', $metadata, (object) ['first_name' => 'Sara', 'last_name' => '', 'phone' => '+966500000001']);

        $this->assertSame(['body', 'carousel'], array_column($template['components'], 'type'));
        $this->assertCount(2, $template['components'][1]['cards']);
    }

    // ------------------------------------------------ سجلّ المحادثة

    /**
     * البثّ يرمّز metadata بـ json_encode الافتراضي (العربي ستة بايتات)
     * داخل حمولة حدّها 10KB. carousel معتاد — ستّ بطاقات بنصّ عربي قصير —
     * يجب أن يمرّ كاملاً. وما يزيد يُفرَّغ في البثّ وحده ويبقى سليماً في
     * القاعدة (fitToPusherLimit).
     */
    public function test_chat_carousel_is_compact(): void
    {
        $cards = array_fill(0, 6, [
            'media' => 'https://mnjzchat.s3.amazonaws.com/uploads/media/sent/260/' . str_repeat('a', 40) . '.jpg',
            'body' => str_repeat('ن', 60),
            'buttons' => [['type' => 'QUICK_REPLY', 'text' => 'اطلب الآن'], ['type' => 'URL', 'text' => 'تسوّق الآن']],
        ]);

        $carousel = CarouselPayloadBuilder::chatCarousel('image', $cards);
        $metadata = \App\Support\JsonText::encode([
            'type' => 'text',
            'text' => ['body' => str_repeat('ع', 200)],
            'carousel' => $carousel,
        ]);

        $this->assertSame('IMAGE', $carousel['format']);
        $this->assertSame(['type' => 'URL', 'text' => 'تسوّق الآن'], $carousel['cards'][5]['buttons'][1]);
        $this->assertLessThan(8000, strlen(json_encode(['metadata' => $metadata])), 'يجب أن يترك متّسعاً لبقية حمولة البثّ');
    }

    public function test_chat_carousel_trims_card_text(): void
    {
        $carousel = CarouselPayloadBuilder::chatCarousel('VIDEO', [['media' => null, 'body' => str_repeat('x', 500), 'buttons' => []]]);

        $this->assertSame(160, mb_strlen($carousel['cards'][0]['body']));
        $this->assertNull($carousel['cards'][0]['media']);
    }

    public function test_substitute_replaces_numbered_variables(): void
    {
        $this->assertSame('Hi Sara, SAVE', CarouselPayloadBuilder::substitute('Hi {{1}}, {{2}}', ['Sara', 'SAVE']));
        $this->assertSame('Hi {{2}}', CarouselPayloadBuilder::substitute('Hi {{2}}', ['Sara']));
    }
}
