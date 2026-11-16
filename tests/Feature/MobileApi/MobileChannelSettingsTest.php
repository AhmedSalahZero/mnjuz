<?php

namespace Tests\Feature\MobileApi;

use App\Models\ChannelAccount;
use App\Models\Organization;
use App\Models\Template;
use App\Services\Messaging\Channel;
use App\Services\WebChat\WebChatWidgetService;
use App\Support\OrganizationRole;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * إعدادات القنوات من التطبيق: القائمة، وربط تيليجرام، والفصل.
 *
 * القواعد نفسها في الويب (ChannelSettingsTest) لأن الطريق واحد
 * (ChannelAccountConnector). ما يُختبر هنا ما يخصّ التطبيق: الصلاحية،
 * وشكل الردّ، وما يُربط من المتصفّح وحده.
 */
class MobileChannelSettingsTest extends MobileChannelsTestCase
{
    private function fakeTelegram(bool $webhookOk = true): void
    {
        Http::fake([
            '*/getMe' => Http::response([
                'ok' => true,
                'result' => ['id' => 777001, 'first_name' => 'بوت الدعم', 'username' => 'support_bot'],
            ], 200),
            '*/setWebhook' => Http::response(['ok' => $webhookOk, 'result' => $webhookOk], $webhookOk ? 200 : 400),
        ]);
    }

    // ------------------------------------------------------------ الصلاحية

    public function test_an_agent_cannot_open_or_change_channel_settings(): void
    {
        $account = $this->telegramAccount();
        $this->actAs($this->agent());
        Http::fake();

        $this->getJson('/api/v1/settings/channels')->assertForbidden()->assertJsonPath('statusCode', 403);
        $this->postJson('/api/v1/settings/channels', ['channel' => 'telegram', 'credentials' => ['bot_token' => 'x']])->assertForbidden();
        $this->deleteJson('/api/v1/settings/channels/' . $account->uuid)->assertForbidden();
        $this->deleteJson('/api/v1/settings/channels/whatsapp')->assertForbidden();
        $this->getJson('/api/v1/settings/channels/email')->assertForbidden();
        $this->getJson('/api/v1/settings/webchat')->assertForbidden();

        Http::assertNothingSent();
        $this->assertNotNull($account->fresh());
    }

    public function test_a_manager_can_open_channel_settings(): void
    {
        $this->actAs($this->member(OrganizationRole::MANAGER));

        $this->getJson('/api/v1/settings/channels')->assertOk();
    }

    // -------------------------------------------------------------- القائمة

    public function test_the_list_describes_every_channel_and_how_it_connects(): void
    {
        $this->connectWhatsapp();
        $this->telegramAccount();

        $data = $this->getJson('/api/v1/settings/channels')->assertOk()->json('data');

        $this->assertTrue($data['whatsapp']['connected']);
        $this->assertSame('web', $data['whatsapp']['connect_mode']);
        $this->assertStringEndsWith('/settings/whatsapp', $data['whatsapp']['connect_url']);

        $cards = collect($data['channels'])->keyBy('channel');
        $this->assertSame(Channel::connectable(), $cards->keys()->all());
        $this->assertSame('in_app', $cards['telegram']['connect_mode']);
        $this->assertSame('bot_token', $cards['telegram']['fields'][0]['key']);
        $this->assertSame('support_bot', $cards['telegram']['account']['username']);
        $this->assertArrayNotHasKey('settings', $cards['telegram']['account']);

        foreach ([Channel::MESSENGER, Channel::INSTAGRAM, Channel::TIKTOK] as $oauth) {
            $this->assertSame('web', $cards[$oauth]['connect_mode'], $oauth);
            $this->assertSame([], $cards[$oauth]['fields'], $oauth);
            $this->assertStringEndsWith('/settings/channels/' . $oauth, $cards[$oauth]['connect_url']);
        }

        $this->assertFalse($data['email']['connected']);
        $this->assertSame('in_app', $data['email']['connect_mode']);
        $this->assertArrayHasKey('available', $data['webchat']);
    }

    public function test_no_secret_reaches_the_app(): void
    {
        $this->telegramAccount();

        $json = (string) $this->getJson('/api/v1/settings/channels')->assertOk()->getContent();

        $this->assertStringNotContainsString('777001:AAF-token', $json);
        $this->assertStringNotContainsString('hook-secret', $json);
    }

    // ---------------------------------------------------------------- الربط

    public function test_telegram_connects_from_the_app_and_registers_the_webhook(): void
    {
        $this->fakeTelegram();

        $this->postJson('/api/v1/settings/channels', [
            'channel' => 'telegram',
            'credentials' => ['bot_token' => '777001:AAF-token', 'evil' => 'kept-out'],
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.webhook_registered', true)
            ->assertJsonPath('data.account.username', 'support_bot');

        $account = ChannelAccount::where('organization_id', $this->organization->id)->where('channel', Channel::TELEGRAM)->firstOrFail();
        $this->assertSame('777001', $account->external_id);
        $this->assertSame('777001:AAF-token', $account->credential('bot_token'));
        $this->assertNull($account->credential('evil'), 'مفاتيح لا تصفها البطاقة لا تُحفظ');
        Http::assertSent(fn ($request) => str_contains($request->url(), '/setWebhook'));
    }

    public function test_a_rejected_token_is_refused_and_never_saved(): void
    {
        Http::fake(['*/getMe' => Http::response(['ok' => false, 'description' => 'Unauthorized'], 401)]);

        $this->postJson('/api/v1/settings/channels', ['channel' => 'telegram', 'credentials' => ['bot_token' => 'wrong']])
            ->assertStatus(422)
            ->assertJsonPath('statusCode', 422)
            ->assertJsonMissingPath('data');

        $this->assertSame(0, ChannelAccount::where('channel', Channel::TELEGRAM)->count());
    }

    public function test_a_missing_token_is_a_validation_error_without_calling_telegram(): void
    {
        Http::fake();

        $this->postJson('/api/v1/settings/channels', ['channel' => 'telegram', 'credentials' => ['other' => 'x']])
            ->assertStatus(400)
            ->assertJsonValidationErrors('credentials.bot_token', 'errors');

        Http::assertNothingSent();
    }

    public function test_a_failed_webhook_still_saves_and_says_so(): void
    {
        $this->fakeTelegram(webhookOk: false);

        $this->postJson('/api/v1/settings/channels', ['channel' => 'telegram', 'credentials' => ['bot_token' => '777001:AAF-token']])
            ->assertOk()
            ->assertJsonPath('data.webhook_registered', false);

        $this->assertSame(1, ChannelAccount::where('channel', Channel::TELEGRAM)->count());
    }

    public function test_a_bot_connected_to_another_organization_is_refused(): void
    {
        $other = Organization::factory()->create(['created_by' => $this->owner->id]);
        $this->telegramAccount(['organization_id' => $other->id, 'external_id' => '777001']);
        $this->fakeTelegram();

        $this->postJson('/api/v1/settings/channels', ['channel' => 'telegram', 'credentials' => ['bot_token' => '777001:AAF-token']])
            ->assertStatus(422);

        $this->assertSame(0, ChannelAccount::where('organization_id', $this->organization->id)->count());
    }

    public function test_oauth_channels_connect_from_the_web_only(): void
    {
        Http::fake();

        foreach ([Channel::MESSENGER, Channel::INSTAGRAM, Channel::TIKTOK] as $channel) {
            $this->postJson('/api/v1/settings/channels', ['channel' => $channel, 'credentials' => ['access_token' => 'x', 'business_id' => 'y']])
                ->assertStatus(422)
                ->assertJsonPath('message', __('Connect :channel from the web dashboard.', ['channel' => Channel::label($channel)]));
        }

        Http::assertNothingSent();
        $this->assertSame(0, ChannelAccount::count());
    }

    public function test_whatsapp_email_and_unknown_channels_are_not_connectable_here(): void
    {
        foreach ([Channel::WHATSAPP, Channel::EMAIL, Channel::WEBCHAT, 'fax'] as $channel) {
            $this->postJson('/api/v1/settings/channels', ['channel' => $channel, 'credentials' => ['x' => 'y']])
                ->assertStatus(400);
        }
    }

    // ---------------------------------------------------------------- الفصل

    public function test_disconnecting_soft_deletes_the_account(): void
    {
        $account = $this->telegramAccount();
        Http::fake(['*/deleteWebhook' => Http::response(['ok' => true, 'result' => true], 200)]);

        $this->deleteJson('/api/v1/settings/channels/' . $account->uuid)->assertOk()->assertJsonPath('success', true);

        $this->assertSoftDeleted('channel_accounts', ['id' => $account->id]);
    }

    public function test_another_organizations_account_is_not_found(): void
    {
        $other = Organization::factory()->create(['created_by' => $this->owner->id]);
        $account = $this->telegramAccount(['organization_id' => $other->id]);
        Http::fake();

        $this->deleteJson('/api/v1/settings/channels/' . $account->uuid)->assertNotFound()->assertJsonPath('statusCode', 404);

        $this->assertNotNull($account->fresh());
    }

    public function test_the_web_chat_widget_is_not_disconnected_from_here(): void
    {
        $widget = app(WebChatWidgetService::class)->ensureForOrganization($this->organization->id, 'متجر');

        $this->deleteJson('/api/v1/settings/channels/' . $widget->uuid)->assertStatus(422);

        $this->assertNotNull($widget->fresh());
    }

    public function test_disconnecting_whatsapp_clears_it_and_retires_templates(): void
    {
        $this->connectWhatsapp();
        $template = Template::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'meta_id' => '123',
            'name' => 'welcome',
            'category' => 'MARKETING',
            'language' => 'ar',
            'status' => 'APPROVED',
            'metadata' => json_encode(['components' => []]),
            'created_by' => $this->owner->id,
        ]);

        $this->deleteJson('/api/v1/settings/channels/whatsapp')->assertOk()->assertJsonPath('success', true);

        $metadata = json_decode((string) $this->organization->fresh()->metadata, true);
        $this->assertArrayNotHasKey('whatsapp', $metadata);
        $this->assertNotNull(Template::withTrashed()->find($template->id)->deleted_at);
    }

    public function test_disconnecting_whatsapp_that_is_not_connected_is_refused(): void
    {
        $this->deleteJson('/api/v1/settings/channels/whatsapp')->assertStatus(422);
    }
}
