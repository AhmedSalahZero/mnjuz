<?php

namespace Tests\Feature\Email;

use App\Models\ChannelAccount;
use App\Models\Team;
use App\Models\User;
use App\Services\Email\MerchantSmtpTransportFactory;
use App\Services\Email\SmtpHostGuard;
use App\Services\Messaging\Channel;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\FlowBuilder\FlowBuilderTestCase;
use Tests\Support\FakeSmtpTransportFactory;

/**
 * ربط بريد التاجر (SMTP) من صفحة القنوات: لا يُحفظ قبل قبول الدخول، كلمة
 * المرور لا تصل المتصفّح، ولا اتصال بعنوان داخليّ أو منفذ غير منافذ الإرسال.
 */
class EmailChannelSettingsTest extends FlowBuilderTestCase
{
    private FakeSmtpTransportFactory $smtp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->smtp = new FakeSmtpTransportFactory();
        $this->app->instance(MerchantSmtpTransportFactory::class, $this->smtp);
    }

    private function asOwner(): static
    {
        return $this->actingAs($this->owner, 'user')
            ->withSession(['current_organization' => $this->organization->id]);
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

    public function test_connecting_verifies_the_login_before_saving(): void
    {
        $this->asOwner()->post('/settings/channels/email', $this->form())->assertSessionHasNoErrors();

        $this->assertCount(1, $this->smtp->verified);
        $account = $this->account();
        $this->assertNotNull($account);
        $this->assertTrue($account->isActive());
        $this->assertSame('sales@shop.example', $account->username);
        $this->assertSame('متجر المثال', $account->name);
        $this->assertSame('top-secret-password', $account->credential('password'));

        // مشفّرة في القاعدة.
        $raw = DB::table('channel_accounts')->where('id', $account->id)->value('credentials');
        $this->assertStringNotContainsString('top-secret-password', (string) $raw);
    }

    public function test_a_rejected_login_is_not_saved(): void
    {
        $this->smtp->verifyFailure = FakeSmtpTransportFactory::rejection('535 5.7.3 Authentication unsuccessful', 535);

        $this->asOwner()->post('/settings/channels/email', $this->form())->assertSessionHasErrors('password');

        $this->assertNull($this->account());
    }

    /** خادمٌ لا يعرض تسجيل الدخول كان سيرسل بلا هويّة: يُرفض. */
    public function test_a_server_that_does_not_offer_login_is_refused(): void
    {
        $this->smtp->verifyFailure = FakeSmtpTransportFactory::rejection('The SMTP server did not accept the login.', 535);

        $this->asOwner()->post('/settings/channels/email', $this->form())->assertSessionHasErrors('password');

        $this->assertNull($this->account());
    }

    /** بالحارس الحقيقي: عناوين داخلية تُرفض قبل أيّ اتصال. */
    public function test_private_loopback_and_metadata_hosts_are_refused(): void
    {
        $this->app->instance(MerchantSmtpTransportFactory::class, new MerchantSmtpTransportFactory(new SmtpHostGuard(fn () => ['127.0.0.1'])));

        foreach (['127.0.0.1', '10.0.0.5', '169.254.169.254', '[::1]', 'localhost', '192.168.1.20'] as $host) {
            $this->asOwner()->post('/settings/channels/email', $this->form(['host' => $host]))
                ->assertSessionHasErrors('host');
        }

        $this->assertNull($this->account());
    }

    public function test_ports_outside_the_submission_list_are_refused(): void
    {
        foreach ([25, 6379, 3306, 80] as $port) {
            $this->asOwner()->post('/settings/channels/email', $this->form(['port' => $port]))
                ->assertSessionHasErrors('port');
        }

        $this->assertSame([], $this->smtp->verified);
    }

    public function test_the_page_never_sends_the_password_to_the_browser(): void
    {
        $this->asOwner()->post('/settings/channels/email', $this->form());

        $page = $this->asOwner()->get('/settings/channels/email')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('User/Settings/EmailChannel')
                ->where('smtp.has_password', true)
                ->where('smtp.host', 'smtp.office365.com')
            );
        $this->assertStringNotContainsString('top-secret-password', $page->getContent());

        $list = $this->asOwner()->get('/settings/channels')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('emailChannel.connected', true)
                ->where('emailChannel.from_address', 'sales@shop.example')
            );
        $this->assertStringNotContainsString('top-secret-password', $list->getContent());
    }

    public function test_saving_without_a_password_keeps_the_stored_one(): void
    {
        $this->asOwner()->post('/settings/channels/email', $this->form());

        $this->asOwner()->post('/settings/channels/email', $this->form(['password' => '', 'from_name' => 'اسم جديد']))
            ->assertSessionHasNoErrors();

        $this->assertSame('top-secret-password', $this->account()->credential('password'));
        $this->assertSame('اسم جديد', $this->account()->name);
        $this->assertSame('top-secret-password', end($this->smtp->verified)['password']);
    }

    /** جلسة مخترَقة لا توجّه كلمة المرور المحفوظة إلى خادمها. */
    public function test_changing_the_host_requires_the_password_again(): void
    {
        $this->asOwner()->post('/settings/channels/email', $this->form());
        $verifiedBefore = count($this->smtp->verified);

        $this->asOwner()->post('/settings/channels/email', $this->form(['password' => '', 'host' => 'smtp.attacker.example']))
            ->assertSessionHasErrors('password');

        $this->assertCount($verifiedBefore, $this->smtp->verified);
        $this->assertSame('smtp.office365.com', $this->account()->credential('host'));
    }

    public function test_a_test_email_goes_to_the_signed_in_user_from_the_merchant_address(): void
    {
        $this->asOwner()->post('/settings/channels/email', $this->form());

        $this->asOwner()->post('/settings/channels/email/test')->assertSessionHasNoErrors();

        $sent = $this->smtp->sent();
        $this->assertCount(1, $sent);
        $this->assertSame($this->owner->email, $sent[0]->getTo()[0]->getAddress());
        $this->assertSame('sales@shop.example', $sent[0]->getFrom()[0]->getAddress());
        $this->assertSame('success', session('status')['type']);
    }

    public function test_disconnecting_email_soft_deletes_without_webhook_calls(): void
    {
        $this->asOwner()->post('/settings/channels/email', $this->form());
        $account = $this->account();

        $this->asOwner()->delete('/settings/channels/' . $account->uuid)->assertRedirect();

        $this->assertNull($this->account());
        $this->assertNotNull(ChannelAccount::withTrashed()->find($account->id)->deleted_at);
    }

    public function test_agents_cannot_open_email_settings(): void
    {
        $agent = User::factory()->create(['role' => 'user']);
        Team::factory()->create(['user_id' => $agent->id, 'organization_id' => $this->organization->id, 'role' => 'agent', 'created_by' => $this->owner->id]);

        $this->actingAs($agent, 'user')
            ->withSession(['current_organization' => $this->organization->id])
            ->get('/settings/channels/email')
            ->assertForbidden();

        $this->actingAs($agent, 'user')
            ->withSession(['current_organization' => $this->organization->id])
            ->post('/settings/channels/email', $this->form())
            ->assertForbidden();

        $this->assertSame([], $this->smtp->verified);
    }
}
