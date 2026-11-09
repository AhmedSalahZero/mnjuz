<?php

namespace Tests\Feature\MobileApi;

use App\Models\Campaign;
use App\Models\Contact;
use App\Models\Template;
use Illuminate\Support\Str;

/**
 * قوالب الـ carousel من تطبيق الجوال.
 *
 * التطبيق لا يعرف البطاقات بعد، فيُرفض القالب برسالة واضحة بدل نموذج ناقص
 * تُحفظ منه حملة ترفضها Meta عند الإرسال. والقالب العادي لم يتغيّر.
 */
class MobileCarouselCampaignTest extends MobileApiTestCase
{
    protected function planMetadata(): array
    {
        return parent::planMetadata() + ['campaign_limit' => 5];
    }

    private function template(string $name, array $components): Template
    {
        return Template::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'meta_id' => (string) random_int(100000, 999999),
            'name' => $name,
            'category' => 'MARKETING',
            'language' => 'ar',
            'status' => 'APPROVED',
            'metadata' => json_encode(['name' => $name, 'components' => $components]),
            'created_by' => $this->owner->id,
        ])->fresh();
    }

    private function carousel(): Template
    {
        $card = ['components' => [
            ['type' => 'header', 'format' => 'image', 'example' => ['header_handle' => ['https://scontent.test/x.jpg']]],
            ['type' => 'buttons', 'buttons' => [['type' => 'url', 'text' => 'Shop', 'url' => 'https://shop.test']]],
        ]];

        return $this->template('carousel_promo', [
            ['type' => 'body', 'text' => 'New arrivals'],
            ['type' => 'carousel', 'cards' => [$card, $card]],
        ]);
    }

    private function standard(): Template
    {
        return $this->template('plain_promo', [['type' => 'BODY', 'text' => 'Hello']]);
    }

    private function campaignPayload(Template $template): array
    {
        Contact::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'first_name' => 'Sara',
            'phone' => '+966500000009',
            'created_by' => $this->owner->id,
        ]);

        return [
            'name' => 'Mobile',
            'template' => (string) $template->uuid,
            'contacts' => 'all',
            'skip_schedule' => true,
            'header' => ['format' => null, 'parameters' => []],
            'body' => ['text' => 'x', 'parameters' => []],
            'footer' => ['text' => null],
            'buttons' => [],
        ];
    }

    public function test_the_template_endpoint_refuses_a_carousel_clearly(): void
    {
        $this->getJson('/api/v1/campaigns/templates/' . $this->carousel()->uuid)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Carousel templates can only be sent from the web dashboard for now.');
    }

    public function test_the_template_endpoint_still_serves_standard_templates(): void
    {
        $this->getJson('/api/v1/campaigns/templates/' . $this->standard()->uuid)
            ->assertOk()
            ->assertJsonPath('data.template.name', 'plain_promo');
    }

    public function test_a_carousel_campaign_is_not_created_from_the_app(): void
    {
        $this->postJson('/api/v1/campaigns', $this->campaignPayload($this->carousel()))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Carousel templates can only be sent from the web dashboard for now.');

        $this->assertSame(0, Campaign::count());
    }

    public function test_a_standard_campaign_is_still_created_from_the_app(): void
    {
        $this->postJson('/api/v1/campaigns', $this->campaignPayload($this->standard()))->assertOk();

        $this->assertSame(1, Campaign::count());
    }
}
