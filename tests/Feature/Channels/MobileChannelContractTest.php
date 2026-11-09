<?php

namespace Tests\Feature\Channels;

use App\Http\Controllers\ApiController;
use App\Models\ChannelAccount;
use App\Models\Chat;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\User;
use App\Services\Messaging\Channel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * عقد تطبيق الجوال.
 *
 * النسخة المنشورة على أجهزة المستخدمين اليوم تقرأ أسماء مفاتيح بعينها.
 * حذف واحد منها أو إعادة تسميته يكسر تطبيقاً لا نملك تحديثه فوراً — ولذلك
 * كل ما نُضيفه إضافيّ، وكل ما كان موجوداً يبقى.
 *
 * وما يحتاج مطوّر التطبيق معرفته موثَّق في MOBILE_CHANNELS.md.
 */
class MobileChannelContractTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    /** المفاتيح التي تقرأها النسخة المنشورة — لا يجوز أن يختفي واحد منها. */
    private const REQUIRED_CHAT_VALUE_KEYS = [
        'id', 'uuid', 'contact_uuid', 'contact_id', 'is_new_contact', 'phone',
        'formatted_phone_number', 'organization_id', 'latest_chat_created_at',
        'is_blocked', 'is_favorite', 'contact_full_name', 'unread_messages_count',
        'created_at', 'deleted_at', 'metadata', 'type', 'wam_id', 'status',
        'media', 'logs', 'user',
    ];

    private const REQUIRED_MESSAGE_KEYS = [
        'id', 'uuid', 'created_at', 'deleted_at', 'metadata', 'type',
        'wam_id', 'status', 'media', 'logs', 'user',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $owner = User::factory()->create(['role' => 'user']);
        $this->organization = Organization::factory()->create(['created_by' => $owner->id]);
    }

    /** كل مفتاح قائم باقٍ في حمولة المحادثة. */
    public function test_every_existing_chat_value_key_survives(): void
    {
        [$contact, $chat] = $this->whatsappConversation();

        $payload = $this->formatChatValue($chat, $contact);

        foreach (self::REQUIRED_CHAT_VALUE_KEYS as $key) {
            $this->assertArrayHasKey($key, $payload, "المفتاح {$key} اختفى من ردّ الموبايل");
        }
    }

    /** وكل مفتاح قائم باقٍ في حمولة الرسالة. */
    public function test_every_existing_message_key_survives(): void
    {
        [, $chat] = $this->whatsappConversation();

        $payload = $this->formatChatMessageV2($chat);

        foreach (self::REQUIRED_MESSAGE_KEYS as $key) {
            $this->assertArrayHasKey($key, $payload, "المفتاح {$key} اختفى من ردّ الموبايل");
        }
    }

    /** ومحادثة واتساب تصل بقيم لم تتغيّر. */
    public function test_a_whatsapp_conversation_is_unchanged(): void
    {
        [$contact, $chat] = $this->whatsappConversation();

        $payload = $this->formatChatValue($chat, $contact);

        $this->assertSame('+966500000001', $payload['phone']);
        $this->assertSame(Channel::WHATSAPP, $payload['channel']);
        // معرّف واتساب يبقى خاماً بلا بادئة.
        $this->assertSame('wamid.HBgMOTY2', $payload['wam_id']);
    }

    /**
     * ومحادثة قناة أخرى تصل بـ phone = null — وهو أخطر تغيير على التطبيق،
     * فيجب أن يكون صريحاً لا مفاجئاً.
     */
    public function test_a_channel_conversation_reports_a_null_phone_and_its_channel(): void
    {
        [$contact, $chat] = $this->telegramConversation();

        $payload = $this->formatChatValue($chat, $contact);

        $this->assertNull($payload['phone']);
        $this->assertSame(Channel::TELEGRAM, $payload['channel']);
        $this->assertSame('tg-1', $payload['channel_identifier']);
        // والمعرّف منمَّق — التطبيق يستعمله مفتاحاً لا يحلّله.
        $this->assertStringStartsWith('tg:', $payload['wam_id']);
    }

    /** والقناة تصل على الرسالة نفسها كذلك. */
    public function test_the_message_payload_carries_its_channel(): void
    {
        [, $chat] = $this->telegramConversation();

        $payload = $this->formatChatMessageV2($chat);

        $this->assertSame(Channel::TELEGRAM, $payload['channel']);
    }

    /** والرسالة القديمة بلا عمود قناة تصل whatsapp لا فارغة. */
    public function test_a_message_without_a_channel_column_defaults_to_whatsapp(): void
    {
        $payload = $this->formatChatMessageV2((object) [
            'id' => 1,
            'uuid' => 'x',
            'metadata' => '{"type":"text","text":{"body":"مرحبا"}}',
            'type' => 'inbound',
            'wam_id' => 'wamid.X',
            'status' => 'delivered',
        ]);

        $this->assertSame(Channel::WHATSAPP, $payload['channel']);
    }

    /** ونافذة الردّ تصل بساعاتها لكل قناة. */
    public function test_the_window_payload_reports_hours_per_channel(): void
    {
        [$whatsapp] = $this->whatsappConversation();
        [$telegram] = $this->telegramConversation();

        $this->assertSame(
            24,
            \App\Helpers\MessagingWindowHelper::payloadForContact($whatsapp)['messaging_window_hours']
        );

        // null يعني «بلا نافذة» — والتطبيق يُخفي شريط الـ٢٤ ساعة عندها.
        $this->assertNull(
            \App\Helpers\MessagingWindowHelper::payloadForContact($telegram)['messaging_window_hours']
        );
    }

    // ───────────────────── مساعدات ─────────────────────

    private function formatChatValue($chat, $contact): array
    {
        $method = new ReflectionMethod(ApiController::class, 'formatChatValue');
        $method->setAccessible(true);

        return $method->invoke(app(ApiController::class), $chat, $contact);
    }

    private function formatChatMessageV2($chat): array
    {
        $method = new ReflectionMethod(ApiController::class, 'formatChatMessageV2');
        $method->setAccessible(true);

        return $method->invoke(app(ApiController::class), $chat);
    }

    /** @return array{0: Contact, 1: Chat} */
    private function whatsappConversation(): array
    {
        $contact = Contact::create([
            'organization_id' => $this->organization->id,
            'phone' => '+966500000001',
            'first_name' => 'أحمد',
            'created_by' => 0,
        ]);

        $chat = Chat::create([
            'organization_id' => $this->organization->id,
            'wam_id' => 'wamid.HBgMOTY2',
            'contact_id' => $contact->id,
            'type' => 'inbound',
            'metadata' => '{"type":"text","text":{"body":"مرحبا"}}',
            'status' => 'delivered',
            'created_at' => now(),
        ]);

        return [$contact->fresh(), $chat->fresh()];
    }

    /** @return array{0: Contact, 1: Chat} */
    private function telegramConversation(): array
    {
        $account = ChannelAccount::create([
            'organization_id' => $this->organization->id,
            'channel' => Channel::TELEGRAM,
            'external_id' => 'bot-m',
            'credentials' => ['bot_token' => 'x'],
            'status' => ChannelAccount::STATUS_ACTIVE,
            'created_by' => 0,
        ]);

        $contact = Contact::create([
            'organization_id' => $this->organization->id,
            'channel' => Channel::TELEGRAM,
            'channel_identifier' => 'tg-1',
            'channel_account_id' => $account->id,
            'phone' => null,
            'first_name' => 'سارة',
            'created_by' => 0,
        ]);

        $chat = Chat::create([
            'organization_id' => $this->organization->id,
            'wam_id' => 'tg:bot-m:tg-1:7',
            'contact_id' => $contact->id,
            'type' => 'inbound',
            'metadata' => '{"type":"text","text":{"body":"مرحبا"}}',
            'status' => 'delivered',
            'channel' => Channel::TELEGRAM,
            'created_at' => now(),
        ]);

        return [$contact->fresh(), $chat->fresh()];
    }
}
