<?php

namespace Tests\Feature\Carousel;

use App\Models\Chat;
use App\Models\Organization;
use App\Models\Template;
use Illuminate\Support\Str;

/**
 * إرسال قالب carousel لجهة اتصال واحدة من المحادثة.
 *
 * ولقطة لقالب عادي بنفس المسار، كُتبت على الكود قبل الـ carousel ومرّت
 * عليه.
 */
class CarouselChatSendTest extends CarouselTestCase
{
    private function send(Template $template, array $extra)
    {
        return $this->actingAs($this->owner)
            ->withSession(['current_organization' => $this->organization->id])
            ->from('/chats/' . $this->contact->uuid)
            ->post('/chat/' . $this->contact->uuid . '/send/template', ['template' => (string) $template->uuid] + $extra);
    }

    private function carouselRequest(array $headers): array
    {
        return [
            'header' => ['format' => null, 'parameters' => []],
            'body' => ['text' => 'Hi {{1}}', 'parameters' => [['type' => 'text', 'selection' => 'static', 'value' => 'Sara']]],
            'footer' => ['text' => null],
            'buttons' => [],
            'carousel' => $this->carouselSendInput($headers),
        ];
    }

    public function test_a_carousel_is_sent_from_the_chat(): void
    {
        $template = $this->makeCarouselTemplate();

        $this->send($template, $this->carouselRequest([
            ['selection' => 'default'],
            ['selection' => 'upload', 'value' => $this->image('fresh.jpg')],
        ]))->assertRedirect()->assertSessionHas('status.type', 'success');

        $calls = $this->graphCalls('/2/messages');
        $this->assertCount(1, $calls);

        $components = $this->graphJson($calls[0])['template']['components'];
        $this->assertSame(['body', 'carousel'], array_column($components, 'type'));
        $this->assertSame([['type' => 'text', 'text' => 'Sara']], $components[0]['parameters']);

        $cards = $components[1]['cards'];
        $this->assertSame('https://app.test/media/public/one.jpg', $cards[0]['components'][0]['parameters'][0]['image']['link']);
        $this->assertStringContainsString('/media/public/', $cards[1]['components'][0]['parameters'][0]['image']['link']);
        $this->assertNotSame('https://app.test/media/public/two.jpg', $cards[1]['components'][0]['parameters'][0]['image']['link'], 'المرفوع للتوّ لا الافتراضي');

        $chat = json_decode(Chat::latest('id')->firstOrFail()->metadata, true);
        $this->assertSame('Hi Sara', $chat['text']['body']);
        $this->assertSame('Shoe red', $chat['carousel']['cards'][0]['body']);
        $this->assertSame('https://app.test/media/public/one.jpg', $chat['carousel']['cards'][0]['media']);
        $this->assertSame([['type' => 'QUICK_REPLY', 'text' => 'Buy'], ['type' => 'URL', 'text' => 'Open']], $chat['carousel']['cards'][1]['buttons']);
    }

    public function test_a_card_without_media_returns_errors_and_sends_nothing(): void
    {
        $template = $this->makeCarouselTemplate(false);

        $this->send($template, $this->carouselRequest([
            ['selection' => 'default'],
            ['selection' => 'upload', 'value' => $this->image()],
        ]))->assertRedirect()->assertSessionHasErrors(['carousel.cards.0.header.value']);

        $this->assertSame([], $this->graphCalls('/2/messages'));
        $this->assertSame(0, Chat::count());
    }

    public function test_a_template_of_another_organization_is_refused(): void
    {
        $other = Organization::factory()->create(['created_by' => $this->owner->id]);
        $template = $this->makeCarouselTemplate();
        $template->update(['organization_id' => $other->id]);

        $this->send($template, $this->carouselRequest([['selection' => 'default'], ['selection' => 'default']]))
            ->assertRedirect()
            ->assertSessionHas('status.type', 'error');

        $this->assertSame([], $this->graphCalls('/2/messages'));
    }

    /** لقطة القالب العادي من المحادثة: لم يتغيّر فيها حرف. */
    public function test_a_standard_template_is_sent_exactly_as_before(): void
    {
        $template = $this->makeStandardTemplate();

        $this->send($template, [
            'header' => ['format' => 'TEXT', 'text' => 'Hello {{1}}', 'parameters' => [['type' => 'text', 'selection' => 'static', 'value' => 'Sara']]],
            'body' => ['text' => 'Your code is {{1}}', 'parameters' => [['type' => 'text', 'selection' => 'static', 'value' => 'SAVE']]],
            'footer' => ['text' => 'Mnjz'],
            'buttons' => [['type' => 'QUICK_REPLY', 'text' => 'Yes', 'value' => null, 'parameters' => [['type' => 'static', 'value' => 'yes']]]],
        ])->assertRedirect()->assertSessionHas('status.type', 'success');

        $this->assertSame([
            'name' => 'standard_promo',
            'language' => ['code' => 'ar'],
            'components' => [
                ['type' => 'header', 'parameters' => [['type' => 'text', 'text' => 'Sara']]],
                ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => 'SAVE']]],
                ['type' => 'button', 'sub_type' => 'quick_reply', 'index' => 0, 'parameters' => [['type' => 'payload', 'payload' => 'yes']]],
            ],
        ], $this->graphJson($this->graphCalls('/2/messages')[0])['template']);

        $this->assertSame(
            '{"type":"text","header":{"text":"Hello Sara"},"text":{"body":"Your code is SAVE","footer":"Mnjz"},"buttons":[{"type":"QUICK_REPLY","text":"Yes","value":"Yes"}]}',
            Chat::latest('id')->firstOrFail()->metadata
        );
    }
}
