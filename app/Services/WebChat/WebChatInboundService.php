<?php

namespace App\Services\WebChat;

use App\Jobs\ProcessWebChatInboundJob;
use App\Models\ChannelAccount;
use App\Models\Chat;
use App\Models\WebChatVisitor;
use App\Services\Messaging\Channel;
use App\Services\Messaging\InboundMedia;
use App\Services\Messaging\InboundMessage;
use App\Services\Messaging\Support\MessageId;

/**
 * تحويل ما يرسله الزائر إلى InboundMessage بشكل واتساب، ثم تسليمه للطابور.
 *
 * لماذا الطابور لا الطلب نفسه؟ InboundMessageRecorder يُطلق webhook التاجر
 * متزامناً وبلا مهلة، ويُسند التذكرة ويُرسل إشعار الجوال. لا يُعقل أن ينتظر
 * زائر في موقع التاجر كل ذلك قبل أن تظهر رسالته — ولا أن يُسقطها موقعٌ
 * بطيء عند التاجر.
 */
class WebChatInboundService
{
    public function dispatchText(ChannelAccount $account, WebChatVisitor $visitor, string $clientMessageId, string $text): void
    {
        $this->dispatch($account, $visitor, $clientMessageId, [
            'type' => 'text',
            'text' => ['body' => $text],
        ]);
    }

    /**
     * ضغطة زرّ أو اختيار من قائمة أرسلها البوت.
     *
     * العنوان يُؤخذ من نسختنا المخزَّنة لا من الطلب: الـ flow يطابق الردّ
     * بعنوان الزرّ (choiceHandle)، فعنوانٌ يكتبه المتصفّح كما يشاء يقفز في
     * الـ flow إلى فرعٍ لم يُعرض على الزائر أصلاً.
     *
     * @return bool false إن لم يكن الخيار من رسالة عُرضت على هذا الزائر
     */
    public function dispatchInteractiveReply(
        ChannelAccount $account,
        WebChatVisitor $visitor,
        string $clientMessageId,
        string $messageUuid,
        string $optionId
    ): bool {
        $reply = $this->resolveReply($visitor, $messageUuid, $optionId);

        if ($reply === null) {
            return false;
        }

        $this->dispatch($account, $visitor, $clientMessageId, $reply);

        return true;
    }

    /** ملف رفعه الزائر — محفوظ مؤقّتاً، وتُكمله مهمّة تنزيل الوسائط. */
    public function dispatchMedia(
        ChannelAccount $account,
        WebChatVisitor $visitor,
        string $clientMessageId,
        array $upload,
        ?string $caption
    ): void {
        $type = $upload['type'];

        $this->dispatch($account, $visitor, $clientMessageId, [
            'type' => $type,
            $type => array_filter([
                'caption' => $caption,
                'filename' => $type === 'document' ? $upload['file_name'] : null,
                'mime_type' => $upload['mime_type'],
            ], static fn ($v) => $v !== null && $v !== ''),
        ], [
            'type' => $type,
            'mime_type' => $upload['mime_type'],
            'file_name' => $upload['file_name'],
            'size' => $upload['size'],
            'caption' => $caption,
            'temp_path' => $upload['temp_path'],
        ]);
    }

    /** معرّف الرسالة الواردة: يشتقّ من معرّف العميل فتكون إعادة الإرسال نفس الرسالة. */
    public static function providerMessageId(ChannelAccount $account, WebChatVisitor $visitor, string $clientMessageId): string
    {
        return MessageId::namespaced(Channel::WEBCHAT, (string) $account->external_id, 'c' . strtolower($clientMessageId), $visitor->uuid);
    }

    /**
     * InboundMessage من مصفوفة الطابور. في مكان واحد ليبقى الشكل الذي يكتبه
     * الطلب هو نفسه الذي تقرؤه المهمّة.
     */
    public static function messageFromPayload(array $payload): InboundMessage
    {
        $media = null;

        if (!empty($payload['media'])) {
            $m = $payload['media'];
            $media = new InboundMedia(
                type: $m['type'],
                mimeType: $m['mime_type'] ?? null,
                fileName: $m['file_name'] ?? null,
                size: isset($m['size']) ? (int) $m['size'] : null,
                caption: $m['caption'] ?? null,
                extra: ['temp_path' => $m['temp_path'] ?? null],
            );
        }

        return new InboundMessage(
            providerMessageId: $payload['provider_message_id'],
            senderId: $payload['sender_id'],
            metadata: $payload['metadata'],
            senderName: $payload['sender_name'] ?? null,
            media: $media,
            raw: $payload['raw'] ?? [],
            senderPhone: $payload['sender_phone'] ?? null,
            senderEmail: $payload['sender_email'] ?? null,
        );
    }

    private function dispatch(ChannelAccount $account, WebChatVisitor $visitor, string $clientMessageId, array $metadata, ?array $media = null): void
    {
        $payload = [
            'provider_message_id' => self::providerMessageId($account, $visitor, $clientMessageId),
            'sender_id' => $visitor->uuid,
            'sender_name' => $visitor->name,
            'sender_phone' => $visitor->phone,
            'sender_email' => $visitor->email,
            'metadata' => $metadata,
            'media' => $media,
            // ما يصل webhook التاجر: هويّة الزائر كما أعطاها والرسالة والصفحة.
            // لا توكن ولا IP ولا متصفّح.
            'raw' => [
                'channel' => Channel::WEBCHAT,
                'visitor' => array_filter([
                    'id' => $visitor->uuid,
                    'name' => $visitor->name,
                    'phone' => $visitor->phone,
                    'email' => $visitor->email,
                ], static fn ($v) => $v !== null && $v !== ''),
                'message' => $metadata,
                'page_url' => $visitor->last_page_url,
            ],
        ];

        ProcessWebChatInboundJob::dispatch((int) $account->id, (int) $visitor->id, $payload)->onQueue('high');
    }

    private function resolveReply(WebChatVisitor $visitor, string $messageUuid, string $optionId): ?array
    {
        if (!$visitor->contact_id) {
            return null;
        }

        $chat = Chat::query()
            ->where('uuid', $messageUuid)
            ->where('contact_id', $visitor->contact_id)
            ->where('type', 'outbound')
            ->whereNull('deleted_at')
            ->first();

        $options = $chat ? (json_decode((string) $chat->metadata, true)['interactive_options'] ?? null) : null;

        if (!is_array($options)) {
            return null;
        }

        if (($options['kind'] ?? null) === 'buttons') {
            foreach ((array) ($options['buttons'] ?? []) as $button) {
                if ((string) ($button['id'] ?? '') === $optionId) {
                    return [
                        'type' => 'interactive',
                        'interactive' => [
                            'type' => 'button_reply',
                            'button_reply' => ['id' => $optionId, 'title' => (string) $button['title']],
                        ],
                    ];
                }
            }
        }

        if (($options['kind'] ?? null) === 'list') {
            foreach ((array) ($options['sections'] ?? []) as $section) {
                foreach ((array) ($section['rows'] ?? []) as $row) {
                    if ((string) ($row['id'] ?? '') === $optionId) {
                        return [
                            'type' => 'interactive',
                            'interactive' => [
                                'type' => 'list_reply',
                                'list_reply' => array_filter([
                                    'id' => $optionId,
                                    'title' => (string) $row['title'],
                                    'description' => $row['description'] ?? null,
                                ], static fn ($v) => $v !== null && $v !== ''),
                            ],
                        ];
                    }
                }
            }
        }

        return null;
    }
}
