<?php

namespace App\Services\Messaging\Support;

use App\Services\Messaging\Channel;

/**
 * تنميق معرّفات الرسائل قبل تخزينها في chats.wam_id.
 *
 * لماذا؟ الفهرس الفريد على wam_id_active_key فريد **عالمياً** لا لكل منشأة.
 * ومعرّف تيليجرام (message_id) رقم تسلسلي لكل محادثة يبدأ من ١، فرسالتان
 * من محادثتين مختلفتين تحملان الرقم نفسه وتتصادمان. ومعرّفات ميتا وتيك توك
 * فريدة عالمياً لكنّها قد تتشابه مع معرّف واتساب نظرياً.
 *
 * الحلّ: بادئة قناة + مساحة اسم خاصّة بالحساب. معرّفات واتساب تبقى خاماً
 * كما هي — لا نلمس ٣.٢ مليون صفّ قائم ولا نكسر أي بحث عليها.
 *
 * العمود varchar(128) — نحرس الطول لأن التجاوز يقطع المعرّف صامتاً فتفشل
 * مطابقة حالة التسليم لاحقاً بلا أثر.
 */
final class MessageId
{
    public const MAX_LENGTH = 128;

    private const PREFIXES = [
        Channel::TELEGRAM => 'tg',
        Channel::MESSENGER => 'fb',
        Channel::INSTAGRAM => 'ig',
        Channel::TIKTOK => 'tt',
    ];

    /**
     * معرّف مخزَّن من معرّف المنصّة.
     *
     * @param  string  $channel     القناة
     * @param  string  $accountId   معرّف الحساب لدى المنصّة (يفصل البوت الواحد بين منشأتين)
     * @param  string  $providerId  المعرّف كما أعطته المنصّة
     * @param  ?string $scope       نطاق إضافي حين لا يكون المعرّف فريداً داخل الحساب (chat_id لتيليجرام)
     */
    public static function namespaced(string $channel, string $accountId, string $providerId, ?string $scope = null): string
    {
        if ($channel === Channel::WHATSAPP) {
            return $providerId;
        }

        $prefix = self::PREFIXES[$channel] ?? substr($channel, 0, 2);

        $parts = array_filter([$prefix, $accountId, $scope, $providerId], static fn ($p) => $p !== null && $p !== '');
        $id = implode(':', $parts);

        if (strlen($id) <= self::MAX_LENGTH) {
            return $id;
        }

        // التجاوز نادر (معرّفات ميتا طويلة مع حساب طويل). نُبقي البادئة
        // مقروءة ونستبدل الباقي ببصمة قصيرة تبقى فريدة — أفضل من قطعٍ
        // يُنتج معرّفين متطابقين لرسالتين مختلفتين.
        $digest = substr(hash('sha256', $id), 0, 40);

        return $prefix . ':' . $accountId . ':h_' . $digest;
    }

    /** هل هذا المعرّف يخصّ قناة غير واتساب؟ */
    public static function isNamespaced(string $id): bool
    {
        foreach (self::PREFIXES as $prefix) {
            if (str_starts_with($id, $prefix . ':')) {
                return true;
            }
        }

        return false;
    }

    /** القناة المستنتَجة من معرّف مخزَّن، أو واتساب إن لم يكن مُنَمَّقاً. */
    public static function channelOf(string $id): string
    {
        foreach (self::PREFIXES as $channel => $prefix) {
            if (str_starts_with($id, $prefix . ':')) {
                return $channel;
            }
        }

        return Channel::WHATSAPP;
    }
}
