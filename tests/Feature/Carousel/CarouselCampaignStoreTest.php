<?php

namespace Tests\Feature\Carousel;

use App\Http\Requests\StoreCampaign;
use App\Models\Campaign;
use App\Models\CampaignMediaHistory;
use App\Models\ChatMedia;
use App\Services\CampaignMediaHistoryService;
use Illuminate\Support\Facades\Storage;

/**
 * حفظ حملة بقالب carousel.
 *
 * ما يُحرَس: أن الطلب يطابق بطاقات القالب (عددها، صيغتها، وسائط كل بطاقة،
 * وقيم متغيّراتها) قبل الحفظ، وأن النصوص تُؤخذ من القالب لا من المتصفح،
 * وأن مفاتيح header/footer/buttons تبقى في metadata لأن مسار الإرسال يقرؤها.
 */
class CarouselCampaignStoreTest extends CarouselTestCase
{
    private function payload($template, ?array $carousel): array
    {
        $payload = [
            'name' => 'Summer',
            'template' => (string) $template->uuid,
            'contacts' => 'all',
            'skip_schedule' => true,
            'header' => ['format' => null, 'parameters' => []],
            'body' => ['text' => 'Hi {{1}}', 'parameters' => [['type' => 'text', 'selection' => 'dynamic', 'value' => 'first name']]],
            'footer' => ['text' => null],
            'buttons' => [],
        ];

        if ($carousel !== null) {
            $payload['carousel'] = $carousel;
        }

        return $payload;
    }

    private function store(array $payload)
    {
        return $this->actingAs($this->owner)
            ->withSession(['current_organization' => $this->organization->id])
            ->post('/campaigns', $payload, ['Accept' => 'application/json']);
    }

    private function latestMetadata(): array
    {
        return json_decode(Campaign::latest('id')->firstOrFail()->metadata, true);
    }

    // ------------------------------------------------ الحفظ

    public function test_template_media_defaults_produce_the_full_carousel_metadata(): void
    {
        $template = $this->makeCarouselTemplate();

        $this->store($this->payload($template, $this->carouselSendInput([
            ['selection' => 'default'],
            ['selection' => 'default'],
        ])))->assertRedirect();

        $metadata = $this->latestMetadata();

        $this->assertSame(['format' => null, 'parameters' => []], $metadata['header']);
        $this->assertSame(['text' => null], $metadata['footer']);
        $this->assertSame([], $metadata['buttons']);
        $this->assertNull($metadata['media']);
        $this->assertSame([
            'format' => 'IMAGE',
            'cards' => [
                [
                    'card_index' => 0,
                    'header' => ['type' => 'IMAGE', 'selection' => 'default', 'value' => 'https://app.test/media/public/one.jpg', 'chat_media_id' => null],
                    'body' => ['text' => 'Shoe {{1}}', 'parameters' => [['type' => 'text', 'selection' => 'static', 'value' => 'red']]],
                    'buttons' => [
                        ['index' => 0, 'type' => 'QUICK_REPLY', 'text' => 'Buy', 'url' => null, 'parameters' => [['selection' => 'static', 'value' => 'card1_buy']]],
                        ['index' => 1, 'type' => 'URL', 'text' => 'Open', 'url' => 'https://shop.test/p/{{1}}', 'parameters' => [['selection' => 'static', 'value' => 'sku-1']]],
                    ],
                ],
                [
                    'card_index' => 1,
                    'header' => ['type' => 'IMAGE', 'selection' => 'default', 'value' => 'https://app.test/media/public/two.jpg', 'chat_media_id' => null],
                    'body' => ['text' => 'Bag {{1}}', 'parameters' => [['type' => 'text', 'selection' => 'static', 'value' => 'blue']]],
                    'buttons' => [
                        ['index' => 0, 'type' => 'QUICK_REPLY', 'text' => 'Buy', 'url' => null, 'parameters' => [['selection' => 'static', 'value' => '']]],
                        ['index' => 1, 'type' => 'URL', 'text' => 'Open', 'url' => 'https://shop.test/p/{{1}}', 'parameters' => [['selection' => 'static', 'value' => 'sku-2']]],
                    ],
                ],
            ],
        ], $metadata['carousel']);
    }

    public function test_an_uploaded_card_is_stored_and_recorded_in_history(): void
    {
        $template = $this->makeCarouselTemplate();

        $this->store($this->payload($template, $this->carouselSendInput([
            ['selection' => 'upload', 'value' => $this->image('new.jpg')],
            ['selection' => 'default'],
        ])))->assertRedirect();

        $card = $this->latestMetadata()['carousel']['cards'][0]['header'];

        $this->assertSame('upload', $card['selection']);
        $this->assertStringContainsString('/media/public/', $card['value']);
        Storage::disk('local')->assertExists(substr($card['value'], strpos($card['value'], 'public/')));
        $this->assertSame($card['value'], ChatMedia::find($card['chat_media_id'])->path);
        $this->assertTrue(CampaignMediaHistory::where('path', $card['value'])->where('media_type', 'IMAGE')->exists());
    }

    public function test_a_previously_used_file_can_be_chosen_for_a_card(): void
    {
        $template = $this->makeCarouselTemplate(false);
        $item = app(CampaignMediaHistoryService::class)->record(
            $this->organization->id, $this->owner->id, 'IMAGE', 'old.jpg', 'https://s3.test/old.jpg', 'amazon', 'image/jpeg', '10'
        )->fresh();

        $this->store($this->payload($template, $this->carouselSendInput([
            ['selection' => 'history', 'value' => (string) $item->uuid],
            ['selection' => 'upload', 'value' => $this->image()],
        ])))->assertRedirect();

        $this->assertSame('https://s3.test/old.jpg', $this->latestMetadata()['carousel']['cards'][0]['header']['value']);
    }

    /** النصوص وتسميات الأزرار من القالب: ما يرسله المتصفح منها يُتجاهل. */
    public function test_card_texts_come_from_the_template_not_the_browser(): void
    {
        $template = $this->makeCarouselTemplate();
        $carousel = $this->carouselSendInput([['selection' => 'default'], ['selection' => 'default']]);
        $carousel['cards'][0]['body']['text'] = 'HACKED {{1}}';
        $carousel['cards'][0]['buttons'][0]['text'] = 'HACKED';
        $carousel['cards'][0]['buttons'][2] = ['type' => 'URL', 'parameters' => [['selection' => 'static', 'value' => 'x']]];

        $this->store($this->payload($template, $carousel))->assertRedirect();

        $card = $this->latestMetadata()['carousel']['cards'][0];
        $this->assertSame('Shoe {{1}}', $card['body']['text']);
        $this->assertSame('Buy', $card['buttons'][0]['text']);
        $this->assertCount(2, $card['buttons']);
    }

    // ------------------------------------------------ الرفض

    /** قالب المزامنة بلا نسخنا من الوسائط: «الافتراضي» غير متاح. */
    public function test_a_synced_template_requires_an_upload_for_every_card(): void
    {
        $template = $this->makeCarouselTemplate(false);

        $this->store($this->payload($template, $this->carouselSendInput([
            ['selection' => 'default'],
            ['selection' => 'upload', 'value' => $this->image()],
        ])))->assertStatus(422)->assertJsonValidationErrors(['carousel.cards.0.header.value']);

        $this->assertSame(0, Campaign::count());
    }

    public function test_a_card_without_media_is_rejected(): void
    {
        $template = $this->makeCarouselTemplate();

        $this->store($this->payload($template, $this->carouselSendInput([
            ['selection' => 'default'],
            ['selection' => 'upload', 'value' => null],
        ])))->assertStatus(422)->assertJsonValidationErrors(['carousel.cards.1.header.value']);
    }

    public function test_a_video_in_an_image_carousel_is_rejected(): void
    {
        $template = $this->makeCarouselTemplate();

        $this->store($this->payload($template, $this->carouselSendInput([
            ['selection' => 'upload', 'value' => \Illuminate\Http\UploadedFile::fake()->create('v.mp4', 100, 'video/mp4')],
            ['selection' => 'default'],
        ])))->assertStatus(422)->assertJsonValidationErrors(['carousel.cards.0.header.value']);
    }

    public function test_a_different_card_count_is_rejected(): void
    {
        $template = $this->makeCarouselTemplate();
        $carousel = $this->carouselSendInput([['selection' => 'default'], ['selection' => 'default'], ['selection' => 'default']]);

        $this->store($this->payload($template, $carousel))
            ->assertStatus(422)->assertJsonValidationErrors(['carousel']);
    }

    public function test_a_different_format_is_rejected(): void
    {
        $template = $this->makeCarouselTemplate();
        $carousel = $this->carouselSendInput([['selection' => 'default'], ['selection' => 'default']], 'VIDEO');

        $this->store($this->payload($template, $carousel))
            ->assertStatus(422)->assertJsonValidationErrors(['carousel']);
    }

    public function test_a_carousel_template_without_cards_is_rejected(): void
    {
        $template = $this->makeCarouselTemplate();

        $this->store($this->payload($template, null))
            ->assertStatus(422)->assertJsonValidationErrors(['carousel']);

        $this->assertSame(0, Campaign::count());
    }

    public function test_an_empty_link_variable_is_rejected(): void
    {
        $template = $this->makeCarouselTemplate();
        $carousel = $this->carouselSendInput([['selection' => 'default'], ['selection' => 'default']]);
        $carousel['cards'][1]['buttons'][1]['parameters'] = [];

        $this->store($this->payload($template, $carousel))
            ->assertStatus(422)->assertJsonValidationErrors(['carousel.cards.1.buttons.1.parameters.0.value']);
    }

    // ------------------------------------------------ القالب العادي

    public function test_a_standard_template_ignores_stray_carousel_input(): void
    {
        $template = $this->makeStandardTemplate();
        $payload = [
            'name' => 'Plain',
            'template' => (string) $template->uuid,
            'contacts' => 'all',
            'skip_schedule' => true,
            'header' => ['format' => 'TEXT', 'text' => 'Hello {{1}}', 'parameters' => [['type' => 'text', 'selection' => 'static', 'value' => 'Sara']]],
            'body' => ['text' => 'Your code is {{1}}', 'parameters' => [['type' => 'text', 'selection' => 'static', 'value' => 'SAVE']]],
            'footer' => ['text' => 'Mnjz'],
            'buttons' => [['type' => 'QUICK_REPLY', 'text' => 'Yes', 'value' => null, 'parameters' => [['type' => 'static', 'value' => 'yes']]]],
        ];

        $this->store($payload)->assertRedirect();
        $plain = $this->latestMetadata();

        $this->store($payload + ['carousel' => $this->carouselSendInput([['selection' => 'default'], ['selection' => 'default']])])->assertRedirect();
        $withStray = $this->latestMetadata();

        $this->assertSame($plain, $withStray);
        $this->assertArrayNotHasKey('carousel', $withStray);
    }

    public function test_standard_validation_rules_are_unchanged(): void
    {
        $payload = [
            'name' => 'x', 'template' => 'u', 'contacts' => 'all', 'skip_schedule' => true,
            'header' => ['format' => 'IMAGE', 'parameters' => [['type' => 'IMAGE', 'selection' => 'default', 'value' => 'https://s3.test/a.jpg']]],
            'body' => ['parameters' => [['type' => 'text', 'selection' => 'static', 'value' => 'a']]],
            'buttons' => [],
        ];

        $rules = StoreCampaign::create('/campaigns', 'POST', $payload)->rules();

        $this->assertSame([], array_values(array_filter(array_keys($rules), fn ($key) => str_starts_with($key, 'carousel'))));
        $this->assertSame('required|url|max:2048', $rules['header.parameters.0.value']);
    }
}
