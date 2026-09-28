<?php

namespace App\Services\Messaging;

/**
 * ملف نريد إرساله. يصل السائق إمّا برابط عام (الأغلب) وإمّا بمسار محلّي.
 *
 * ميتا وتيليجرام يقبلان رابطاً عاماً فتنزّله المنصّة بنفسها، وتيك توك
 * تشترط رفع الملف أولاً والحصول على media_id. لذلك يحمل الكائن الاثنين
 * ويختار كل سائق ما يناسبه.
 */
class OutboundMedia
{
    public function __construct(
        /** image|video|audio|document|sticker */
        public readonly string $type,

        /** رابط عام يصل إليه مزوّد المنصّة. */
        public readonly ?string $url = null,

        /** مسار محلّي للرفع المباشر حين لا يكفي الرابط. */
        public readonly ?string $path = null,

        public readonly ?string $fileName = null,
        public readonly ?string $mimeType = null,
        public readonly ?int $size = null,
        public readonly ?string $caption = null,

        /** رسالة صوتية لا ملف صوت. */
        public readonly bool $isVoice = false,
    ) {
    }

    public function hasUrl(): bool
    {
        return $this->url !== null && $this->url !== '';
    }

    public function hasLocalPath(): bool
    {
        return $this->path !== null && $this->path !== '' && is_readable($this->path);
    }
}
