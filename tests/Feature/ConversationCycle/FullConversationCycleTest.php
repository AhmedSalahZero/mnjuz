<?php

namespace Tests\Feature\ConversationCycle;

use App\Models\Chat;
use App\Models\ChatTicketLog;
use App\Models\Contact;
use App\Models\ConversationRating;
use App\Services\ConversationRatingService;
use App\Services\Messaging\Channel;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * المحادثة كاملة في اختبار واحد لكل قناة — بالترتيب الذي يعيشه التاجر:
 *
 *   العميل يكتب ← تُسجَّل مرّة واحدة على جهة اتصال واحدة وتُفتح تذكرة
 *   ← تُبثّ لصندوق الوارد ← يردّ الردّ الآلي من قاعدة قناته ← يردّ الموظّف
 *   ← تتقدّم حالة الرسالة ← يُغلق الموظّف ← يصل الاستبيان بشكل قناته
 *   ← يقيّم العميل ← يكتب ثانيةً فتعود التذكرة نفسها مفتوحة بلا استبيان آخر.
 *
 * انكسار أي حلقة يُسقط الاختبار عند اسمها، على القناة التي انكسرت فيها.
 */
class FullConversationCycleTest extends ConversationCycleTestCase
{
    #[DataProvider('channels')]
    public function test_a_conversation_runs_from_the_first_message_to_the_rating_and_back(string $channel): void
    {
        $this->autoReply('السعر', 'الأسعار تبدأ من 99 ريال');

        // ── ١. العميل يكتب
        $this->customerWrites($channel, 'مرحبا، كم السعر؟');

        $customer = $this->customer($channel);
        $this->assertSame($channel, $customer->channel);
        $this->assertSame(1, Contact::where('organization_id', $this->organization->id)->where('channel', $channel)->count(), 'one contact per customer');

        $inbound = Chat::where('contact_id', $customer->id)->where('type', 'inbound')->get();
        $this->assertCount(1, $inbound);
        $this->assertSame($channel, $inbound[0]->channel);
        $this->assertSame('مرحبا، كم السعر؟', self::bodyOf(json_decode($inbound[0]->metadata, true)));

        $ticket = $this->latestTicket($customer);
        $this->assertNotNull($ticket, 'the first message opens a ticket');
        $this->assertSame('open', $ticket->status);

        // ── ٢. صندوق الوارد يعرف بها: الرسالة نفسها، وجهة الاتصال، والقناة
        $arrivals = $this->inboxBroadcasts($customer, 'inbound');
        $this->assertNotEmpty($arrivals, 'the arrival is broadcast to the inbox');
        $this->assertSame($inbound[0]->id, $arrivals[0]['id']);
        $this->assertSame($customer->uuid, $arrivals[0]['contact_uuid']);
        $this->assertSame($channel, $arrivals[0]['channel']);

        // ── ٣. الردّ الآلي من القناة نفسها
        $this->assertSame('الأسعار تبدأ من 99 ريال', self::bodyOf($this->outbound($customer)[0] ?? []));
        $this->assertSame(['الأسعار تبدأ من 99 ريال'], $this->deliveredTexts($channel), 'the auto reply reached the platform');

        // ── ٤. الموظّف يردّ من صندوق الوارد
        $this->agentReplies($customer, 'تفضّل، هذا رابط المتجر');

        $agentMessage = $this->lastOutbound($customer);
        $this->assertSame('تفضّل، هذا رابط المتجر', self::bodyOf(json_decode($agentMessage->metadata, true)));
        $this->assertSame($this->owner->id, (int) $agentMessage->user_id);
        $this->assertSame($channel, $agentMessage->channel);
        $this->assertSame('تفضّل، هذا رابط المتجر', last($this->deliveredTexts($channel)));
        $this->assertNotEmpty($this->inboxBroadcasts($customer, 'outbound'), 'the agent reply is broadcast to the other agents');

        // ── ٥. حالة الرسالة
        match ($channel) {
            Channel::WHATSAPP => $this->whatsappReceipts($agentMessage),
            Channel::WEBCHAT => $this->visitorReadsEverything(),
            // تيليجرام بلا إيصالات تسليم: تبقى «أُرسلت» ولا تسقط.
            Channel::TELEGRAM => null,
        };
        $this->assertSame($channel === Channel::TELEGRAM ? 'sent' : 'read', $agentMessage->fresh()->status);

        // ── ٦. الموظّف يُغلق ← يصل الاستبيان بشكل القناة
        $this->agentCloses($customer);

        $this->assertSame('closed', $this->latestTicket($customer)->status);
        $this->assertSame(0, Chat::where('contact_id', $customer->id)->where('type', 'inbound')->where('is_read', 0)->count(), 'closing marks the conversation read');

        $rating = ConversationRating::where('contact_id', $customer->id)->sole();
        $this->assertNotNull($rating->sent_at);
        $this->assertSurveyButton($channel, ConversationRatingService::linkFor($rating));

        // ── ٧. العميل يقيّم من الرابط
        $page = $this->get('/rate/' . $rating->token)->assertOk()->viewData('page');
        $this->assertSame('Rating/Form', $page['component']);
        $this->assertSame('open', $page['props']['state']);

        $this->post('/rate/' . $rating->token, ['rating' => 5, 'comment' => 'خدمة ممتازة'])->assertOk();
        $this->assertSame(5, (int) $rating->fresh()->rating);
        $this->assertSame('submitted', $this->get('/rate/' . $rating->token)->viewData('page')['props']['state']);

        // ── ٨. يكتب ثانيةً: التذكرة نفسها تعود مفتوحة، ولا استبيان جديد
        $this->customerWrites($channel, 'عندي سؤال آخر');

        $reopened = $this->latestTicket($customer);
        $this->assertSame($ticket->id, $reopened->id, 'the same ticket is reopened');
        $this->assertSame('open', $reopened->status);
        $this->assertTrue(
            ChatTicketLog::where('contact_id', $customer->id)->where('description', 'Conversation was moved from closed to open')->exists()
        );
        $this->assertSame(1, ConversationRating::where('contact_id', $customer->id)->count());
        $this->assertSame(1, Contact::where('organization_id', $this->organization->id)->where('channel', $channel)->count());
        $this->assertSame(2, Chat::where('contact_id', $customer->id)->where('type', 'inbound')->count());
    }

    /** المنصّات تعيد الإرسال إن تأخّر ردّنا: الرسالة نفسها تُسجَّل مرّة. */
    #[DataProvider('channels')]
    public function test_a_retried_delivery_is_recorded_once(string $channel): void
    {
        $id = match ($channel) {
            Channel::WHATSAPP => 'wamid.RETRY-1',
            Channel::TELEGRAM => '4242',
            Channel::WEBCHAT => '6f1c1f43-6d0f-4b39-9c56-2d2b1b0f7a10',
        };

        $this->customerWrites($channel, 'السلام عليكم', $id);
        $this->customerWrites($channel, 'السلام عليكم', $id);

        $customer = $this->customer($channel);
        $this->assertSame(1, Chat::where('contact_id', $customer->id)->where('type', 'inbound')->count());
        $this->assertCount(1, $this->inboxBroadcasts($customer, 'inbound'));
    }

    /** ردّ قناة أخرى لا يُجيب هنا، وعميل القناة لا يرى إلا ما كُتب لها. */
    #[DataProvider('channels')]
    public function test_only_the_replies_chosen_for_the_channel_answer(string $channel): void
    {
        foreach (self::CHANNELS as $other) {
            $this->autoReply('الدوام', "دوام {$other}", [$other]);
        }

        $this->customerWrites($channel, 'متى الدوام؟');

        $this->assertSame(["دوام {$channel}"], $this->deliveredTexts($channel));
        foreach (array_diff(self::CHANNELS, [$channel]) as $other) {
            $this->assertSame([], $this->deliveredTexts($other), "nothing was sent on {$other}");
        }
    }

    // ───────────────────── مساعدات ─────────────────────

    private function whatsappReceipts(Chat $message): void
    {
        $this->assertNotEmpty($message->wam_id, 'Graph returned an id for the agent message');

        foreach (['sent', 'delivered', 'read'] as $status) {
            $this->whatsappStatus($message->wam_id, $status);
        }
    }

    private function assertSurveyButton(string $channel, string $link): void
    {
        $label = ConversationRatingService::DEFAULT_BUTTON_LABEL;
        $sent = last($this->deliveredTo($channel));

        match ($channel) {
            Channel::WHATSAPP => $this->assertSame(
                ['name' => 'cta_url', 'parameters' => ['display_text' => $label, 'url' => $link]],
                $sent['interactive']['action'] ?? null
            ),
            Channel::TELEGRAM => $this->assertSame(
                ['inline_keyboard' => [[['text' => $label, 'url' => $link]]]],
                is_string($sent['reply_markup'] ?? null) ? json_decode($sent['reply_markup'], true) : ($sent['reply_markup'] ?? null)
            ),
            Channel::WEBCHAT => $this->assertSame(
                ['kind' => 'cta_url', 'display_text' => $label, 'url' => $link],
                array_intersect_key($sent['interactive'] ?? [], array_flip(['kind', 'display_text', 'url']))
            ),
        };
    }
}
