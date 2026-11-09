<?php

namespace Tests\Feature\Carousel;

use App\Jobs\ProcessSingleCampaignLogJob;
use App\Models\Campaign;
use App\Models\CampaignLog;
use App\Models\Chat;
use App\Models\Template;
use Illuminate\Support\Str;

/**
 * إرسال حملة carousel من أوّلها إلى آخرها: سجلّ الحملة ← المهمّة ← الطلب
 * إلى Meta ← رسالة المحادثة.
 *
 * ولقطة لحملة عادية بنفس المسار، كُتبت على الكود قبل الـ carousel ومرّت
 * عليه: الطلب إلى Meta ورسالة المحادثة حرفاً بحرف.
 */
class CarouselCampaignSendTest extends CarouselTestCase
{
    private function campaign(Template $template, array $metadata): CampaignLog
    {
        $campaign = Campaign::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'name' => 'Summer',
            'status' => 'ongoing',
            'template_id' => $template->id,
            'contact_group_id' => 0,
            'metadata' => json_encode($metadata),
            'created_by' => $this->owner->id,
            'scheduled_at' => now(),
        ]);

        return CampaignLog::create([
            'campaign_id' => $campaign->id,
            'contact_id' => $this->contact->id,
            'status' => 'pending',
        ]);
    }

    private function carouselMetadata(): array
    {
        return [
            'header' => ['format' => null, 'parameters' => []],
            'body' => ['text' => 'Hi {{1}}', 'parameters' => [['type' => 'text', 'selection' => 'dynamic', 'value' => 'first name']]],
            'footer' => ['text' => null],
            'buttons' => [],
            'media' => null,
            'carousel' => [
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
                        'header' => ['type' => 'IMAGE', 'selection' => 'upload', 'value' => 'https://app.test/media/public/new.jpg', 'chat_media_id' => 7],
                        'body' => ['text' => 'Bag {{1}}', 'parameters' => [['type' => 'text', 'selection' => 'dynamic', 'value' => 'last name']]],
                        'buttons' => [
                            ['index' => 0, 'type' => 'QUICK_REPLY', 'text' => 'Buy', 'url' => null, 'parameters' => [['selection' => 'static', 'value' => '']]],
                            ['index' => 1, 'type' => 'URL', 'text' => 'Open', 'url' => 'https://shop.test/p/{{1}}', 'parameters' => [['selection' => 'static', 'value' => 'sku-2']]],
                        ],
                    ],
                ],
            ],
        ];
    }

    public function test_the_carousel_campaign_reaches_meta_and_the_chat(): void
    {
        $log = $this->campaign($this->makeCarouselTemplate(), $this->carouselMetadata());

        (new ProcessSingleCampaignLogJob($log))->handle();

        $calls = $this->graphCalls('/2/messages');
        $this->assertCount(1, $calls);

        $sent = $this->graphJson($calls[0]);
        $this->assertSame('+966500000001', $sent['to']);
        $this->assertSame([
            'name' => 'shoes',
            'language' => ['code' => 'ar'],
            'components' => [
                ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => 'Sara']]],
                ['type' => 'carousel', 'cards' => [
                    ['card_index' => 0, 'components' => [
                        ['type' => 'header', 'parameters' => [['type' => 'image', 'image' => ['link' => 'https://app.test/media/public/one.jpg']]]],
                        ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => 'red']]],
                        ['type' => 'button', 'sub_type' => 'quick_reply', 'index' => 0, 'parameters' => [['type' => 'payload', 'payload' => 'card1_buy']]],
                        ['type' => 'button', 'sub_type' => 'url', 'index' => 1, 'parameters' => [['type' => 'text', 'text' => 'sku-1']]],
                    ]],
                    ['card_index' => 1, 'components' => [
                        ['type' => 'header', 'parameters' => [['type' => 'image', 'image' => ['link' => 'https://app.test/media/public/new.jpg']]]],
                        ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => 'Ali']]],
                        ['type' => 'button', 'sub_type' => 'url', 'index' => 1, 'parameters' => [['type' => 'text', 'text' => 'sku-2']]],
                    ]],
                ]],
            ],
        ], $sent['template']);

        $log->refresh();
        $this->assertSame('success', $log->status);
        $this->assertNotNull($log->chat_id);

        $chat = Chat::findOrFail($log->chat_id);
        $this->assertNull($chat->media_id);
        $this->assertSame([
            'type' => 'text',
            'text' => ['body' => 'Hi Sara'],
            'carousel' => [
                'format' => 'IMAGE',
                'cards' => [
                    ['media' => 'https://app.test/media/public/one.jpg', 'body' => 'Shoe red', 'buttons' => [['type' => 'QUICK_REPLY', 'text' => 'Buy'], ['type' => 'URL', 'text' => 'Open']]],
                    ['media' => 'https://app.test/media/public/new.jpg', 'body' => 'Bag Ali', 'buttons' => [['type' => 'QUICK_REPLY', 'text' => 'Buy'], ['type' => 'URL', 'text' => 'Open']]],
                ],
            ],
        ], json_decode($chat->metadata, true));
    }

    /**
     * عطب في بناء بطاقات المحادثة يأتي بعد قبول Meta للرسالة. لو رمى لفشل
     * السجلّ وأُعيد الإرسال فوصلت الرسالة مرّتين. فيُبلع ويُحفظ الشكل العادي.
     */
    public function test_a_broken_chat_carousel_never_fails_an_accepted_message(): void
    {
        $metadata = $this->carouselMetadata();
        $metadata['carousel']['cards'][1]['body']['text'] = ['not' => 'a string'];
        $log = $this->campaign($this->makeCarouselTemplate(), $metadata);

        (new ProcessSingleCampaignLogJob($log))->handle();

        $this->assertCount(1, $this->graphCalls('/2/messages'));
        $log->refresh();
        $this->assertSame('success', $log->status);
        $this->assertSame(
            ['type' => 'text', 'text' => ['body' => 'Hi Sara']],
            json_decode(Chat::findOrFail($log->chat_id)->metadata, true)
        );
    }

    /** لقطة الحملة العادية: لم يتغيّر فيها حرف. */
    public function test_a_standard_campaign_is_sent_exactly_as_before(): void
    {
        $log = $this->campaign($this->makeStandardTemplate(), [
            'header' => ['format' => 'TEXT', 'text' => 'Hello {{1}}', 'parameters' => [['type' => 'text', 'selection' => 'static', 'value' => 'Sara']]],
            'body' => ['text' => 'Your code is {{1}}', 'parameters' => [['type' => 'text', 'selection' => 'static', 'value' => 'SAVE']]],
            'footer' => ['text' => 'Mnjz'],
            'buttons' => [['type' => 'QUICK_REPLY', 'text' => 'Yes', 'value' => null, 'parameters' => [['type' => 'static', 'value' => 'yes']]]],
            'media' => null,
        ]);

        (new ProcessSingleCampaignLogJob($log))->handle();

        $this->assertSame([
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => '+966500000001',
            'type' => 'template',
            'template' => [
                'name' => 'standard_promo',
                'language' => ['code' => 'ar'],
                'components' => [
                    ['type' => 'header', 'parameters' => [['type' => 'text', 'text' => 'Sara']]],
                    ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => 'SAVE']]],
                    ['type' => 'button', 'sub_type' => 'quick_reply', 'index' => 0, 'parameters' => [['type' => 'payload', 'payload' => 'yes']]],
                ],
            ],
        ], $this->graphJson($this->graphCalls('/2/messages')[0]));

        $log->refresh();
        $this->assertSame('success', $log->status);
        $this->assertSame(
            '{"type":"text","header":{"text":"Hello Sara"},"text":{"body":"Your code is SAVE","footer":"Mnjz"},"buttons":[{"type":"QUICK_REPLY","text":"Yes","value":null,"parameters":[{"type":"static","value":"yes"}]}]}',
            Chat::findOrFail($log->chat_id)->metadata
        );
    }
}
