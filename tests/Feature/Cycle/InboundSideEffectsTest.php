<?php

namespace Tests\Feature\Cycle;

use App\Events\NewChatEvent;
use App\Jobs\ProcessAutoReplyJob;
use App\Jobs\ProcessMediaDownloadJob;
use App\Models\Chat;
use App\Models\ChatTicket;
use App\Models\Contact;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;

/**
 * ما يترتّب على الرسالة الواردة غير حفظها.
 *
 * الرسالة الواحدة تُشغّل سلسلة: تذكرة تُفتح أو تُسنَد، وردّ آلي يُفحَص،
 * وإشعار خارج الدوام، وحدث يُبثّ للواجهة. وسقوط أيٍّ منها صامتاً يعني أن
 * المحادثة تصل ولا يُنبَّه أحد — وهو أسوأ من ألّا تصل.
 */
class InboundSideEffectsTest extends CycleTestCase
{
    private function receive(array $overrides = []): void
    {
        $this->postJson($this->webhookUrl(), $this->payload('messages', [
            'contacts' => [['profile' => ['name' => 'أحمد'], 'wa_id' => '966502486051']],
            'messages' => [$this->textMessage($overrides)],
        ]))->assertOk();
    }

    // ------------------------------------------------- التذكرة

    /** التذاكر معطّلة افتراضياً — فلا تُفتح تذكرة ولا يقع خطأ. */
    public function test_no_ticket_when_the_feature_is_off(): void
    {
        $this->receive();

        $this->assertSame(0, ChatTicket::count());
    }

    /** أوّل رسالة تفتح تذكرة للمحادثة حين تكون الميزة مفعّلة. */
    public function test_an_incoming_message_opens_a_chat_ticket(): void
    {
        $this->enableTickets();
        $this->receive();

        $contact = Contact::where('organization_id', $this->organization->id)->first();

        $this->assertSame(
            1,
            ChatTicket::where('contact_id', $contact->id)->count(),
            'لم تُفتح تذكرة للمحادثة'
        );
    }

    /** ورسالة ثانية لا تفتح تذكرة ثانية. */
    public function test_a_second_message_does_not_open_a_second_ticket(): void
    {
        $this->enableTickets();
        $this->receive();
        $this->receive();

        $contact = Contact::where('organization_id', $this->organization->id)->first();

        $this->assertSame(1, ChatTicket::where('contact_id', $contact->id)->count());
    }

    private function enableTickets(): void
    {
        $metadata = json_decode($this->organization->metadata, true);
        $metadata['tickets'] = ['active' => true, 'assignment' => 'manual'];

        \App\Models\Organization::where('id', $this->organization->id)
            ->update(['metadata' => json_encode($metadata)]);
    }

    // ------------------------------------------------- الردّ الآلي

    public function test_the_auto_reply_check_runs_for_an_incoming_message(): void
    {
        Bus::fake([ProcessAutoReplyJob::class, ProcessMediaDownloadJob::class]);

        $this->receive();

        Bus::assertDispatched(ProcessAutoReplyJob::class, 1);
        Bus::assertDispatched(ProcessAutoReplyJob::class, fn ($job) => $job->queue === 'autoreplies');
    }

    /**
     * واشتراك بلغ حدّ الرسائل لا يُشغّل ردّاً آلياً.
     *
     * الردّ الآلي رسالة صادرة تُحتسب على الحدّ — فتشغيله بعد بلوغه يُنفق من
     * رصيد لا يملكه العميل.
     */
    public function test_no_auto_reply_once_the_message_limit_is_reached(): void
    {
        $plan = SubscriptionPlan::create([
            'name' => 'Tiny', 'price' => 0, 'period' => 'monthly',
            'metadata' => json_encode(['message_limit' => 0]),
        ]);
        Subscription::where('organization_id', $this->organization->id)->update(['plan_id' => $plan->id]);

        Bus::fake([ProcessAutoReplyJob::class, ProcessMediaDownloadJob::class]);

        $this->receive();

        Bus::assertNotDispatched(ProcessAutoReplyJob::class);
    }

    // ------------------------------------------------- البثّ للواجهة

    /** الواجهة المفتوحة تعرف بالرسالة فور وصولها. */
    public function test_the_arrival_is_broadcast_to_the_open_screen(): void
    {
        Event::fake([NewChatEvent::class]);

        $this->receive();

        Event::assertDispatched(NewChatEvent::class);
    }

    /** ورسالة وسائط تؤجّل البثّ حتى ينتهي التنزيل. */
    public function test_a_media_message_broadcasts_after_the_download_not_before(): void
    {
        Event::fake([NewChatEvent::class]);
        Bus::fake([ProcessMediaDownloadJob::class]);

        $this->receive([
            'type' => 'image',
            'image' => ['id' => '111', 'mime_type' => 'image/jpeg'],
        ]);

        Bus::assertDispatched(ProcessMediaDownloadJob::class);
        Event::assertNotDispatched(NewChatEvent::class);
    }

    // ------------------------------------------------- إشعار خارج الدوام

    /**
     * خارج الدوام: إشعار واحد في الساعة لا أكثر.
     *
     * بلا هذا الحدّ يتلقّى من يرسل عشر رسائل عشرةَ إشعارات متطابقة.
     */
    public function test_the_away_notice_is_sent_at_most_once_an_hour(): void
    {
        $this->enableWorkingHoursOutsideNow();

        $this->receive();
        $first = Chat::where('type', 'outbound')->count();

        $this->receive();
        $second = Chat::where('type', 'outbound')->count();

        $this->assertSame($first, $second, 'أُرسل إشعار ثانٍ في الساعة نفسها');
    }

    /** وداخل الدوام لا إشعار إطلاقاً. */
    public function test_no_away_notice_during_working_hours(): void
    {
        $this->enableWorkingHoursCoveringNow();

        $this->receive();

        $this->assertSame(0, Chat::where('type', 'outbound')->count());
    }

    private function enableWorkingHoursOutsideNow(): void
    {
        \App\Models\Addon::factory()->create(['name' => 'Working Hours', 'status' => 1, 'is_active' => 1]);

        $metadata = json_decode($this->organization->metadata, true);
        // فترة لا تشمل اللحظة الحالية مهما كانت.
        $metadata['working_hours'] = [['day' => (int) now()->addDays(2)->dayOfWeek, 'open' => '09:00', 'close' => '09:01']];
        $metadata['working_hours_outside_message'] = 'نعتذر، خارج أوقات العمل';

        \App\Models\Organization::where('id', $this->organization->id)
            ->update(['metadata' => json_encode($metadata)]);
    }

    private function enableWorkingHoursCoveringNow(): void
    {
        \App\Models\Addon::factory()->create(['name' => 'Working Hours', 'status' => 1, 'is_active' => 1]);

        $metadata = json_decode($this->organization->metadata, true);
        $metadata['working_hours'] = [
            ['day' => 0, 'open' => '00:00', 'close' => '23:59'],
            ['day' => 1, 'open' => '00:00', 'close' => '23:59'],
            ['day' => 2, 'open' => '00:00', 'close' => '23:59'],
            ['day' => 3, 'open' => '00:00', 'close' => '23:59'],
            ['day' => 4, 'open' => '00:00', 'close' => '23:59'],
            ['day' => 5, 'open' => '00:00', 'close' => '23:59'],
            ['day' => 6, 'open' => '00:00', 'close' => '23:59'],
        ];
        $metadata['working_hours_outside_message'] = 'نعتذر، خارج أوقات العمل';

        \App\Models\Organization::where('id', $this->organization->id)
            ->update(['metadata' => json_encode($metadata)]);
    }

    // ------------------------------------------------- صدى التطبيق

    /**
     * رسالة أرسلها التاجر من تطبيق واتساب نفسه (التعايش).
     *
     * تصل عبر smb_message_echoes، ويجب أن تظهر في خيط المحادثة عندنا صادرةً
     * — وإلا رأى الموظّف نصف المحادثة.
     */
    public function test_an_echo_from_the_whatsapp_app_lands_as_outbound(): void
    {
        $this->postJson($this->webhookUrl(), $this->payload('smb_message_echoes', [
            'message_echoes' => [[
                'id' => 'wamid.echo1',
                'to' => '966502486051',
                'timestamp' => (string) now()->timestamp,
                'type' => 'text',
                'text' => ['body' => 'ردّ من الجوال'],
            ]],
        ]))->assertOk();

        $chat = Chat::where('organization_id', $this->organization->id)->first();

        $this->assertNotNull($chat, 'لم تُحفظ رسالة الصدى');
        $this->assertSame('outbound', $chat->type);
        $this->assertSame('wamid.echo1', $chat->wam_id);
    }

    public function test_an_echo_creates_the_contact_it_was_sent_to(): void
    {
        $this->postJson($this->webhookUrl(), $this->payload('smb_message_echoes', [
            'message_echoes' => [[
                'id' => 'wamid.echo2',
                'to' => '966502486051',
                'type' => 'text',
                'text' => ['body' => 'أهلاً'],
            ]],
        ]))->assertOk();

        $this->assertSame('+966502486051', Contact::first()->phone);
    }

    /** وصدى مكرّر لا يُخزَّن مرّتين. */
    public function test_a_repeated_echo_is_stored_once(): void
    {
        $echo = ['id' => 'wamid.echo3', 'to' => '966502486051', 'type' => 'text', 'text' => ['body' => 'أهلاً']];

        foreach ([1, 2] as $ignored) {
            $this->postJson($this->webhookUrl(), $this->payload('smb_message_echoes', [
                'message_echoes' => [$echo],
            ]))->assertOk();
        }

        $this->assertSame(1, Chat::where('wam_id', 'wamid.echo3')->count());
    }

    /** والصدى لا يفتح نافذة الردّ — هو رسالتنا نحن لا رسالة العميل. */
    public function test_an_echo_does_not_open_the_reply_window(): void
    {
        $this->postJson($this->webhookUrl(), $this->payload('smb_message_echoes', [
            'message_echoes' => [[
                'id' => 'wamid.echo4', 'to' => '966502486051',
                'type' => 'text', 'text' => ['body' => 'أهلاً'],
            ]],
        ]))->assertOk();

        $this->assertFalse(
            \App\Helpers\MessagingWindowHelper::isMessagingWindowOpen(Contact::first())
        );
    }
}
