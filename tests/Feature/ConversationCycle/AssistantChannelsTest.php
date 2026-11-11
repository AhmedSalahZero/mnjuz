<?php

namespace Tests\Feature\ConversationCycle;

use App\Models\Addon;
use App\Models\Organization;
use App\Models\Subscription;
use App\Services\Messaging\AutomationChannels;
use App\Services\Messaging\Channel;
use Modules\IntelliReply\Services\AIResponseService;

/**
 * تيليجرام تصله الردود والـ flows التي يختارها التاجر له — لا المساعد الذكي
 * ولا رسالة خارج أوقات العمل. هذان بلا اختيار قنوات بعد، فيبقيان على واتساب
 * والمحادثة المباشرة كما كانا قبل فتح تيليجرام للأتمتة: لا يبدأ بوتٌ يجيب
 * عملاء تيليجرام على حساب OpenAI دون أن يطلبه التاجر.
 */
class AssistantChannelsTest extends ConversationCycleTestCase
{
    /** قنوات الرسائل التي وصلت المساعد الذكي (ArrayObject: يُشارَك مع الخدمة المربوطة). */
    private \ArrayObject $assistantSaw;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assistantSaw = $seen = new \ArrayObject();
        $this->app->bind(AIResponseService::class, fn () => new class($seen) extends AIResponseService {
            public function __construct(private \ArrayObject $seen)
            {
            }

            public function handleAIResponse($chat, $receivedMessage): bool
            {
                $this->seen[] = $chat->channel;

                return false;
            }
        });
    }

    public function test_the_assistant_answers_whatsapp_and_web_chat_but_not_telegram(): void
    {
        foreach (self::CHANNELS as $channel) {
            $this->customerWrites($channel, 'عندي سؤال عن المنتج');
        }

        $seen = $this->assistantSaw->getArrayCopy();
        sort($seen);
        $this->assertSame([Channel::WEBCHAT, Channel::WHATSAPP], $seen);
    }

    /** والردّ الآلي المختار لتيليجرام يصله كما يصل غيره. */
    public function test_a_reply_chosen_for_telegram_still_answers_there(): void
    {
        $this->autoReply('السعر', 'الأسعار في الموقع', [Channel::TELEGRAM]);

        $this->customerWrites(Channel::TELEGRAM, 'كم السعر؟');

        $this->assertContains('الأسعار في الموقع', $this->deliveredTexts(Channel::TELEGRAM));
        $this->assertSame([], $this->assistantSaw->getArrayCopy(), 'the reply answered first');
    }

    public function test_the_away_notice_reaches_web_chat_but_not_telegram(): void
    {
        $this->outsideWorkingHours('نعتذر، خارج أوقات العمل');

        $this->customerWrites(Channel::WEBCHAT, 'مرحبا');
        $this->customerWrites(Channel::TELEGRAM, 'مرحبا');

        $this->assertContains('نعتذر، خارج أوقات العمل', $this->deliveredTexts(Channel::WEBCHAT));
        $this->assertNotContains('نعتذر، خارج أوقات العمل', $this->deliveredTexts(Channel::TELEGRAM));
    }

    public function test_the_rule_in_one_place(): void
    {
        $this->assertTrue(AutomationChannels::assistantAllows(Channel::WHATSAPP));
        $this->assertTrue(AutomationChannels::assistantAllows(null), 'no channel means WhatsApp');
        $this->assertTrue(AutomationChannels::assistantAllows(Channel::WEBCHAT));
        $this->assertFalse(AutomationChannels::assistantAllows(Channel::TELEGRAM));
        $this->assertTrue(AutomationChannels::allowsChannel(Channel::TELEGRAM), 'replies and flows still run on Telegram');

        // قناة أُطفئت أتمتتها كلّها لا يعود إليها المساعد.
        config(['messaging.automation_channels' => []]);
        $this->assertFalse(AutomationChannels::assistantAllows(Channel::WEBCHAT));
        $this->assertTrue(AutomationChannels::assistantAllows(Channel::WHATSAPP));
    }

    private function outsideWorkingHours(string $message): void
    {
        Addon::factory()->create(['name' => 'Working Hours', 'status' => 1, 'is_active' => 1]);
        $plan = Subscription::where('organization_id', $this->organization->id)->first()->plan;
        $planMetadata = json_decode($plan->metadata, true);
        $planMetadata['addons']['Working Hours'] = true;
        $plan->update(['metadata' => json_encode($planMetadata)]);

        $metadata = json_decode(Organization::find($this->organization->id)->metadata, true);
        // فترة لا تشمل اللحظة الحالية مهما كانت.
        $metadata['working_hours'] = [['day' => (int) now()->addDays(2)->dayOfWeek, 'open' => '09:00', 'close' => '09:01']];
        $metadata['working_hours_outside_message'] = $message;
        Organization::where('id', $this->organization->id)->update(['metadata' => json_encode($metadata)]);
        $this->organization->refresh();
    }
}
