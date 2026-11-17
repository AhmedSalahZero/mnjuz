<?php

namespace Tests\Feature\Campaigns;

use App\Models\Campaign;
use App\Models\ChannelAccount;
use App\Models\ChannelTemplate;
use App\Services\Email\EmailDesign;
use App\Services\Email\MerchantSmtpTransportFactory;
use App\Services\Messaging\Channel;
use Tests\Support\FakeSmtpTransportFactory;

/**
 * حملة بريد من قالب مصمَّم: التصميم يُنسخ في الحملة، والرسالة تُبنى منه
 * لكل مستلم (متغيّرات مستبدلة ثم مهرَّبة)، والنسخة النصّية والتذييل كما في
 * الحملة النصّية.
 */
class EmailDesignCampaignTest extends CampaignTestCase
{
    private FakeSmtpTransportFactory $smtp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->smtp = new FakeSmtpTransportFactory();
        $this->app->instance(MerchantSmtpTransportFactory::class, $this->smtp);

        ChannelAccount::create([
            'organization_id' => $this->organization->id,
            'channel' => Channel::EMAIL,
            'external_id' => 'org:' . $this->organization->id,
            'name' => 'متجر المثال',
            'username' => 'sales@shop.example',
            'credentials' => ['host' => 'smtp.example.com', 'port' => 587, 'encryption' => 'tls', 'username' => 'sales@shop.example', 'password' => 'pw'],
            'status' => ChannelAccount::STATUS_ACTIVE,
            'created_by' => $this->owner->id,
        ]);
    }

    private function designedTemplate(): ChannelTemplate
    {
        $design = EmailDesign::normalize([
            'settings' => ['direction' => 'rtl', 'page_bg' => '#eeeeee'],
            'blocks' => [
                ['type' => 'header', 'props' => ['text' => '{organization_name}', 'bg_color' => '#1b5e20']],
                ['type' => 'heading', 'props' => ['text' => 'أهلاً {first_name}']],
                ['type' => 'button', 'props' => ['label' => 'احجز الآن', 'url' => 'https://shop.example/book?n={url:first_name}']],
                ['type' => 'image', 'props' => ['src' => 'https://cdn.example.com/banner.png', 'alt' => 'عرض']],
            ],
        ], 'ar');

        return ChannelTemplate::create([
            'organization_id' => $this->organization->id,
            'channel' => 'email',
            'name' => 'اليوم الوطني',
            'subject' => 'عرض لك يا {first_name}',
            'body' => EmailDesign::toPlainText($design),
            'design' => $design,
        ]);
    }

    private function campaignFrom(ChannelTemplate $template): void
    {
        $this->createFromWeb([
            'name' => 'حملة مصمَّمة',
            'channel' => 'email',
            'channel_template' => (string) $template->uuid,
            'contacts' => 'all',
            'skip_schedule' => true,
        ])->assertRedirect();
    }

    private function sendAll(): void
    {
        $this->schedulerMinute();
        for ($i = 0; $i < 3; $i++) {
            $this->dispatcherTick();
        }
    }

    public function test_the_design_is_copied_into_the_campaign(): void
    {
        $this->customer('+966500000002', 'نورة', ['email' => 'a@x.com']);
        $template = $this->designedTemplate();
        $this->campaignFrom($template);

        $campaign = Campaign::latest('id')->first();
        $this->assertSame(
            ['header', 'heading', 'button', 'image'],
            array_column($campaign->payloadValue('design')['blocks'], 'type')
        );
        $this->assertSame($template->body, $campaign->messagePayload()['text']);
    }

    public function test_the_designed_email_is_rendered_per_recipient_and_escaped(): void
    {
        $this->customer('+966500000002', '<b>نورة</b>', ['email' => 'a@x.com']);
        $this->campaignFrom($this->designedTemplate());
        $this->sendAll();

        $this->assertCount(1, $this->smtp->sent());
        $email = $this->smtp->sent()[0];
        $html = (string) $email->getHtmlBody();

        $this->assertSame('عرض لك يا <b>نورة</b>', $email->getSubject());
        $this->assertStringContainsString('أهلاً &lt;b&gt;نورة&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('<b>نورة</b>', $html);
        $this->assertStringContainsString(e($this->organization->name), $html);
        $this->assertStringContainsString('background:#1b5e20', $html);
        $this->assertStringContainsString('href="https://shop.example/book?n=' . e(rawurlencode('<b>نورة</b>')) . '"', $html);
        $this->assertStringContainsString('src="https://cdn.example.com/banner.png"', $html);
        $this->assertStringContainsString('/email/unsubscribe/', $html);

        $text = (string) $email->getTextBody();
        $this->assertStringContainsString('أهلاً <b>نورة</b>', $text);
        $this->assertStringContainsString('احجز الآن: https://shop.example/book?n=' . rawurlencode('<b>نورة</b>'), $text);
        $this->assertStringContainsString('/email/unsubscribe/', $text);
    }

    public function test_editing_the_template_later_does_not_change_the_campaign(): void
    {
        $this->customer('+966500000002', 'نورة', ['email' => 'a@x.com']);
        $template = $this->designedTemplate();
        $this->campaignFrom($template);

        $template->update(['design' => EmailDesign::fromPlainText('محتوى آخر')]);
        $this->sendAll();

        $html = (string) $this->smtp->sent()[0]->getHtmlBody();
        $this->assertStringContainsString('أهلاً نورة', $html);
        $this->assertStringNotContainsString('محتوى آخر', $html);
    }
}
