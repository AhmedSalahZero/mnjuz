<?php

namespace App\Services\ChatImport;

/**
 * صفّ سليم جاهز للكتابة.
 */
final class ParsedRow
{
    public function __construct(
        public readonly int $row,
        public readonly string $phone,
        public readonly ?string $name,
        /** بتوقيت UTC بصيغة Y-m-d H:i:s — كما تُخزَّن chats.created_at. */
        public readonly string $sentAtUtc,
        /** inbound | outbound */
        public readonly string $direction,
        public readonly string $text,
        public readonly ?string $originalType,
    ) {
    }

    /** مفتاح «الدفعة المتّصلة»: الصفوف المتتالية لنفس الرقم في نفس الثانية. */
    public function runKey(): string
    {
        return $this->phone . '|' . $this->sentAtUtc;
    }
}
