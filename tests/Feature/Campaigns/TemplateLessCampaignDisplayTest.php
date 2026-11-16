<?php

namespace Tests\Feature\Campaigns;

use App\Models\Campaign;
use App\Models\CampaignLog;
use App\Models\ChannelAccount;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * حملة بلا قالب واتساب (تيليجرام وغيرها) تُعرض في كل مكان.
 *
 * القائمة كانت تقرأ template.name، وصفحة الحملة تمرّر metadata "[]"
 * لمعاينة واتساب، والتصدير يقرأ template->name — فتسقط الثلاثة مع أوّل
 * حملة تيليجرام. ومعها: الحملة تُطلب بالـ uuid من رابط، فصفحتها وتصديرها
 * وحذفها مقيّدة بمنشأة المستخدم.
 */
class TemplateLessCampaignDisplayTest extends CampaignTestCase
{
    private function asOwner(): static
    {
        return $this->actingAs($this->owner, 'user')
            ->withSession(['current_organization' => $this->organization->id]);
    }

    private function telegramCampaign(?Organization $organization = null): Campaign
    {
        $organization ??= $this->organization;

        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 901]], 200)]);

        $bot = ChannelAccount::create([
            'organization_id' => $organization->id, 'channel' => 'telegram', 'external_id' => 'bot-' . $organization->id,
            'credentials' => ['bot_token' => 'tok', 'webhook_updates' => 'callback_query'],
            'status' => ChannelAccount::STATUS_ACTIVE, 'created_by' => 0,
        ]);
        $contact = $this->customer('', 'سالم', [
            'channel' => 'telegram', 'channel_identifier' => '5551' . $organization->id, 'channel_account_id' => $bot->id, 'phone' => null,
        ], $organization);

        $campaign = Campaign::create([
            'uuid' => (string) Str::uuid(), 'organization_id' => $organization->id, 'name' => 'عرض تيليجرام',
            'channel' => 'telegram', 'channel_account_id' => $bot->id, 'template_id' => null, 'contact_group_id' => 0,
            'metadata' => '[]', 'message_payload' => json_encode(['text' => 'عرض اليوم يا {first_name}', 'media' => null], JSON_UNESCAPED_UNICODE),
            'created_by' => $this->owner->id, 'status' => 'completed', 'scheduled_at' => now(),
        ]);
        CampaignLog::create(['campaign_id' => $campaign->id, 'contact_id' => $contact->id, 'status' => 'success']);

        return $campaign;
    }

    public function test_the_campaign_list_carries_the_channel_of_a_telegram_campaign(): void
    {
        $this->ongoingCampaign($this->template(), [$this->customer('+966500000002', 'نورة')]);
        $telegram = $this->telegramCampaign();

        $response = $this->asOwner()->get('/campaigns')->assertOk();

        $rows = collect($response->viewData('page')['props']['rows']['data'])->keyBy('uuid');

        $uuid = (string) $telegram->uuid;
        $this->assertNull($rows[$uuid]['template']);
        $this->assertSame('telegram', $rows[$uuid]['channel']);
        $this->assertSame('whatsapp', $rows->first(fn ($row) => (string) $row['uuid'] !== $uuid)['channel']);
    }

    public function test_the_campaign_page_opens_for_a_telegram_campaign(): void
    {
        $telegram = $this->telegramCampaign();

        $this->asOwner()->get('/campaigns/' . $telegram->uuid)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('User/Campaign/View')
                ->where('campaign.channel', 'telegram')
                ->where('campaign.message_payload', fn ($payload) => str_contains((string) $payload, 'عرض اليوم'))
            );
    }

    public function test_a_telegram_campaign_exports_without_a_template(): void
    {
        $telegram = $this->telegramCampaign();

        $response = $this->asOwner()->get('/campaigns/export/' . $telegram->uuid)->assertOk();

        // Excel::download يُرجع ملفاً لا تدفّقاً.
        $csv = file_get_contents($response->baseResponse->getFile()->getPathname());
        $this->assertStringContainsString('عرض تيليجرام', $csv);
        $this->assertStringContainsString('sent', $csv);
    }

    /** رابط حملة منشأة أخرى لا يفتح صفحتها ولا يصدّرها ولا يحذفها. */
    public function test_another_organizations_campaign_cannot_be_viewed_exported_or_deleted(): void
    {
        $stranger = User::factory()->create(['role' => 'user']);
        $other = $this->makeOrganization($stranger);
        $foreign = $this->telegramCampaign($other);

        $this->asOwner()->get('/campaigns/' . $foreign->uuid)->assertNotFound();
        $this->asOwner()->get('/campaigns/export/' . $foreign->uuid)->assertNotFound();
        $this->asOwner()->delete('/campaigns/' . $foreign->uuid)->assertNotFound();

        $this->assertNull(Campaign::where('id', $foreign->id)->value('deleted_at'));
    }

    /** وحملة المنشأة نفسها تُحذف كما كانت. */
    public function test_an_own_campaign_is_still_deleted(): void
    {
        $telegram = $this->telegramCampaign();

        $this->asOwner()->delete('/campaigns/' . $telegram->uuid)->assertRedirect();

        $this->assertNotNull(Campaign::where('id', $telegram->id)->value('deleted_at'));
    }
}
