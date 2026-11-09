<?php

namespace App\Services\Messaging;

/**
 * رسالة واردة بعد تطبيعها — الشكل الوحيد الذي يراه ما بعد السائق.
 *
 * النقطة الجوهرية: $metadata يجب أن يكون بنفس شكل ChatMetadataHelper
 * الخاص بواتساب بالضبط ({"type":"text","text":{"body":"…"}} وأخواته)، لأن
 * فقاعة المحادثة في الويب وتطبيق الجوال و AutoReplyService و
 * ChatNotificationContentBuilder كلّها تفرّع على هذا الشكل. أي انحراف فيه
 * يعني فقاعةً فارغة أو إشعاراً بلا نصّ، ولا يكشفه اختبار وحدة.
 *
 * السائق مسؤول عن التحويل إلى هذا الشكل، فيبقى باقي النظام كما هو.
 */
class InboundMessage
{
    public function __construct(
        /** معرّف الرسالة لدى المنصّة، مُنَمَّق (namespaced) ليكون فريداً عالمياً. */
        public readonly string $providerMessageId,

        /** معرّف المُرسِل لدى المنصّة: chat_id أو PSID أو IGSID أو conversation_id. */
        public readonly string $senderId,

        /** metadata بشكل واتساب — العقد مع كل ما يعرض الرسالة. */
        public readonly array $metadata,

        /** وقت الرسالة كما أعطته المنصّة (UTC)، أو null فنستعمل now(). */
        public readonly ?\DateTimeInterface $sentAt = null,

        /** الاسم الظاهر للمُرسِل إن أعطته المنصّة في نفس الحمولة. */
        public readonly ?string $senderName = null,

        /** اسم المستخدم (@handle) إن وُجد. */
        public readonly ?string $senderUsername = null,

        /** صورة المُرسِل إن أعطتها المنصّة مباشرة. */
        public readonly ?string $senderAvatar = null,

        /** ملف مرفق يحتاج تنزيلاً، أو null. */
        public readonly ?InboundMedia $media = null,

        /** رسالة صادرة منّا وصلت صداها من المنصّة (echo). */
        public readonly bool $isEcho = false,

        /** معرّف الرسالة التي يردّ عليها العميل. */
        public readonly ?string $replyToProviderId = null,

        /** الحمولة الخام — تُمرَّر إلى webhook العميل كما كانت. */
        public readonly array $raw = [],
    ) {
    }

    public function hasMedia(): bool
    {
        return $this->media !== null;
    }

    public function type(): string
    {
        return $this->metadata['type'] ?? 'unsupported';
    }
}
