<?php

namespace Tests\Feature\Channels;

use App\Helpers\MessagingWindowHelper;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\User;
use App\Services\Messaging\Channel;
use App\Services\Messaging\ChannelManager;
use App\Services\Messaging\Support\MessageId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الاختبار الأهمّ في هذه الدفعة: واتساب لم تتأثّر.
 *
 * طبقة القنوات أُضيفت جانب مسار واتساب لا داخله، لكن ثلاثة أشياء تلامسه
 * فعلاً: عمود channel الجديد على contacts و chats، ونافذة الردّ التي صارت
 * تُقرأ من قدرات القناة، والسائق الذي يغلّف WhatsappService. هذا الملفّ
 * يُثبّت أن أيّاً منها لم يُغيّر سلوكاً قائماً.
 */
class WhatsappUnaffectedTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $owner = User::factory()->create(['role' => 'user']);
        $this->organization = Organization::factory()->create(['created_by' => $owner->id]);
    }

    // ───────────────────── القيمة الافتراضية ─────────────────────

    /**
     * جهة اتصال تُنشأ بالطريقة القديمة (بلا ذكر للقناة) تخرج whatsapp.
     *
     * هذا ما يجعل ٨١٧ ألف صفّ قائم صحيحاً بلا تعبئة رجعية: القيمة
     * الافتراضية في المخطّط لا في الكود.
     */
    public function test_a_contact_created_the_old_way_is_a_whatsapp_contact(): void
    {
        $contact = Contact::create([
            'organization_id' => $this->organization->id,
            'phone' => '+966500000001',
            'first_name' => 'أحمد',
            'created_by' => 0,
        ]);

        $this->assertSame(Channel::WHATSAPP, $contact->fresh()->channel);
        $this->assertNull($contact->fresh()->channel_identifier);
    }

    /** ورسالة تُنشأ بالطريقة القديمة كذلك. */
    public function test_a_chat_created_the_old_way_is_a_whatsapp_chat(): void
    {
        $contact = $this->whatsappContact('+966500000002');

        $chat = \App\Models\Chat::create([
            'organization_id' => $this->organization->id,
            'wam_id' => 'wamid.HBgMOTY2NTAwMDAwMDAy',
            'contact_id' => $contact->id,
            'type' => 'inbound',
            'metadata' => '{"type":"text","text":{"body":"مرحبا"}}',
            'status' => 'delivered',
            'created_at' => now(),
        ]);

        $this->assertSame(Channel::WHATSAPP, $chat->fresh()->channel);
    }

    // ───────────────────── معرّفات الرسائل ─────────────────────

    /**
     * معرّف واتساب يبقى خاماً. لو نمّقناه لانقطعت مطابقة حالات التسليم
     * الواردة من Meta على كل رسالة قائمة.
     */
    public function test_a_whatsapp_message_id_is_never_namespaced(): void
    {
        $raw = 'wamid.HBgMOTY2NTAwMDAwMDAxFQIAEhgUM0E0RjhCQzhBRDkzNEE5NkY2NTcA';

        $this->assertSame(
            $raw,
            MessageId::namespaced(Channel::WHATSAPP, '626950913834572', $raw)
        );

        $this->assertFalse(MessageId::isNamespaced($raw));
        $this->assertSame(Channel::WHATSAPP, MessageId::channelOf($raw));
    }

    // ───────────────────── نافذة الردّ ─────────────────────

    /** نافذة واتساب ما زالت ٢٤ ساعة بالضبط. */
    public function test_the_whatsapp_reply_window_is_still_twenty_four_hours(): void
    {
        $contact = $this->whatsappContact('+966500000003');

        $this->assertSame(24, MessagingWindowHelper::windowHoursFor($contact));
        $this->assertSame(24, MessagingWindowHelper::WINDOW_HOURS);
    }

    /** ومفتوحة داخلها، مغلقة خارجها — كما كانت حرفياً. */
    public function test_the_window_opens_and_closes_exactly_as_before(): void
    {
        $contact = $this->whatsappContact('+966500000004');

        $contact->last_inbound_chat_created_at = now()->subHours(23)->toDateTimeString();
        $this->assertTrue(MessagingWindowHelper::isMessagingWindowOpen($contact));

        $contact->last_inbound_chat_created_at = now()->subHours(25)->toDateTimeString();
        $this->assertFalse(MessagingWindowHelper::isMessagingWindowOpen($contact));
    }

    /** وبلا رسالة واردة تبقى مغلقة. */
    public function test_a_contact_that_never_wrote_has_a_closed_window(): void
    {
        $contact = $this->whatsappContact('+966500000005');

        $this->assertFalse(MessagingWindowHelper::isMessagingWindowOpen($contact));
    }

    /**
     * مفاتيح payloadForContact التي يقرأها التطبيق المنشور باقية كلّها.
     *
     * التطبيق على أجهزة المستخدمين اليوم يقرأ هذه الأسماء بعينها. حذف
     * واحد منها أو إعادة تسميته يكسر نسخةً منشورة لا نملك تحديثها فوراً.
     */
    public function test_the_mobile_window_payload_keeps_every_existing_key(): void
    {
        $contact = $this->whatsappContact('+966500000006');
        $contact->last_inbound_chat_created_at = now()->subHour()->toDateTimeString();

        $payload = MessagingWindowHelper::payloadForContact($contact);

        foreach ([
            'last_inbound_chat_created_at',
            'last_inbound_chat_created_at_iso',
            'last_inbound_chat',
            'is_messaging_window_open',
        ] as $key) {
            $this->assertArrayHasKey($key, $payload, "المفتاح {$key} اختفى من ردّ الموبايل");
        }

        $this->assertTrue($payload['is_messaging_window_open']);
        $this->assertSame(24, $payload['messaging_window_hours']);
        $this->assertSame(Channel::WHATSAPP, $payload['channel']);
    }

    // ───────────────────── القدرات ─────────────────────

    /** قدرات واتساب: القوالب والتفاعلي والموقع — كلّها متاحة كما كانت. */
    public function test_whatsapp_keeps_all_the_features_it_had(): void
    {
        $capabilities = app(ChannelManager::class)->capabilities(Channel::WHATSAPP);

        $this->assertTrue($capabilities->templates);
        $this->assertTrue($capabilities->interactive);
        $this->assertTrue($capabilities->location);
        $this->assertTrue($capabilities->locationRequest);
        $this->assertTrue($capabilities->blockContact);
        $this->assertTrue($capabilities->mediaCaption);
        $this->assertTrue($capabilities->deliveryReceipts);
        $this->assertSame(24, $capabilities->windowHours);
    }

    /** وسائق واتساب يرفض جهة اتصال بلا رقم — نفس حراسة isSendableNumber. */
    public function test_the_whatsapp_driver_refuses_a_contact_without_a_number(): void
    {
        $driver = app(ChannelManager::class)->driver(Channel::WHATSAPP);

        $withNumber = $this->whatsappContact('+966500000007');
        $this->assertTrue($driver->canAddress($withNumber));

        $withoutNumber = new Contact(['organization_id' => $this->organization->id, 'phone' => null]);
        $this->assertFalse($driver->canAddress($withoutNumber));

        $blankNumber = new Contact(['organization_id' => $this->organization->id, 'phone' => '   ']);
        $this->assertFalse($driver->canAddress($blankNumber));
    }

    // ───────────────────── الفهرس الفريد القائم ─────────────────────

    /**
     * قيد التفرّد على الرقم ما زال يعمل: رقمان متطابقان في منشأة واحدة
     * يُرفضان. هذا هو ما يمنع تكرار جهات الاتصال منذ البداية.
     */
    public function test_the_existing_phone_uniqueness_still_holds(): void
    {
        $this->whatsappContact('+966500000008');

        $this->expectException(\Illuminate\Database\QueryException::class);

        Contact::create([
            'organization_id' => $this->organization->id,
            'phone' => '+966500000008',
            'created_by' => 0,
        ]);
    }

    /**
     * وجهات الاتصال بلا رقم لا تتصادم — وهو شرط وجود قنوات بلا هواتف.
     * MySQL لا يعدّ NULL تكراراً في الفهرس الفريد.
     */
    public function test_many_contacts_without_a_phone_can_coexist(): void
    {
        foreach (['tg-1', 'tg-2', 'tg-3'] as $identifier) {
            Contact::create([
                'organization_id' => $this->organization->id,
                'phone' => null,
                'channel' => Channel::TELEGRAM,
                'channel_identifier' => $identifier,
                'created_by' => 0,
            ]);
        }

        $this->assertSame(3, Contact::where('organization_id', $this->organization->id)->whereNull('phone')->count());
    }

    private function whatsappContact(string $phone): Contact
    {
        return Contact::create([
            'organization_id' => $this->organization->id,
            'phone' => $phone,
            'first_name' => 'عميل',
            'created_by' => 0,
        ]);
    }
}
