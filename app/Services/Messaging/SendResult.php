<?php

namespace App\Services\Messaging;

/**
 * نتيجة محاولة إرسال واحدة، موحّدة لكل القنوات.
 *
 * toLegacyObject() موجودة لسبب واحد: الكود الحالي (ApiController و
 * WebhookHelper و flows) يُمرّر كائن WhatsappService الخام إلى العملاء وإلى
 * تطبيق الجوال. تغيير شكله يكسر عقداً منشوراً، فنُبقيه كما هو حرفياً.
 */
class SendResult
{
    private function __construct(
        public readonly bool $success,
        public readonly ?string $providerMessageId = null,
        /** الحالة الأولية للرسالة: sent أو delivered حسب ما تُرجعه المنصّة. */
        public readonly string $status = 'sent',
        public readonly ?string $errorMessage = null,
        public readonly ?string $errorCode = null,
        /** الردّ الخام كما وصل من المنصّة — للسجلّ والتشخيص. */
        public readonly mixed $raw = null,
    ) {
    }

    public static function success(?string $providerMessageId, string $status = 'sent', mixed $raw = null): self
    {
        return new self(
            success: true,
            providerMessageId: $providerMessageId,
            status: $status,
            raw: $raw,
        );
    }

    public static function failure(string $message, ?string $code = null, mixed $raw = null): self
    {
        return new self(
            success: false,
            errorMessage: $message,
            errorCode: $code,
            raw: $raw,
        );
    }

    /**
     * الشكل القديم الذي يتوقّعه المُستدعون: stdClass فيه success و data و message.
     * data->messages[0]->id هو ما يقرأه الكود ليعرف معرّف الرسالة.
     */
    public function toLegacyObject(): object
    {
        if (!$this->success) {
            return (object) [
                'success' => false,
                'message' => $this->errorMessage,
                'data' => (object) [
                    'error' => (object) array_filter([
                        'message' => $this->errorMessage,
                        'code' => $this->errorCode,
                    ], static fn ($v) => $v !== null),
                ],
            ];
        }

        return (object) [
            'success' => true,
            'data' => (object) [
                'messages' => [
                    (object) ['id' => $this->providerMessageId],
                ],
            ],
        ];
    }
}
