<?php

namespace Tests\Unit;

use App\Services\Chat\ChatBroadcastPayloadBuilder;
use PHPUnit\Framework\TestCase;

/**
 * ChatLog::relatedEntities تُرجع null حين تشير entity_id إلى رسالة غير موجودة،
 * وخمسة مواقع بثّ تمرّرها بلا احتياط. البناء من ذلك كان يُنتج «رسالة» كل حقولها
 * null — بلا معرّف ولا تاريخ — والتطبيق يفعل createdAt! على الواردة فينهار.
 *
 * هذه الاختبارات تحرس أمرين: أن الفارغ يُرفض، وأن الرسالة الحقيقية تمرّ كما كانت.
 */
class ChatBroadcastPayloadGuardTest extends TestCase
{
    private ChatBroadcastPayloadBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = new ChatBroadcastPayloadBuilder();
    }

    /**
     * صفّ رسالة كما يصل البنّاء.
     *
     * نمرّره مصفوفةً لا نموذج Chat: الـ accessor في النموذج يمرّ بـ
     * DateTimeHelper الذي يقرأ الجلسة ويستعلم جدول المنشآت، فيحتاج تمهيد
     * Laravel وقاعدة بيانات. والبنّاء يفعل (array) $value على غير النماذج،
     * فالمسار المفحوص هو نفسه، ولا contact_id فيه فلا يُستعلم شيء.
     */
    private function chat(array $attributes = []): array
    {
        return array_merge([
            'id' => 991,
            'uuid' => 'e3f1c0b2-0000-4000-8000-000000000001',
            'organization_id' => 211,
            'type' => 'inbound',
            'status' => 'delivered',
            'wam_id' => 'wamid.TEST',
            'metadata' => '{"type":"text","text":{"body":"مرحبا"}}',
            'created_at' => '2026-08-15 10:00:00',
        ], $attributes);
    }

    // ---------- الحارس ----------

    public function test_a_null_entity_produces_no_value_instead_of_an_all_null_message(): void
    {
        $this->assertSame([], $this->builder->buildMinimalValue(null, 211, false));
    }

    public function test_an_empty_array_produces_no_value(): void
    {
        $this->assertSame([], $this->builder->buildMinimalValue([], 211, false));
    }

    /** بلا معرّف = ليست رسالة، مهما حملت من حقول أخرى. */
    public function test_a_value_without_an_id_produces_no_value(): void
    {
        $this->assertSame([], $this->builder->buildMinimalValue(['type' => 'inbound'], 211, false));
    }

    public function test_an_unresolvable_entity_is_not_broadcastable(): void
    {
        $wrapped = $this->builder->buildWrappedChat(
            [['type' => 'chat', 'value' => null]],
            211,
            false
        );

        $this->assertSame([], $wrapped[0]['value']);
        $this->assertFalse($this->builder->hasUsableChat($wrapped));
    }

    public function test_has_usable_chat_rejects_malformed_wrappers(): void
    {
        $this->assertFalse($this->builder->hasUsableChat(null));
        $this->assertFalse($this->builder->hasUsableChat([]));
        $this->assertFalse($this->builder->hasUsableChat([['type' => 'chat', 'value' => []]]));
        $this->assertFalse($this->builder->hasUsableChat([['type' => 'chat', 'value' => ['id' => null]]]));
    }

    // ---------- ما كان يعمل يجب أن يبقى كما هو ----------

    public function test_a_real_message_still_carries_created_at(): void
    {
        $value = $this->builder->buildMinimalValue($this->chat(), 211, false);

        $this->assertNotSame([], $value);
        $this->assertSame(991, $value['id']);
        $this->assertNotNull($value['created_at'], 'التاريخ هو ما ينهار عليه التطبيق — لا يجوز أن يغيب');
        $this->assertSame('inbound', $value['type']);
        $this->assertSame('delivered', $value['status']);
    }

    public function test_a_real_message_is_broadcastable_through_the_wrapper(): void
    {
        $wrapped = $this->builder->buildWrappedChat(
            [['type' => 'chat', 'value' => $this->chat()]],
            211,
            false
        );

        $this->assertTrue($this->builder->hasUsableChat($wrapped));
        $this->assertSame(991, $wrapped[0]['value']['id']);
        $this->assertSame('2026-08-15 10:00:00', $wrapped[0]['value']['created_at']);
    }

    /** الغلاف غير المصفوف (الشكل القديم) ما زال مدعوماً. */
    public function test_the_flat_wrapper_shape_still_works(): void
    {
        $wrapped = $this->builder->buildWrappedChat(
            ['type' => 'chat', 'value' => $this->chat()],
            211,
            false
        );

        $this->assertTrue($this->builder->hasUsableChat($wrapped));
    }

    /** المفاتيح المجاورة (tempMessageId وغيرها) تبقى كما مرّرها الباثّ. */
    public function test_sibling_keys_are_preserved(): void
    {
        $wrapped = $this->builder->buildWrappedChat(
            [['type' => 'chat', 'value' => $this->chat(), 'tempMessageId' => -1]],
            211,
            false
        );

        $this->assertSame(-1, $wrapped[0]['tempMessageId']);
        $this->assertSame('chat', $wrapped[0]['type']);
    }

    /** الرسالة الحقيقية تظلّ دون سقف Pusher فلا يمسّها سلّم التقليص. */
    public function test_a_normal_message_fits_pusher_without_being_shrunk(): void
    {
        $wrapped = $this->builder->buildWrappedChat(
            [['type' => 'chat', 'value' => $this->chat()]],
            211,
            false
        );

        $payload = $this->builder->fitToPusherLimit(['chat' => $wrapped]);

        $this->assertSame('2026-08-15 10:00:00', $payload['chat'][0]['value']['created_at']);
        $this->assertNotNull($payload['chat'][0]['value']['metadata']);
        $this->assertLessThanOrEqual(
            ChatBroadcastPayloadBuilder::PUSHER_MAX_PAYLOAD_BYTES,
            strlen((string) json_encode($payload))
        );
    }
}
