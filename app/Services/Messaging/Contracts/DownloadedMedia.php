<?php

namespace App\Services\Messaging\Contracts;

/**
 * ملف نُزِّل من المنصّة، جاهزاً للتخزين.
 *
 * المحتوى في الذاكرة لا على القرص: الملفات هنا محدودة الحجم (أكبرها 50MB
 * لتيليجرام) والمسار الحالي في ProcessMediaDownloadJob يفعل الشيء نفسه،
 * فنبقى على ما هو مُجرَّب.
 */
class DownloadedMedia
{
    public function __construct(
        public readonly string $contents,
        public readonly string $mimeType,
        public readonly ?string $fileName = null,
        public readonly ?int $size = null,
    ) {
    }

    public function size(): int
    {
        return $this->size ?? strlen($this->contents);
    }
}
