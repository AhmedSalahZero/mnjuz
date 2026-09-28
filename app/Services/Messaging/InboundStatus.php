<?php

namespace App\Services\Messaging;

/**
 * تحديث حالة رسالة صادرة (تسليم / قراءة / فشل).
 *
 * منصّات ميتا تعطي «علامة مائية» (watermark): كل ما قبل هذا الوقت صار
 * مقروءاً — لا معرّف رسالة بعينها. لذلك نسمح بأحدهما: إمّا معرّفات صريحة،
 * وإمّا وقت يُطبَّق على ما قبله.
 */
class InboundStatus
{
    public function __construct(
        /** الحالة الجديدة: sent|delivered|read|failed */
        public readonly string $status,

        /**
         * معرّفات الرسائل المعنيّة (مُنَمَّقة)، أو فارغة مع watermark.
         *
         * @var array<int, string>
         */
        public readonly array $providerMessageIds = [],

        /** كل ما أُرسل قبل هذا الوقت يأخذ الحالة. */
        public readonly ?\DateTimeInterface $watermark = null,

        /** معرّف المحادثة — لازم مع watermark لتحديد جهة الاتصال. */
        public readonly ?string $senderId = null,

        public readonly ?string $errorMessage = null,
        public readonly ?string $errorCode = null,

        public readonly array $raw = [],
    ) {
    }

    public function isWatermark(): bool
    {
        return $this->providerMessageIds === [] && $this->watermark !== null;
    }
}
