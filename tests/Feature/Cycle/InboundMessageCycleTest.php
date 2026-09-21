<?php

namespace Tests\Feature\Cycle;

use App\Models\Chat;
use App\Models\ChatLog;
use App\Models\Contact;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * دورة الاستقبال كاملة: من طلب Meta إلى صفٍّ في المحادثة.
 *
 * الطابور متزامن في الاختبارات، فالطلب الواحد يمرّ بالسلسلة كلّها:
 * WebhookController ⇐ ProcessIncomingMessageJob ⇐ جهة الاتصال والمحادثة
 * والسجلّ. وهو ما يجعل هذا الملف يختبر الوصل بين الطبقات لا كل طبقة وحدها.
 */
class InboundMessageCycleTest extends CycleTestCase
{
    private function receive(array $message, ?array $profile = null): \Illuminate\Testing\TestResponse
    {
        return $this->postJson($this->webhookUrl(), $this->payload('messages', [
            'contacts' => [[
                'profile' => ['name' => $profile['name'] ?? 'أحمد'],
                'wa_id' => $message['from'] ?? '966502486051',
            ]],
            'messages' => [$message],
        ]));
    }

    // ------------------------------------------------- الطريق كاملاً

    public function test_a_text_message_creates_contact_chat_and_log(): void
    {
        $message = $this->textMessage();

        $this->receive($message)->assertOk();

        $contact = Contact::where('organization_id', $this->organization->id)->first();
        $this->assertNotNull($contact, 'لم تُنشأ جهة الاتصال');
        $this->assertSame('+966502486051', $contact->phone);
        $this->assertSame('أحمد', $contact->first_name);

        $chat = Chat::where('organization_id', $this->organization->id)->first();
        $this->assertNotNull($chat, 'لم تُنشأ المحادثة');
        $this->assertSame('inbound', $chat->type);
        $this->assertSame($message['id'], $chat->wam_id);
        $this->assertSame($contact->id, (int) $chat->contact_id);

        $this->assertSame(
            1,
            ChatLog::where('entity_type', 'chat')->where('entity_id', $chat->id)->count(),
            'لم يُنشأ سجلّ المحادثة'
        );
    }

    public function test_the_message_body_is_stored(): void
    {
        $this->receive($this->textMessage(['text' => ['body' => 'أريد الاستفسار عن الطلب']]))->assertOk();

        $metadata = json_decode(Chat::first()->metadata, true);

        $this->assertSame('text', $metadata['type']);
        $this->assertSame('أريد الاستفسار عن الطلب', $metadata['text']['body']);
    }

    public function test_an_inbound_message_is_unread(): void
    {
        $this->receive($this->textMessage())->assertOk();

        $this->assertSame(0, (int) Chat::first()->is_read);
    }

    /** ورسالتان من الرقم نفسه تجتمعان في جهة اتصال واحدة. */
    public function test_two_messages_from_one_number_share_the_contact(): void
    {
        $this->receive($this->textMessage())->assertOk();
        $this->receive($this->textMessage())->assertOk();

        $this->assertSame(1, Contact::where('organization_id', $this->organization->id)->count());
        $this->assertSame(2, Chat::where('organization_id', $this->organization->id)->count());
    }

    /** ورقمان مختلفان جهتا اتصال. */
    public function test_two_numbers_make_two_contacts(): void
    {
        $this->receive($this->textMessage(['from' => '966502486051']))->assertOk();
        $this->receive($this->textMessage(['from' => '201025894984']))->assertOk();

        $this->assertSame(2, Contact::where('organization_id', $this->organization->id)->count());
    }

    // ------------------------------------------------- التكرار

    /**
     * Meta تُعيد إرسال الحمولة إن تأخّر ردّنا، فالرسالة نفسها قد تصل مرّتين.
     * المعرّف فريد، فلا تُخزَّن مرّتين.
     */
    public function test_the_same_message_is_not_stored_twice(): void
    {
        $message = $this->textMessage();

        $this->receive($message)->assertOk();
        $this->receive($message)->assertOk();

        $this->assertSame(1, Chat::where('wam_id', $message['id'])->count());
    }

    // ------------------------------------------------- حمولات ناقصة

    /** رسالة بلا مُرسِل لا تُنشئ شيئاً ولا تُسقط الطلب. */
    public function test_a_message_without_a_sender_is_skipped(): void
    {
        $this->postJson($this->webhookUrl(), $this->payload('messages', [
            'messages' => [['id' => 'wamid.x', 'type' => 'text', 'text' => ['body' => 'بلا مُرسِل']]],
        ]))->assertOk();

        $this->assertSame(0, Contact::where('organization_id', $this->organization->id)->count());
        $this->assertSame(0, Chat::where('organization_id', $this->organization->id)->count());
    }

    /** ورقم لا تقبله libphonenumber يُحفظ كما أرسلته واتساب. */
    public function test_a_number_libphonenumber_rejects_is_still_stored(): void
    {
        $this->receive($this->textMessage(['from' => '15550001234']))->assertOk();

        $contact = Contact::where('organization_id', $this->organization->id)->first();

        $this->assertNotNull($contact);
        $this->assertSame('+15550001234', $contact->phone);
    }

    // ------------------------------------------------- الأنواع

    /**
     * كل نوع رسالة يصل الخيط.
     *
     * تنزيل الوسائط وحده مُعزَل — يذهب إلى Meta بالرمز الحقيقي — وبقيّة
     * السلسلة تعمل كاملة.
     *
     * @dataProvider messageTypes
     */
    public function test_every_message_type_lands_in_the_thread(string $type, array $payload, bool $hasMedia): void
    {
        \Illuminate\Support\Facades\Bus::fake([\App\Jobs\ProcessMediaDownloadJob::class]);

        $this->receive($this->textMessage(array_merge(['type' => $type], $payload)))->assertOk();

        $chat = Chat::where('organization_id', $this->organization->id)->first();

        $this->assertNotNull($chat, $type . ': لم تُنشأ المحادثة');
        $this->assertSame('inbound', $chat->type);
        $this->assertSame(
            $type,
            json_decode($chat->metadata, true)['type'] ?? null,
            $type . ': النوع المحفوظ لا يطابق الوارد'
        );

        // الوسائط تُنزَّل في وظيفة منفصلة، وغيرها لا يُشغّلها.
        $hasMedia
            ? \Illuminate\Support\Facades\Bus::assertDispatched(\App\Jobs\ProcessMediaDownloadJob::class)
            : \Illuminate\Support\Facades\Bus::assertNotDispatched(\App\Jobs\ProcessMediaDownloadJob::class);
    }

    public static function messageTypes(): array
    {
        return [
            'نصّ' => ['text', ['text' => ['body' => 'مرحباً']], false],
            'صورة' => ['image', ['image' => ['id' => '111', 'mime_type' => 'image/jpeg', 'caption' => 'صورة']], true],
            'فيديو' => ['video', ['video' => ['id' => '222', 'mime_type' => 'video/mp4']], true],
            'صوت' => ['audio', ['audio' => ['id' => '333', 'mime_type' => 'audio/ogg']], true],
            'مستند' => ['document', ['document' => ['id' => '444', 'mime_type' => 'application/pdf', 'filename' => 'a.pdf']], true],
            'ملصق' => ['sticker', ['sticker' => ['id' => '555', 'mime_type' => 'image/webp']], true],
            'موقع' => ['location', ['location' => ['latitude' => 21.5, 'longitude' => 39.1]], false],
            'جهة اتصال' => ['contacts', ['contacts' => [['name' => ['formatted_name' => 'سالم']]]], false],
            'ردّ زرّ' => ['button', ['button' => ['text' => 'نعم', 'payload' => 'yes']], false],
            'ردّ تفاعلي' => ['interactive', ['interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => '1', 'title' => 'نعم']]], false],
        ];
    }

    // ------------------------------------------------- نافذة الأربع والعشرين

    /**
     * الرسالة الواردة تفتح نافذة الردّ.
     *
     * وهي أهمّ أثر للاستقبال على الإرسال: بدونها لا يستطيع الموظّف الردّ.
     */
    public function test_receiving_a_message_opens_the_reply_window(): void
    {
        $this->receive($this->textMessage())->assertOk();

        $contact = Contact::where('organization_id', $this->organization->id)->first();

        $this->assertTrue(
            \App\Helpers\MessagingWindowHelper::isMessagingWindowOpen($contact),
            'النافذة يجب أن تُفتح برسالة واردة الآن'
        );
    }

    // ------------------------------------------------- العزل

    /** حمولة منشأة لا تُنشئ بيانات في منشأة أخرى. */
    public function test_the_thread_belongs_to_the_organization_of_the_webhook(): void
    {
        $this->receive($this->textMessage())->assertOk();

        $this->assertSame(
            $this->organization->id,
            (int) Chat::first()->organization_id
        );
        $this->assertSame(
            $this->organization->id,
            (int) Contact::first()->organization_id
        );
    }

    /** ونفس الرقم في منشأتين جهتا اتصال منفصلتان. */
    public function test_the_same_number_in_two_organizations_stays_separate(): void
    {
        $this->receive($this->textMessage())->assertOk();

        $other = $this->organization->replicate();
        $other->identifier = (string) Str::uuid();
        $other->save();

        \App\Models\Subscription::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $other->id,
            'plan_id' => \App\Models\Subscription::where('organization_id', $this->organization->id)->value('plan_id'),
            'status' => 'active',
            'valid_until' => now()->addYear(),
        ]);

        $this->postJson('/webhook/whatsapp/' . $other->identifier, $this->payload('messages', [
            'contacts' => [['profile' => ['name' => 'أحمد'], 'wa_id' => '966502486051']],
            'messages' => [$this->textMessage()],
        ]))->assertOk();

        $this->assertSame(1, Contact::where('organization_id', $this->organization->id)->count());
        $this->assertSame(1, Contact::where('organization_id', $other->id)->count());
    }
}
