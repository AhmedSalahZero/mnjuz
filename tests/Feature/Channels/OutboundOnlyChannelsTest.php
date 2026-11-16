<?php

namespace Tests\Feature\Channels;

use App\Http\Controllers\User\ChannelAccountController;
use App\Models\ChannelAccount;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use App\Services\Messaging\AutomationChannels;
use App\Services\Messaging\CampaignChannels;
use App\Services\Messaging\Channel;
use App\Services\Messaging\ChannelManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * البريد وSMS قنوات إرسال للحملات فقط: لا محادثة ولا webhook ولا driver.
 * لا تدخل صندوق الوارد ولا الأتمتة ولا صفحة الربط العامّة — وحساب SMTP
 * للتاجر صفّ في channel_accounts لا يظهر قناةَ محادثة.
 */
class OutboundOnlyChannelsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['role' => 'user']);
        $this->organization = Organization::factory()->create(['created_by' => $this->owner->id]);
        Team::factory()->create(['user_id' => $this->owner->id, 'organization_id' => $this->organization->id, 'role' => 'owner']);

        $this->actingAs($this->owner);
        session(['current_organization' => $this->organization->id]);
    }

    public function test_email_and_sms_are_not_conversation_channels(): void
    {
        foreach ([Channel::EMAIL, Channel::SMS] as $channel) {
            $this->assertNotContains($channel, Channel::all());
            $this->assertNotContains($channel, Channel::connectable());
            $this->assertNull(Channel::normalize($channel));
            $this->assertFalse(Channel::isValid($channel));
            // لكن الحملات تعرفهما.
            $this->assertSame($channel, CampaignChannels::normalize(strtoupper($channel)));
        }

        config(['messaging.automation_channels' => ['email', 'sms', 'telegram']]);
        $this->assertNotContains(Channel::EMAIL, AutomationChannels::available());
        $this->assertNotContains(Channel::SMS, AutomationChannels::available());
    }

    public function test_an_email_account_row_never_shows_as_a_connected_channel(): void
    {
        ChannelAccount::create([
            'organization_id' => $this->organization->id,
            'channel' => Channel::EMAIL,
            'external_id' => 'org:' . $this->organization->id,
            'credentials' => ['host' => 'smtp.example.com', 'password' => 'secret'],
            'status' => ChannelAccount::STATUS_ACTIVE,
            'created_by' => $this->owner->id,
        ]);

        $manager = app(ChannelManager::class);

        $this->assertNotContains(Channel::EMAIL, $manager->connectedChannels($this->organization->id));
        // ولا يُستدعى له driver (كان سيرمي): قائمة قنوات الحملة تُبنى بلا خطأ.
        $this->assertNotContains(Channel::EMAIL, $manager->campaignChannels($this->organization->id));
        $this->assertTrue(CampaignChannels::isConnected($this->organization->id, Channel::EMAIL));
    }

    public function test_the_channel_webhook_refuses_email(): void
    {
        $this->postJson('/webhook/channel/email', ['x' => 1])->assertNotFound();
    }

    public function test_the_generic_connect_form_refuses_email(): void
    {
        $this->expectException(ValidationException::class);

        (new ChannelAccountController(app(ChannelManager::class)))->store(Request::create('/settings/channels', 'POST', [
            'channel' => Channel::EMAIL,
            'credentials' => ['host' => 'x'],
        ]));
    }
}
