<?php

namespace Tests\Unit\Messaging;

use App\Models\Contact;
use App\Services\Messaging\AutomationChannels;
use App\Services\Messaging\Channel;
use Tests\TestCase;

/**
 * قواعد «لكل قناة أتمتتها» بلا قاعدة بيانات: أيّ القنوات تُتاح للاختيار،
 * وكيف يُقرأ اختيار التاجر، ومتى يجيب ردٌّ على رسالة.
 */
class AutomationChannelsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['messaging.automation_channels' => [Channel::WEBCHAT, Channel::TELEGRAM]]);
    }

    public function test_whatsapp_is_always_available_and_the_rest_follow_the_config(): void
    {
        $this->assertSame([Channel::WHATSAPP, Channel::TELEGRAM, Channel::WEBCHAT], AutomationChannels::available());

        config(['messaging.automation_channels' => []]);
        $this->assertSame([Channel::WHATSAPP], AutomationChannels::available());
    }

    public function test_options_carry_a_value_and_a_label_for_each_available_channel(): void
    {
        $options = AutomationChannels::options();

        $this->assertSame(AutomationChannels::available(), array_column($options, 'value'));
        $this->assertNotContains('', array_column($options, 'label'));
    }

    public function test_normalize_keeps_known_channels_once_in_display_order(): void
    {
        $this->assertSame(
            [Channel::WHATSAPP, Channel::WEBCHAT],
            AutomationChannels::normalize([Channel::WEBCHAT, 'fax', Channel::WHATSAPP, Channel::WEBCHAT])
        );
        $this->assertSame([], AutomationChannels::normalize(null));
        $this->assertSame([], AutomationChannels::normalize('not-a-list'));
    }

    /** null = قاعدة قديمة لم يُحدَّد لها شيء: تعمل على كل المتاح. */
    public function test_effective_reads_null_as_every_available_channel(): void
    {
        $this->assertSame(AutomationChannels::available(), AutomationChannels::effective(null));
        $this->assertSame([Channel::TELEGRAM], AutomationChannels::effective([Channel::TELEGRAM]));
        $this->assertSame([Channel::TELEGRAM], AutomationChannels::effective('["telegram"]'));
    }

    public function test_covers_decides_whether_a_rule_answers_on_a_channel(): void
    {
        $this->assertTrue(AutomationChannels::covers(null, Channel::TELEGRAM));
        $this->assertTrue(AutomationChannels::covers([Channel::WEBCHAT], Channel::WEBCHAT));
        $this->assertFalse(AutomationChannels::covers([Channel::WEBCHAT], Channel::WHATSAPP));
        $this->assertTrue(AutomationChannels::covers('["whatsapp"]', null), 'a chat without a channel is WhatsApp');
        $this->assertFalse(AutomationChannels::covers([], Channel::WHATSAPP));
    }

    /** قواعد ما قبل الاختيار عملت على واتساب والمحادثة المباشرة وحدهما. */
    public function test_legacy_rules_keep_the_channels_they_ran_on(): void
    {
        $this->assertSame([Channel::WHATSAPP, Channel::WEBCHAT], AutomationChannels::LEGACY);
        $this->assertFalse(AutomationChannels::covers(AutomationChannels::LEGACY, Channel::TELEGRAM));
    }

    public function test_the_global_gate_follows_the_contacts_channel(): void
    {
        $this->assertTrue(AutomationChannels::allows(new Contact(['channel' => Channel::WHATSAPP])));
        $this->assertTrue(AutomationChannels::allows(new Contact(['channel' => Channel::TELEGRAM])));
        $this->assertFalse(AutomationChannels::allows(new Contact(['channel' => Channel::MESSENGER])));
    }
}
