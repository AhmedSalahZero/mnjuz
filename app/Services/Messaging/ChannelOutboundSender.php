<?php

namespace App\Services\Messaging;

use App\Models\ChannelAccount;
use App\Models\Chat;
use App\Models\Contact;
use App\Services\Messaging\Contracts\ChannelDriver;
use App\Services\Messaging\Contracts\SupportsInteractive;
use Illuminate\Support\Facades\Log;

/**
 * إرسال رسالة على قناة غير واتساب وتسجيلها — ما تشترك فيه الحملات والردّ الآلي.
 *
 * يُرجع الشكل القديم لردّ WhatsappService ({success, message, data->chat,
 * data->error}) لأن مُستدعيه يقرؤونه: الحملات تكتبه في campaign_logs،
 * والـ flow يقرأ data->chat->id لسجلّه.
 *
 * لا يرمي أبداً: مُستدعوه (مهمّة الحملة، الـ flow) لا يلتقطون، والرمي
 * يُعيد المهمّة كلّها فتتكرّر رسائل وصلت.
 */
class ChannelOutboundSender
{
    public function __construct(private readonly OutboundMessageRecorder $recorder)
    {
    }

    /**
     * نصّ أو وسائط (مع نصّها).
     *
     * @param  array|null  $media   ['type','url','name','mime_type','size','location']
     * @param  array  $record       ['user_id','channel','metadata' => إضافات لصفّ المحادثة,'log' => سياق السجلّ]
     */
    public function deliver(ChannelDriver $driver, ChannelAccount $account, Contact $contact, string $text, ?array $media, array $record = []): object
    {
        try {
            [$result, $metadata, $mediaRow, $followUp] = $this->dispatch($driver, $account, $contact, $text, $media);
        } catch (\Throwable $e) {
            Log::error('Channel send threw', ($record['log'] ?? []) + [
                'contact_id' => $contact->id,
                'channel' => $driver->channel(),
                'error' => $e->getMessage(),
            ]);

            return self::failure($e->getMessage());
        }

        if (!$result->success) {
            return self::failure($result->errorMessage ?? __('Message could not be sent.'), $result->errorCode);
        }

        $metadata = array_replace($metadata, (array) ($record['metadata'] ?? []));
        $options = $this->recordOptions($contact, $record, $result->status);

        $chat = $mediaRow
            ? $this->recorder->recordMedia($contact, $result->providerMessageId, $metadata, $mediaRow, $options)
            : $this->recorder->recordText($contact, $result->providerMessageId, $metadata, $options);

        // النصّ الذي أُرسل بعد الصورة (قناة لا تجمعهما) وصل العميل، فيُسجَّل
        // كذلك — وإلا رآه العميل ولم يره الموظّف.
        if ($followUp !== null && $followUp->success) {
            $this->recorder->recordText(
                $contact,
                $followUp->providerMessageId,
                ['type' => 'text', 'text' => ['body' => $text]],
                $this->recordOptions($contact, $record, $followUp->status)
            );
        }

        return self::success($result, $chat);
    }

    /**
     * أزرار أو قائمة أو زرّ رابط. القناة التي لا تعرض الأزرار يصلها الخيار
     * نصّاً، والعميل يكتب عنوانه.
     */
    public function deliverInteractive(ChannelDriver $driver, ChannelAccount $account, Contact $contact, OutboundInteractive $message, array $record = []): object
    {
        if (!$driver instanceof SupportsInteractive || !$driver->capabilities()->interactive) {
            return $this->deliver($driver, $account, $contact, $message->fallbackText(), null, $record);
        }

        try {
            $result = $driver->sendInteractive($account, $contact, $message);
        } catch (\Throwable $e) {
            Log::error('Channel interactive send threw', ($record['log'] ?? []) + [
                'contact_id' => $contact->id,
                'channel' => $driver->channel(),
                'error' => $e->getMessage(),
            ]);

            return self::failure($e->getMessage());
        }

        if (!$result->success) {
            return self::failure($result->errorMessage ?? __('Message could not be sent.'), $result->errorCode);
        }

        $chat = $this->recorder->recordText(
            $contact,
            $result->providerMessageId,
            array_replace($message->toMetadata(), (array) ($record['metadata'] ?? [])),
            $this->recordOptions($contact, $record, $result->status)
        );

        return self::success($result, $chat);
    }

    /** فشل بشكل ردّ WhatsappService. الكود نصّ دائماً: لا يُخلط بأكواد Meta التي تُعاد معها المحاولة. */
    public static function failure(string $message, ?string $code = null): object
    {
        return (object) [
            'success' => false,
            'message' => $message,
            'data' => (object) [
                'error' => (object) array_filter([
                    'message' => $message,
                    'code' => $code,
                ], static fn ($v) => $v !== null),
            ],
        ];
    }

    private static function success(SendResult $result, ?Chat $chat): object
    {
        $response = $result->toLegacyObject();
        $response->data->chat = $chat;

        return $response;
    }

    private function recordOptions(Contact $contact, array $record, string $status): array
    {
        return [
            'user_id' => $record['user_id'] ?? null,
            'status' => $status,
            'channel' => $record['channel'] ?? ($contact->channel ?? Channel::DEFAULT),
        ];
    }

    /**
     * اختيار الإرسال: وسائط أم نصّ.
     *
     * القناة التي لا تجمع نصّاً وصورة (تيك توك) تُرسل الصورة وحدها ثم النصّ
     * رسالةً تالية — والسائق هو من يعرف ذلك لا نحن.
     *
     * @return array{0: SendResult, 1: array, 2: ?array, 3: ?SendResult}
     */
    private function dispatch(ChannelDriver $driver, ChannelAccount $account, Contact $contact, string $text, ?array $media): array
    {
        if (!$media || empty($media['url'])) {
            return [
                $driver->sendText($account, $contact, $text),
                ['type' => 'text', 'text' => ['body' => $text]],
                null,
                null,
            ];
        }

        $type = $media['type'] ?? 'image';
        $capabilities = $driver->capabilities();

        $outbound = new OutboundMedia(
            type: $type,
            url: $media['url'],
            fileName: $media['name'] ?? null,
            mimeType: $media['mime_type'] ?? null,
            size: isset($media['size']) ? (int) $media['size'] : null,
            caption: $capabilities->textWithMedia ? $text : null,
        );

        $result = $driver->sendMedia($account, $contact, $outbound);

        // النصّ لم يُرسَل مع الصورة لأن القناة لا تسمح: نُرسله تالياً حتى
        // لا تصل الرسالة صورةً صامتة.
        $followUp = null;
        if ($result->success && !$capabilities->textWithMedia && trim($text) !== '') {
            $followUp = $driver->sendText($account, $contact, $text);
        }

        return [
            $result,
            [
                'type' => $type,
                $type => array_filter(
                    [
                        'caption' => $capabilities->mediaCaption ? $text : null,
                        'filename' => $media['name'] ?? null,
                        'mime_type' => $media['mime_type'] ?? null,
                    ],
                    static fn ($v) => $v !== null && $v !== ''
                ),
            ],
            [
                'name' => $media['name'] ?? 'N/A',
                'path' => $media['url'],
                'location' => $media['location'] ?? 'local',
                'type' => $media['mime_type'] ?? null,
                'size' => $media['size'] ?? 0,
            ],
            $followUp,
        ];
    }
}
