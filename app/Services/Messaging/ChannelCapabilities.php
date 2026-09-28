<?php

namespace App\Services\Messaging;

/**
 * ما تستطيعه كل قناة وما لا تستطيعه.
 *
 * الغرض: ألّا يسأل أي كود «هل هذه واتساب؟» بل «هل تدعم هذه القناة القوالب؟».
 * السؤال الأول ينتشر في عشرات الملفات ويتعفّن مع كل منصّة جديدة، والثاني
 * يبقى في مكان واحد.
 *
 * كل الحدود بالبايت مأخوذة من وثائق المنصّات (راجع
 * docs/social-channels/PLATFORM_CONTRACTS.md).
 */
class ChannelCapabilities
{
    public function __construct(
        /** نافذة الردّ بالساعات — null يعني لا نافذة (Telegram). */
        public readonly ?int $windowHours = 24,

        /** قوالب معتمدة مسبقاً (واتساب وحدها). */
        public readonly bool $templates = false,

        /** أزرار تفاعلية / قوائم داخل الرسالة. */
        public readonly bool $interactive = false,

        /** إرسال موقع جغرافي. */
        public readonly bool $location = false,

        /** طلب موقع العميل. */
        public readonly bool $locationRequest = false,

        /** تعليق نصّي مرفق بالوسائط في نفس الرسالة. */
        public readonly bool $mediaCaption = true,

        /** نصّ وصورة في رسالة واحدة (TikTok لا تسمح). */
        public readonly bool $textWithMedia = true,

        /** أكثر من مرفق في رسالة واحدة. */
        public readonly bool $multipleAttachments = false,

        /** إشعارات التسليم والقراءة (Telegram لا تعطيها للبوتات). */
        public readonly bool $deliveryReceipts = true,
        public readonly bool $readReceipts = true,

        /** حظر جهة الاتصال على مستوى المنصّة. */
        public readonly bool $blockContact = false,

        /** التفاعل بالإيموجي على رسالة. */
        public readonly bool $reactions = false,

        /** الردّ على رسالة بعينها (reply/quote). */
        public readonly bool $replyTo = false,

        /**
         * أنواع الوسائط المسموحة وحدّ حجم كلٍّ منها بالبايت.
         *
         * @var array<string, int>
         */
        public readonly array $mediaLimits = [],

        /** أنواع MIME المسموحة للصور، فارغة تعني الكل. */
        public readonly array $imageMimeTypes = [],

        /** حملات جماعية مدعومة على هذه القناة. */
        public readonly bool $campaigns = true,
    ) {
    }

    /** هل تقبل القناة هذا النوع من الوسائط أصلاً؟ */
    public function supportsMedia(string $type): bool
    {
        return array_key_exists($type, $this->mediaLimits);
    }

    /** حدّ الحجم لنوع وسائط، أو null إن كان النوع غير مدعوم. */
    public function mediaLimit(string $type): ?int
    {
        return $this->mediaLimits[$type] ?? null;
    }

    /** هل النافذة الزمنية مفتوحة دائماً على هذه القناة؟ */
    public function hasUnlimitedWindow(): bool
    {
        return $this->windowHours === null;
    }

    /**
     * شكل مبسّط تُرسله الواجهة والموبايل ليعرفا ما يُظهران وما يُخفون،
     * فلا تُكرَّر قواعد كل منصّة في الويب وفي التطبيق.
     */
    public function toArray(): array
    {
        return [
            'window_hours' => $this->windowHours,
            'templates' => $this->templates,
            'interactive' => $this->interactive,
            'location' => $this->location,
            'location_request' => $this->locationRequest,
            'media_caption' => $this->mediaCaption,
            'text_with_media' => $this->textWithMedia,
            'multiple_attachments' => $this->multipleAttachments,
            'delivery_receipts' => $this->deliveryReceipts,
            'read_receipts' => $this->readReceipts,
            'block_contact' => $this->blockContact,
            'reactions' => $this->reactions,
            'reply_to' => $this->replyTo,
            'media_limits' => $this->mediaLimits,
            'campaigns' => $this->campaigns,
        ];
    }
}
