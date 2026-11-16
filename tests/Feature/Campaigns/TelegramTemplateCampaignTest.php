<?php

namespace Tests\Feature\Campaigns;

use App\Models\Campaign;
use App\Models\CampaignLog;
use App\Models\ChannelAccount;
use App\Models\ChannelTemplate;
use App\Models\Contact;
use Illuminate\Support\Facades\Http;

/**
 * حملة تيليجرام من قالب محفوظ: محتوى القالب يُنسخ لحظة الإنشاء، فتعديله أو
 * حذفه لاحقاً لا يغيّر حملةً مجدولة، وإعادة المحاولة ترسل النسخة نفسها.
 */
class TelegramTemplateCampaignTest extends CampaignTestCase
{
    private ChannelAccount $bot;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 901]], 200)]);

        $this->bot = ChannelAccount::create([
            'organization_id' => $this->organization->id, 'channel' => 'telegram', 'external_id' => 'bot-1',
            'credentials' => ['bot_token' => 'tok', 'webhook_updates' => 'callback_query'],
            'status' => ChannelAccount::STATUS_ACTIVE, 'created_by' => 0,
        ]);

        $this->customer('', 'سالم', [
            'channel' => 'telegram', 'channel_identifier' => '7771', 'channel_account_id' => $this->bot->id, 'phone' => null,
        ]);
    }

    private function savedTemplate(array $attributes = []): ChannelTemplate
    {
        return ChannelTemplate::create(array_merge([
            'organization_id' => $this->organization->id,
            'channel' => 'telegram',
            'name' => 'عرض العيد',
            'body' => 'عرض العيد يا {first_name}',
        ], $attributes));
    }

    private function fromTemplate(ChannelTemplate $template, array $overrides = []): array
    {
        return array_merge([
            'name' => 'حملة من قالب',
            'channel' => 'telegram',
            'channel_template' => (string) $template->uuid,
            'contacts' => 'all',
            'skip_schedule' => true,
        ], $overrides);
    }

    private function sentTexts(): array
    {
        return Http::recorded(fn ($request) => str_contains($request->url(), '/sendMessage'))
            ->map(fn ($pair) => $pair[0]['text'])
            ->values()
            ->all();
    }

    public function test_a_telegram_campaign_from_a_saved_template_reaches_the_bot(): void
    {
        $template = $this->savedTemplate();

        $this->createFromWeb($this->fromTemplate($template))->assertRedirect();
        $this->schedulerMinute();
        $this->dispatcherTick();

        $this->assertSame(['عرض العيد يا سالم'], $this->sentTexts());

        $campaign = Campaign::latest('id')->first();
        $this->assertSame(['uuid' => (string) $template->uuid, 'name' => 'عرض العيد'], $campaign->payloadValue('template'));
        $this->assertSame('success', CampaignLog::where('campaign_id', $campaign->id)->value('status'));
    }

    public function test_editing_the_template_later_does_not_change_a_scheduled_campaign(): void
    {
        $template = $this->savedTemplate();

        $this->createFromWeb($this->fromTemplate($template, ['skip_schedule' => false, 'time' => now()->addMinute()->format('Y-m-d H:i')]))->assertRedirect();
        $template->update(['body' => 'نص جديد بعد الجدولة']);

        $this->travel(2)->minutes();
        $this->schedulerMinute();
        $this->dispatcherTick();

        $this->assertSame(['عرض العيد يا سالم'], $this->sentTexts());
    }

    public function test_deleting_the_template_does_not_break_a_scheduled_campaign(): void
    {
        $template = $this->savedTemplate();

        $this->createFromWeb($this->fromTemplate($template))->assertRedirect();
        $template->delete();

        $this->schedulerMinute();
        $this->dispatcherTick();

        $this->assertSame(['عرض العيد يا سالم'], $this->sentTexts());
    }

    public function test_a_template_of_another_channel_or_organization_is_refused(): void
    {
        $email = $this->savedTemplate(['channel' => 'email', 'subject' => 'x']);

        $this->createFromWeb($this->fromTemplate($email))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['channel_template']);

        $stranger = \App\Models\User::factory()->create(['role' => 'user']);
        $other = $this->makeOrganization($stranger);
        $foreign = $this->savedTemplate(['organization_id' => $other->id]);

        $this->createFromWeb($this->fromTemplate($foreign))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['channel_template']);

        $this->assertSame(0, Campaign::count());
    }

    /** الكتابة المباشرة بجانب القوالب: ما كان يرسله النموذج قبلها. */
    public function test_the_free_message_path_still_works(): void
    {
        $this->createFromWeb([
            'name' => 'رسالة مباشرة',
            'channel' => 'telegram',
            'message_text' => 'رسالة مباشرة يا {first_name}',
            'contacts' => 'all',
            'skip_schedule' => true,
        ])->assertRedirect();

        $this->schedulerMinute();
        $this->dispatcherTick();

        $this->assertSame(['رسالة مباشرة يا سالم'], $this->sentTexts());
        $this->assertNull(Campaign::latest('id')->first()->payloadValue('template'));
    }

    public function test_a_retry_resends_the_same_snapshot(): void
    {
        $template = $this->savedTemplate();
        $this->setRetrySettings(true, [1]);

        $attempts = 0;
        Http::fake(['api.telegram.org/*' => function () use (&$attempts) {
            $attempts++;

            return $attempts === 1
                ? Http::response(['ok' => false, 'error_code' => 500, 'description' => 'Internal'], 500)
                : Http::response(['ok' => true, 'result' => ['message_id' => 902]], 200);
        }]);

        $this->createFromWeb($this->fromTemplate($template))->assertRedirect();
        $template->update(['body' => 'تغيّر القالب']);

        $this->schedulerMinute();
        $this->dispatcherTick();

        $this->travel(2)->minutes();
        $this->schedulerMinute();
        $this->dispatcherTick();

        $texts = $this->sentTexts();
        $this->assertNotEmpty($texts);
        $this->assertSame(['عرض العيد يا سالم'], array_values(array_unique($texts)));
    }
}
