<?php

namespace App\Services\Messaging;

/**
 * أسماء القنوات كما تُخزَّن في قاعدة البيانات.
 *
 * القيمة نصّ قصير ثابت لا يتغيّر أبداً بعد أول نشر: هي المخزَّنة في
 * contacts.channel و chats.channel و campaigns.channel لملايين الصفوف.
 *
 * WHATSAPP هي القيمة الافتراضية لكل صفّ قائم اليوم، فلا حاجة إلى تعبئة
 * رجعية (backfill) عند الترحيل.
 */
final class Channel
{
    public const WHATSAPP = 'whatsapp';
    public const TELEGRAM = 'telegram';
    public const MESSENGER = 'messenger';
    public const INSTAGRAM = 'instagram';
    public const TIKTOK = 'tiktok';

    /**
     * المحادثة المباشرة من موقع التاجر (widget). لا منصّة خارجية: الرسائل
     * تصل من واجهتنا العامّة لا من webhook، وتُرسَل إلى متصفّح الزائر.
     */
    public const WEBCHAT = 'webchat';

    /** القناة الافتراضية لكل ما هو مخزَّن قبل إضافة تعدّد القنوات. */
    public const DEFAULT = self::WHATSAPP;

    /**
     * كل القنوات المدعومة، بالترتيب الذي تُعرض به في الواجهة.
     *
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [
            self::WHATSAPP,
            self::TELEGRAM,
            self::MESSENGER,
            self::INSTAGRAM,
            self::TIKTOK,
            self::WEBCHAT,
        ];
    }

    /** القنوات ما عدا واتساب — أي ما يمرّ على طبقة القنوات الجديدة وحدها. */
    public static function others(): array
    {
        return array_values(array_diff(self::all(), [self::WHATSAPP]));
    }

    /**
     * القنوات التي تُربط بحساب على منصّة خارجية من صفحة القنوات وتستقبل
     * webhook. المحادثة المباشرة ليست منها: لها صفحة إعداداتها وواجهتها.
     */
    public static function connectable(): array
    {
        return array_values(array_diff(self::others(), [self::WEBCHAT]));
    }

    public static function isValid(?string $channel): bool
    {
        return $channel !== null && in_array($channel, self::all(), true);
    }

    /**
     * تطبيع أي قيمة قادمة من طلب خارجي إلى قناة معروفة.
     * القيمة المجهولة تُردّ إلى null لا إلى الافتراضي، لأن الصمت هنا يُخفي
     * خطأً في الطلب بدل أن يكشفه.
     */
    public static function normalize(?string $channel): ?string
    {
        if ($channel === null) {
            return null;
        }

        $channel = strtolower(trim($channel));

        return self::isValid($channel) ? $channel : null;
    }

    /** الاسم المعروض في الواجهة — يُترجَم عبر __() عند العرض. */
    public static function label(string $channel): string
    {
        return match ($channel) {
            self::WHATSAPP => 'WhatsApp',
            self::TELEGRAM => 'Telegram',
            self::MESSENGER => 'Messenger',
            self::INSTAGRAM => 'Instagram',
            self::TIKTOK => 'TikTok',
            self::WEBCHAT => 'Web Chat',
            default => ucfirst($channel),
        };
    }

    /**
     * منصّات ميتا تشترك في نفس آلية التحقّق من التوقيع ونفس شكل الحمولة
     * تقريباً، فبعض المسارات تتعامل معها كمجموعة واحدة.
     */
    public static function isMeta(string $channel): bool
    {
        return in_array($channel, [self::WHATSAPP, self::MESSENGER, self::INSTAGRAM], true);
    }
}
