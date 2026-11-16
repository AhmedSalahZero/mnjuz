<?php

namespace Tests\Feature\MobileApi;

use App\Models\ChannelAccount;
use App\Services\Email\MerchantSmtpTransportFactory;
use App\Services\Messaging\Channel;
use App\Support\OrganizationRole;
use Tests\Support\FakeSmtpTransportFactory;

/**
 * بريد الحملات (SMTP) من التطبيق. القواعد والحفظ هما ما يستعمله الويب
 * (EmailChannelSettingsTest)؛ هنا شكل الردّ والصلاحية وما لا يغادر الخادم.
 */
class MobileEmailChannelTest extends MobileApiTestCase
{
    private FakeSmtpTransportFactory $smtp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->smtp = new FakeSmtpTransportFactory();
        $this->app->instance(MerchantSmtpTransportFactory::class, $this->smtp);
    }

    private function form(array $overrides = []): array
    {
        return array_merge([
            'host' => 'smtp.office365.com',
            'port' => 587,
            'encryption' => 'tls',
            'username' => 'sales@shop.example',
            'password' => 'top-secret-password',
            'from_address' => 'sales@shop.example',
            'from_name' => 'متجر المثال',
            'reply_to' => 'support@shop.example',
        ], $overrides);
    }

    private function account(): ?ChannelAccount
    {
        return ChannelAccount::where('organization_id', $this->organization->id)->where('channel', Channel::EMAIL)->first();
    }

    public function test_saving_verifies_the_login_then_returns_the_settings_without_the_password(): void
    {
        $response = $this->postJson('/api/v1/settings/channels/email', $this->form())
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.smtp.host', 'smtp.office365.com')
            ->assertJsonPath('data.smtp.has_password', true)
            ->assertJsonPath('data.account.is_active', true);

        $this->assertCount(1, $this->smtp->verified);
        $this->assertSame('top-secret-password', $this->account()->credential('password'));
        $this->assertStringNotContainsString('top-secret-password', (string) $response->getContent());
        $this->assertStringNotContainsString('top-secret-password', (string) $this->getJson('/api/v1/settings/channels/email')->getContent());
    }

    public function test_a_rejected_login_is_a_field_error_and_nothing_is_saved(): void
    {
        $this->smtp->verifyFailure = FakeSmtpTransportFactory::rejection('535 5.7.3 Authentication unsuccessful', 535);

        $this->postJson('/api/v1/settings/channels/email', $this->form())
            ->assertStatus(400)
            ->assertJsonValidationErrors('password', 'errors');

        $this->assertNull($this->account());
    }

    public function test_invalid_fields_are_refused_before_contacting_the_server(): void
    {
        $this->postJson('/api/v1/settings/channels/email', $this->form(['port' => 25, 'from_address' => 'not-an-email']))
            ->assertStatus(400)
            ->assertJsonValidationErrors(['port', 'from_address'], 'errors');

        $this->assertCount(0, $this->smtp->verified);
    }

    public function test_a_blank_password_keeps_the_stored_one_only_for_the_same_server(): void
    {
        $this->postJson('/api/v1/settings/channels/email', $this->form())->assertOk();

        $this->postJson('/api/v1/settings/channels/email', $this->form(['password' => '', 'from_name' => 'اسم جديد']))->assertOk();
        $this->assertSame('top-secret-password', $this->account()->credential('password'));

        $this->postJson('/api/v1/settings/channels/email', $this->form(['password' => '', 'host' => 'smtp.evil.example']))
            ->assertStatus(400)
            ->assertJsonValidationErrors('password', 'errors');
        $this->assertSame('smtp.office365.com', $this->account()->credential('host'));
    }

    public function test_the_test_email_goes_to_the_signed_in_user(): void
    {
        $this->postJson('/api/v1/settings/channels/email/test')->assertStatus(422);

        $this->postJson('/api/v1/settings/channels/email', $this->form())->assertOk();
        $this->postJson('/api/v1/settings/channels/email/test')->assertOk()->assertJsonPath('success', true);

        $sent = $this->smtp->mailbox->messages()->first();
        $this->assertNotNull($sent);
        $this->assertSame($this->owner->email, $sent->getEnvelope()->getRecipients()[0]->getAddress());
    }

    public function test_email_is_disconnected_from_the_shared_route(): void
    {
        $this->postJson('/api/v1/settings/channels/email', $this->form())->assertOk();

        $this->deleteJson('/api/v1/settings/channels/' . $this->account()->uuid)->assertOk();

        $this->assertNull($this->account());
    }

    public function test_an_agent_cannot_read_or_change_email_settings(): void
    {
        $this->actAs($this->member(OrganizationRole::AGENT));

        $this->getJson('/api/v1/settings/channels/email')->assertForbidden();
        $this->postJson('/api/v1/settings/channels/email', $this->form())->assertForbidden();
        $this->postJson('/api/v1/settings/channels/email/test')->assertForbidden();
        $this->assertCount(0, $this->smtp->verified);
    }
}
