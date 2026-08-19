<?php

namespace Tests\Unit;

use App\Helpers\ChatMetadataHelper;
use App\Services\WhatsappService;
use PHPUnit\Framework\TestCase;

/**
 * رسالة «طلب الموقع» تفاعلية لا قالب — قوالب Meta لا تحوي هذا النوع.
 *
 * شكل الحمولة هو العقد مع Meta، وأي انحراف فيه يُرفض الطلب برسالة غامضة،
 * فنثبّته هنا حرفاً بحرف مقابل الوثيقة.
 */
class LocationRequestMessageTest extends TestCase
{
    private const TO = '+16505551234';

    public function test_payload_matches_the_documented_shape_exactly(): void
    {
        $payload = WhatsappService::buildLocationRequestPayload(self::TO, 'Share your pickup location');

        $this->assertSame([
            'type' => 'interactive',
            'interactive' => [
                'type' => 'location_request_message',
                'body' => ['text' => 'Share your pickup location'],
                'action' => ['name' => 'send_location'],
            ],
        ], $payload);
    }

    /**
     * الطلب الكامل كما يخرج على السلك — الغلاف الذي يبنيه sendMessage مدموجاً
     * بالحمولة. هذا ما تراه Meta، ومطابقته للوثيقة هي المعيار.
     */
    public function test_the_complete_request_matches_the_documented_example(): void
    {
        $envelope = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => self::TO,
        ];

        $full = array_merge($envelope, WhatsappService::buildLocationRequestPayload(
            self::TO,
            "Let's start with your pickup. You can either manually *enter an address* or *share your current location*."
        ));

        $this->assertSame([
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => self::TO,
            'type' => 'interactive',
            'interactive' => [
                'type' => 'location_request_message',
                'body' => [
                    'text' => "Let's start with your pickup. You can either manually *enter an address* or *share your current location*.",
                ],
                'action' => ['name' => 'send_location'],
            ],
        ], $full);
    }

    /** هذا النوع يقبل body و action فقط؛ إرسال header أو footer يجعل Meta ترفضه. */
    public function test_payload_carries_no_header_or_footer(): void
    {
        $interactive = WhatsappService::buildLocationRequestPayload(self::TO, 'نصّ')['interactive'];

        $this->assertSame(['type', 'body', 'action'], array_keys($interactive));
        $this->assertArrayNotHasKey('header', $interactive);
        $this->assertArrayNotHasKey('footer', $interactive);
    }

    public function test_arabic_body_survives_intact(): void
    {
        $body = 'شاركنا موقعك لنصل إليك بسرعة 📍';
        $payload = WhatsappService::buildLocationRequestPayload(self::TO, $body);

        $this->assertSame($body, $payload['interactive']['body']['text']);
    }

    /** القصّ بالمحارف لا بالبايتات، وإلا شُطر الحرف العربي وتلف النصّ. */
    public function test_body_is_truncated_by_characters_not_bytes(): void
    {
        $long = str_repeat('م', WhatsappService::LOCATION_REQUEST_MAX_BODY + 50);
        $text = WhatsappService::buildLocationRequestPayload(self::TO, $long)['interactive']['body']['text'];

        $this->assertSame(WhatsappService::LOCATION_REQUEST_MAX_BODY, mb_strlen($text));
        $this->assertSame($text, mb_convert_encoding($text, 'UTF-8', 'UTF-8'), 'النصّ المقصوص يجب أن يبقى UTF-8 سليماً');
    }

    public function test_a_body_at_the_limit_is_left_alone(): void
    {
        $exact = str_repeat('a', WhatsappService::LOCATION_REQUEST_MAX_BODY);
        $text = WhatsappService::buildLocationRequestPayload(self::TO, $exact)['interactive']['body']['text'];

        $this->assertSame(WhatsappService::LOCATION_REQUEST_MAX_BODY, mb_strlen($text));
    }

    // ---------- ردّ العميل: الويب هوك ----------

    /** الحمولة من وثيقة Meta حرفياً. */
    private function webhookLocationMessage(array $location): array
    {
        return [
            'context' => ['from' => '15550783881', 'id' => 'wamid.CONTEXT'],
            'from' => '16505551234',
            'id' => 'wamid.REPLY',
            'timestamp' => '1702920965',
            'location' => $location,
            'type' => 'location',
        ];
    }

    public function test_a_shared_location_is_stored_with_all_its_fields(): void
    {
        $stored = ChatMetadataHelper::minimalPayloadForStorage($this->webhookLocationMessage([
            'address' => '1071 5th Ave, New York, NY 10128',
            'latitude' => 40.782910059774,
            'longitude' => -73.959075808525,
            'name' => 'Solomon R. Guggenheim Museum',
        ]));

        $this->assertSame('location', $stored['type']);
        $this->assertSame(40.782910059774, $stored['location']['latitude']);
        $this->assertSame(-73.959075808525, $stored['location']['longitude']);
        $this->assertSame('Solomon R. Guggenheim Museum', $stored['location']['name']);
        $this->assertSame('1071 5th Ave, New York, NY 10128', $stored['location']['address']);
    }

    /** name و address اختياريان في الوثيقة — النقطة الخام وحدها يجب أن تمرّ. */
    public function test_a_bare_coordinate_pair_is_accepted(): void
    {
        $stored = ChatMetadataHelper::minimalPayloadForStorage($this->webhookLocationMessage([
            'latitude' => 24.7136,
            'longitude' => 46.6753,
        ]));

        $this->assertSame('location', $stored['type']);
        $this->assertSame(24.7136, $stored['location']['latitude']);
        $this->assertArrayNotHasKey('name', $stored['location']);
        $this->assertArrayNotHasKey('address', $stored['location']);
    }

    /** context.id يربط الموقع بالطلب الذي أرسلناه، فتعرف الواجهة أنه جواب لا مشاركة عابرة. */
    public function test_the_reply_keeps_the_context_of_the_request(): void
    {
        $stored = ChatMetadataHelper::minimalPayloadForStorage($this->webhookLocationMessage([
            'latitude' => 24.7136,
            'longitude' => 46.6753,
        ]));

        $this->assertSame('wamid.CONTEXT', $stored['context']['id']);
    }

    /** مشاركة موقع عفوية لا سياق لها — لا نخترع مفتاحاً فارغاً. */
    public function test_a_spontaneous_location_carries_no_context_key(): void
    {
        $stored = ChatMetadataHelper::minimalPayloadForStorage([
            'from' => '16505551234',
            'id' => 'wamid.SPONTANEOUS',
            'type' => 'location',
            'location' => ['latitude' => 24.7, 'longitude' => 46.6],
        ]);

        $this->assertArrayNotHasKey('context', $stored);
    }

    public function test_a_location_message_without_a_location_object_does_not_crash(): void
    {
        $stored = ChatMetadataHelper::minimalPayloadForStorage([
            'from' => '16505551234',
            'id' => 'wamid.X',
            'type' => 'location',
        ]);

        $this->assertSame('location', $stored['type']);
        $this->assertSame([], $stored['location']);
    }
}
