<?php

namespace App\Services\ChatImport;

/**
 * بصمة رسالة مستوردة — تُخزَّن في chats.wam_id فيمنع فهرسه الفريد تكرارها.
 *
 * البصمة من محتوى الرسالة لا من رقم صفّها: من يرفع الملف نفسه مرّتين، أو
 * تصديراً أحدث يشمل ما رُفع قبله، لا يجد رسائله مكرّرة.
 *
 * ولكنّ المحتوى وحده يُسقط رسائل حقيقية: ثلاث صور «[image]» في ثانية واحدة
 * محتواها واحد. لذلك يدخل فيها ترتيب الرسالة بين نظيراتها المتتالية لنفس
 * الرقم في نفس الثانية (occurrence) — والقارئ لا يقطع هذه السلسلة بين
 * دفعتين، فيخرج الترتيب نفسه عند الاستئناف.
 *
 * والمنشأة جزءٌ منها: الفهرس فريد على مستوى النظام كلّه، وملفٌ واحد يُرفع
 * لمنشأتين لا يجوز أن تمنع إحداهما الأخرى.
 */
final class MessageFingerprint
{
    public const PREFIX = 'imp:';

    public static function make(int $organizationId, ParsedRow $row, int $occurrence): string
    {
        return self::PREFIX . sha1(implode("\x1f", [
            $organizationId,
            $row->phone,
            $row->sentAtUtc,
            $row->direction,
            sha1($row->text),
            $occurrence,
        ]));
    }

    /** مفتاح ثنائي مضغوط لمجموعات الذاكرة (20 بايتاً بدل 44). */
    public static function binary(string $fingerprint): string
    {
        return (string) hex2bin(substr($fingerprint, strlen(self::PREFIX)));
    }
}
