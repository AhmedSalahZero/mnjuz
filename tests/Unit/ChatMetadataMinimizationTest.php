<?php

namespace Tests\Unit;

use App\Helpers\ChatMetadataHelper;
use PHPUnit\Framework\TestCase;

/**
 * تقليص حمولة الويب هوك قبل حفظها في chats.metadata.
 *
 * أربعة أنواع — reaction و system و edit و revoke — كانت تسقط في الفرع
 * الافتراضي فتُحفظ خاماً بكل حقول الويب هوك (from و id و timestamp مع كل صف)،
 * ولا تجد فرعاً يعرضها فتظهر فقاعةً فارغة. الحمولات أدناه منسوخة حرفياً من
 * قاعدة البيانات الإنتاجية، لا مُختلَقة.
 */
class ChatMetadataMinimizationTest extends TestCase
{
    // ------------------------------------------------------------ reaction

    /** @return array<string, mixed> */
    private function realReactionPayload(): array
    {
        return [
            'from' => '966564127797',
            'id' => 'wamid.HBgMOTY2NTY0MTI3Nzk3FQIAEhgWM0VCMEI1M0EyRjJERkJEREU3QjBBNwA=',
            'timestamp' => '1761642669',
            'type' => 'reaction',
            'reaction' => [
                'message_id' => 'wamid.HBgMOTY2NTY0MTI3Nzk3FQIAERgSQzE3Nzk0OTc3RDlENUU5OTRGAA==',
                'emoji' => '❤️',
            ],
        ];
    }

    public function test_reaction_keeps_only_the_emoji_and_parent_message_id(): void
    {
        $out = ChatMetadataHelper::minimalPayloadForStorage($this->realReactionPayload());

        $this->assertSame([
            'type' => 'reaction',
            'reaction' => [
                'message_id' => 'wamid.HBgMOTY2NTY0MTI3Nzk3FQIAERgSQzE3Nzk0OTc3RDlENUU5OTRGAA==',
                'emoji' => '❤️',
            ],
        ], $out);
    }

    /**
     * الحقول الثلاثة التي كانت تُحفظ مع كل صفّ خام. غيابها هو كل الفرق في الحجم.
     */
    public function test_reaction_drops_the_envelope_fields(): void
    {
        $out = ChatMetadataHelper::minimalPayloadForStorage($this->realReactionPayload());

        $this->assertArrayNotHasKey('from', $out);
        $this->assertArrayNotHasKey('id', $out);
        $this->assertArrayNotHasKey('timestamp', $out);
    }

    /** إيموجي فارغ = أزال المرسل تفاعله. حدث مقصود يجب ألّا يُسقَط. */
    public function test_removing_a_reaction_is_preserved_as_an_empty_emoji(): void
    {
        $payload = $this->realReactionPayload();
        $payload['reaction']['emoji'] = '';

        $out = ChatMetadataHelper::minimalPayloadForStorage($payload);

        $this->assertArrayHasKey('emoji', $out['reaction']);
        $this->assertSame('', $out['reaction']['emoji']);
    }

    public function test_reaction_without_a_reaction_block_does_not_explode(): void
    {
        $out = ChatMetadataHelper::minimalPayloadForStorage(['type' => 'reaction']);

        $this->assertSame(['type' => 'reaction', 'reaction' => []], $out);
    }

    // -------------------------------------------------------------- system

    public function test_system_keeps_the_body_and_the_new_number(): void
    {
        $out = ChatMetadataHelper::minimalPayloadForStorage([
            'from' => '966596771718',
            'id' => 'wamid.HBgMOTY2NTk2NzcxNzE4FQIAEhgJNTMzNjQ5OTc4AA==',
            'timestamp' => '1763646618',
            'type' => 'system',
            'system' => [
                'body' => 'User A changed from 966596771718 to 201273635016',
                'wa_id' => '201273635016',
                'type' => 'user_changed_number',
            ],
        ]);

        $this->assertSame([
            'type' => 'system',
            'system' => [
                'body' => 'User A changed from 966596771718 to 201273635016',
                'wa_id' => '201273635016',
                'type' => 'user_changed_number',
            ],
        ], $out);
    }

    // ---------------------------------------------------------------- edit

    /** @return array<string, mixed> */
    private function realEditPayload(): array
    {
        return [
            'from' => '966560294085',
            'to' => '966543553452',
            'id' => 'wamid.HBgMOTY2NTQzNTUzNDUyFQIAERgUMkE2RkE5NkQ1OUYwOEJDNjQyODcA',
            'to_user_id' => 'SA.1970102981059672',
            'timestamp' => '1784959284',
            'type' => 'edit',
            'edit' => [
                'original_message_id' => 'wamid.HBgMOTY2NTQzNTUzNDUyFQIAERgUMkFFMzgwNDE1MDA0MzBFODU2ODIA',
                'message' => ['type' => 'text', 'text' => ['body' => 'اهو']],
            ],
        ];
    }

    /** النصّ بعد التعديل هو المحتوى الوحيد المعروض، فيُحفظ كاملاً. */
    public function test_edit_keeps_the_new_message_body(): void
    {
        $out = ChatMetadataHelper::minimalPayloadForStorage($this->realEditPayload());

        $this->assertSame('اهو', $out['edit']['message']['text']['body']);
        $this->assertSame(
            'wamid.HBgMOTY2NTQzNTUzNDUyFQIAERgUMkFFMzgwNDE1MDA0MzBFODU2ODIA',
            $out['edit']['original_message_id']
        );
        $this->assertArrayNotHasKey('to_user_id', $out);
        $this->assertArrayNotHasKey('from', $out);
    }

    public function test_edit_without_a_message_block_keeps_what_exists(): void
    {
        $out = ChatMetadataHelper::minimalPayloadForStorage([
            'type' => 'edit',
            'edit' => ['original_message_id' => 'wamid.X'],
        ]);

        $this->assertSame(['type' => 'edit', 'edit' => ['original_message_id' => 'wamid.X']], $out);
    }

    // -------------------------------------------------------------- revoke

    public function test_revoke_keeps_only_the_original_message_id(): void
    {
        $out = ChatMetadataHelper::minimalPayloadForStorage([
            'from' => '966531291799',
            'from_user_id' => 'SA.1027825096659190',
            'id' => 'wamid.HBgMOTY2NTMxMjkxNzk5FQIAEhgUM0FBQThEMUQ5QzM1M0VFODM2N0YA',
            'timestamp' => '1785144274',
            'type' => 'revoke',
            'revoke' => [
                'original_message_id' => 'wamid.HBgMOTY2NTMxMjkxNzk5FQIAEhgUM0FDNTM1MjI0NEFEMjc2NzIzRkQA',
            ],
        ]);

        $this->assertSame([
            'type' => 'revoke',
            'revoke' => [
                'original_message_id' => 'wamid.HBgMOTY2NTMxMjkxNzk5FQIAEhgUM0FDNTM1MjI0NEFEMjc2NzIzRkQA',
            ],
        ], $out);
    }

    // -------------------------------------------- ألّا ينكسر ما كان يعمل

    /**
     * كود إنتاجي: الأنواع التي كانت تُقلَّص قبل هذا التغيير يجب أن تخرج
     * كما كانت حرفاً بحرف.
     *
     * @dataProvider untouchedTypes
     */
    public function test_previously_handled_types_are_unchanged(array $payload, array $expected): void
    {
        $this->assertSame($expected, ChatMetadataHelper::minimalPayloadForStorage($payload));
    }

    public static function untouchedTypes(): array
    {
        return [
            'نصّ' => [
                ['from' => '9665', 'id' => 'wamid.A', 'timestamp' => '1', 'type' => 'text', 'text' => ['body' => 'مرحباً']],
                ['type' => 'text', 'text' => ['body' => 'مرحباً']],
            ],
            'موقع' => [
                [
                    'from' => '9665', 'id' => 'wamid.B', 'timestamp' => '1', 'type' => 'location',
                    'location' => ['latitude' => 21.48, 'longitude' => 39.19, 'name' => 'Ladyes', 'address' => 'جدة'],
                ],
                [
                    'type' => 'location',
                    'location' => ['latitude' => 21.48, 'longitude' => 39.19, 'name' => 'Ladyes', 'address' => 'جدة'],
                ],
            ],
            'زرّ' => [
                ['from' => '9665', 'id' => 'wamid.C', 'timestamp' => '1', 'type' => 'button', 'button' => ['text' => 'نعم', 'payload' => 'YES']],
                ['type' => 'button', 'button' => ['text' => 'نعم', 'payload' => 'YES']],
            ],
        ];
    }

    /**
     * نوع لم تعرفه Meta بعد يجب أن يبقى خاماً لا أن يُفقَد: الفرع الافتراضي
     * هو شبكة الأمان الوحيدة لما لم يُكتب له فرع.
     */
    public function test_a_genuinely_unknown_type_still_falls_through_untouched(): void
    {
        $payload = ['type' => 'order', 'order' => ['catalog_id' => '123'], 'from' => '9665'];

        $this->assertSame($payload, ChatMetadataHelper::minimalPayloadForStorage($payload));
    }

    public function test_payload_without_a_type_is_returned_untouched(): void
    {
        $payload = ['from' => '9665', 'id' => 'wamid.Z'];

        $this->assertSame($payload, ChatMetadataHelper::minimalPayloadForStorage($payload));
    }

    /** النسخ المفرّغة تبقى قابلة للترميز JSON — تُحفظ في عمود نصّي. */
    public function test_every_new_type_survives_a_json_round_trip(): void
    {
        foreach ([$this->realReactionPayload(), $this->realEditPayload()] as $payload) {
            $out = ChatMetadataHelper::minimalPayloadForStorage($payload);
            $encoded = json_encode($out, JSON_UNESCAPED_UNICODE);

            $this->assertIsString($encoded);
            $this->assertSame($out, json_decode($encoded, true));
        }
    }
}
