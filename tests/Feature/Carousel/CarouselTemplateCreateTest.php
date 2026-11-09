<?php

namespace Tests\Feature\Carousel;

use App\Models\Template;
use App\Services\Carousel\CarouselDefinition;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * إنشاء قالب carousel من صفحة القوالب.
 *
 * ما يُحرَس: أن ما يصل Meta بالشكل الذي تقبله، وأن كل مخالفة لقواعدها
 * تُرفض عندنا قبل أي طلب إلى Meta (فلا رفع لعشرة ملفات ثم رفض عام)، وأن
 * القالب العادي لم يتغيّر مساره.
 */
class CarouselTemplateCreateTest extends CarouselTestCase
{
    private function validInput(): array
    {
        return [
            'template_type' => 'carousel',
            'name' => 'summer_shoes',
            'language' => 'ar',
            'category' => 'MARKETING',
            'body' => ['text' => 'Hi {{1}}, our new collection', 'example' => ['Sara']],
            'carousel' => [
                'format' => 'IMAGE',
                'cards' => [
                    [
                        'header' => ['example' => $this->image('one.jpg')],
                        'body' => ['text' => 'Shoe {{1}}', 'example' => ['red']],
                        'buttons' => [
                            ['type' => 'QUICK_REPLY', 'text' => 'Buy'],
                            ['type' => 'URL', 'text' => 'Shop now', 'url' => 'https://shop.test/p/{{1}}', 'example' => 'shoe-1'],
                        ],
                    ],
                    [
                        'header' => ['example' => $this->image('two.jpg')],
                        'body' => ['text' => 'Bag {{1}}', 'example' => ['blue']],
                        'buttons' => [
                            ['type' => 'QUICK_REPLY', 'text' => 'Buy'],
                            ['type' => 'URL', 'text' => 'Shop now', 'url' => 'https://shop.test/p/{{1}}', 'example' => 'bag-1'],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function create(array $input)
    {
        return $this->actingAs($this->owner)
            ->withSession(['current_organization' => $this->organization->id])
            ->post('/templates/create', $input, ['Accept' => 'application/json']);
    }

    // ------------------------------------------------ النجاح

    public function test_a_valid_carousel_reaches_meta_in_the_documented_shape(): void
    {
        $response = $this->create($this->validInput());

        $response->assertOk()->assertJsonPath('success', true);

        // جلستا رفع، ورفعان، وإنشاء واحد.
        $this->assertCount(2, $this->graphCalls('/1/uploads'));
        $this->assertCount(1, $this->graphCalls('/3/message_templates'));

        $sent = $this->graphJson($this->graphCalls('/3/message_templates')[0]);
        $this->assertSame('summer_shoes', $sent['name']);
        $this->assertSame('MARKETING', $sent['category']);
        $this->assertSame(['BODY', 'CAROUSEL'], array_column($sent['components'], 'type'));
        $this->assertSame(['body_text' => [['Sara']]], $sent['components'][0]['example']);

        $cards = $sent['components'][1]['cards'];
        $this->assertCount(2, $cards);
        $this->assertSame('IMAGE', $cards[0]['components'][0]['format']);
        $this->assertMatchesRegularExpression('/^4::H\d+$/', $cards[0]['components'][0]['example']['header_handle'][0]);
        $this->assertNotSame(
            $cards[0]['components'][0]['example']['header_handle'][0],
            $cards[1]['components'][0]['example']['header_handle'][0],
            'لكل بطاقة ملفّها'
        );
        $this->assertSame(['type' => 'BODY', 'text' => 'Bag {{1}}', 'example' => ['body_text' => [['blue']]]], $cards[1]['components'][1]);
        $this->assertSame([
            ['type' => 'QUICK_REPLY', 'text' => 'Buy'],
            ['type' => 'URL', 'text' => 'Shop now', 'url' => 'https://shop.test/p/{{1}}', 'example' => ['shoe-1']],
        ], $cards[0]['components'][2]['buttons']);
    }

    public function test_the_template_row_keeps_the_payload_and_our_copies_of_the_media(): void
    {
        $this->create($this->validInput())->assertJsonPath('success', true);

        $template = Template::where('organization_id', $this->organization->id)->where('name', 'summer_shoes')->firstOrFail();
        $sent = $this->graphJson($this->graphCalls('/3/message_templates')[0]);

        $this->assertSame('T1', $template->meta_id);
        $this->assertSame('PENDING', $template->status);
        $this->assertSame('MARKETING', $template->category);
        $this->assertSame($sent, json_decode($template->metadata, true));
        $this->assertTrue(CarouselDefinition::isCarousel($template->metadata));

        $media = json_decode($template->carousel_media, true);
        $this->assertSame('IMAGE', $media['format']);
        $this->assertCount(2, $media['cards']);
        foreach ($media['cards'] as $card) {
            $this->assertStringContainsString('/media/public/', $card['url']);
            Storage::disk('local')->assertExists(substr($card['url'], strpos($card['url'], 'public/')));
        }
    }

    public function test_a_video_carousel_without_card_text(): void
    {
        $input = $this->validInput();
        $input['carousel']['format'] = 'VIDEO';
        foreach ($input['carousel']['cards'] as $i => $card) {
            $input['carousel']['cards'][$i]['header']['example'] = UploadedFile::fake()->create("v$i.mp4", 500, 'video/mp4');
            unset($input['carousel']['cards'][$i]['body']);
        }

        $this->create($input)->assertJsonPath('success', true);

        $cards = $this->graphJson($this->graphCalls('/3/message_templates')[0])['components'][1]['cards'];
        $this->assertSame('VIDEO', $cards[0]['components'][0]['format']);
        $this->assertSame(['HEADER', 'BUTTONS'], array_column($cards[1]['components'], 'type'));
    }

    // ------------------------------------------------ الرفض قبل Meta

    public static function invalidInputs(): array
    {
        return [
            'بطاقة واحدة' => [fn (array $in) => ['_drop_card' => 1] + $in],
            'إحدى عشرة بطاقة' => [fn (array $in) => ['_cards' => 11] + $in],
            'تصنيف خدمي' => [fn (array $in) => array_replace($in, ['category' => 'UTILITY'])],
            'نصّ بطاقة دون الأخرى' => [fn (array $in) => self::without($in, 'carousel.cards.1.body')],
            'ترتيب أزرار مختلف' => [fn (array $in) => self::reverseButtons($in, 1)],
            'ثلاثة أزرار' => [fn (array $in) => self::addButton($in)],
            'نصّ بطاقة 161 حرفاً' => [fn (array $in) => self::set($in, 'carousel.cards.0.body.text', str_repeat('a', 161))],
            'متن 1025 حرفاً' => [fn (array $in) => self::set($in, 'body.text', str_repeat('a', 1025))],
            'متغيّر الرابط ليس في آخره' => [fn (array $in) => self::set($in, 'carousel.cards.0.buttons.1.url', 'https://shop.test/{{1}}/x')],
            'مثال متغيّر ناقص' => [fn (array $in) => self::set($in, 'body.example', [])],
            'مثال الرابط ناقص' => [fn (array $in) => self::set($in, 'carousel.cards.1.buttons.1.example', '')],
            'اسم بأحرف كبيرة' => [fn (array $in) => array_replace($in, ['name' => 'Summer Shoes'])],
        ];
    }

    /** @dataProvider invalidInputs */
    public function test_invalid_carousels_never_reach_meta(callable $mutate): void
    {
        $input = $mutate($this->validInput());

        if (isset($input['_drop_card'])) {
            $input['carousel']['cards'] = [$input['carousel']['cards'][0]];
            unset($input['_drop_card']);
        }

        if (isset($input['_cards'])) {
            $input['carousel']['cards'] = array_map(fn () => [
                'header' => ['example' => $this->image()],
                'body' => ['text' => 'x'],
                'buttons' => [['type' => 'QUICK_REPLY', 'text' => 'Buy']],
            ], range(1, $input['_cards']));
            unset($input['_cards']);
        }

        $response = $this->create($input);

        $response->assertOk()->assertJsonPath('success', false);
        $this->assertNotEmpty($response->json('message'));
        $this->assertSame([], $this->graphRequests, 'لا طلب إلى Meta على مدخل مرفوض');
        $this->assertSame(0, Template::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_an_mp4_in_an_image_carousel_is_rejected(): void
    {
        $input = $this->validInput();
        $input['carousel']['cards'][1]['header']['example'] = UploadedFile::fake()->create('v.mp4', 500, 'video/mp4');

        $this->create($input)->assertJsonPath('success', false);

        $this->assertSame([], $this->graphRequests);
    }

    // ------------------------------------------------ أخطاء Meta

    public function test_a_meta_rejection_is_reported_and_nothing_is_kept(): void
    {
        $this->graphOverrides['/3/message_templates'] = fn () => $this->graphResponse(['error' => [
            'message' => 'Invalid parameter',
            'error_user_msg' => 'Template name already exists',
            'code' => 100,
        ]], 400);

        $response = $this->create($this->validInput());

        $response->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Template name already exists');
        $this->assertSame(0, Template::count());
        $this->assertSame([], Storage::disk('local')->allFiles(), 'لا نسخ للوسائط إن رفضت Meta القالب');
    }

    public function test_a_failed_media_upload_stops_before_creating_the_template(): void
    {
        $this->graphOverrides['/1/uploads'] = fn () => $this->graphResponse(['error' => ['message' => 'Upload session failed']], 400);

        $response = $this->create($this->validInput());

        $response->assertJsonPath('success', false)->assertJsonPath('message', 'Upload session failed');
        $this->assertSame([], $this->graphCalls('/3/message_templates'));
        $this->assertSame(0, Template::count());
    }

    // ------------------------------------------------ القالب العادي

    /**
     * طلب بلا template_type يذهب إلى createTemplate القديمة كما هي (وهي
     * تبني عميلها بـ new، فلا نعترض طلبها هنا — ولم نغيّرها).
     */
    public function test_a_standard_request_still_takes_the_old_path(): void
    {
        $response = $this->create(['name' => null, 'category' => 'UTILITY', 'language' => 'ar']);

        $response->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Some required fields have not been filled');
    }

    // ------------------------------------------------ أدوات

    private static function set(array $input, string $path, $value): array
    {
        data_set($input, $path, $value);

        return $input;
    }

    private static function without(array $input, string $path): array
    {
        $segments = explode('.', $path);
        $last = array_pop($segments);
        $target = &$input;
        foreach ($segments as $segment) {
            $target = &$target[$segment];
        }
        unset($target[$last]);

        return $input;
    }

    private static function reverseButtons(array $input, int $card): array
    {
        $input['carousel']['cards'][$card]['buttons'] = array_reverse($input['carousel']['cards'][$card]['buttons']);

        return $input;
    }

    private static function addButton(array $input): array
    {
        foreach ($input['carousel']['cards'] as $i => $card) {
            $input['carousel']['cards'][$i]['buttons'][] = ['type' => 'QUICK_REPLY', 'text' => 'More'];
        }

        return $input;
    }
}
