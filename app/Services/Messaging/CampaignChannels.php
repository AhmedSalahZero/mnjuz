<?php

namespace App\Services\Messaging;

use App\Models\ChannelAccount;

/**
 * قنوات الحملات كما يختار منها التاجر: واتساب (الافتراضية)، تيليجرام،
 * البريد، وSMS (قريباً). اختيار واحد لكل حملة — لكل قناة شكل رسالتها.
 *
 * منفصلة عن Channel::all(): البريد وSMS قنوات إرسال فقط، بلا محادثة ولا
 * webhook ولا driver، فلا تدخل صندوق الوارد ولا الأتمتة ولا صفحة الربط
 * العامّة. وماسنجر وإنستغرام وتيك توك تبقى قنوات محادثة، لكن الحملات
 * الجديدة لا تُنشأ عليها (القائم منها يكمل إرساله كما هو).
 */
final class CampaignChannels
{
    /** ما يعرضه منتقي القناة، بالترتيب. */
    public const PICKER = [Channel::WHATSAPP, Channel::TELEGRAM, Channel::EMAIL, Channel::SMS];

    /** يظهر في المنتقي معطّلاً بشارة «قريباً». */
    public const COMING_SOON = [Channel::SMS];

    /** حدود نصّ تيليجرام: الرسالة 4096، والتعليق تحت مرفق 1024 (لا يُقسَّم). */
    public const TELEGRAM_TEXT_MAX = 4096;
    public const TELEGRAM_CAPTION_MAX = 1024;

    public const EMAIL_SUBJECT_MAX = 200;
    public const EMAIL_TEXT_MAX = 10000;

    /** الحدّ القديم لما عدا ذلك (الحملات القائمة على ماسنجر وغيرها). */
    public const LEGACY_TEXT_MAX = 4000;

    /**
     * امتدادات لا تُقبل مرفقاً: /media يعرض الملف بنوعه المكتشَف، وصفحة
     * HTML أو SVG فيه تُنفَّذ على نطاق التطبيق. قائمة منع لا قائمة سماح،
     * فلا يُرفض شيء كان يُقبل من قبل إلا هذه.
     */
    public const BLOCKED_ATTACHMENT_EXTENSIONS = ['html', 'htm', 'xhtml', 'svg', 'svgz', 'xml', 'js', 'mjs', 'php', 'phtml'];

    /**
     * حدود إرسال تيليجرام بالرابط (sendPhoto/sendDocument بـ URL): الصورة
     * 5MB والباقي 20MB — أصغر ممّا يقبله الرفع المباشر.
     */
    /**
     * ما يقبله sendPhoto في تيليجرام بالرابط. صورة بغيرها (AVIF وHEIC
     * وWebP…) تُحوَّل JPG عند رفعها — كانت تُقبل ثم يرفضها تيليجرام عند
     * الإرسال بـ «failed to get HTTP URL content».
     */
    public const TELEGRAM_PHOTO_MIME_TYPES = ['image/jpeg', 'image/png'];

    private const TELEGRAM_URL_LIMITS = [
        'image' => 5 * 1024 * 1024,
        'video' => 20 * 1024 * 1024,
        'audio' => 20 * 1024 * 1024,
        'document' => 20 * 1024 * 1024,
    ];

    /** القنوات التي تُنشأ عليها حملة جديدة الآن. */
    public static function creatable(): array
    {
        return [Channel::WHATSAPP, Channel::TELEGRAM, Channel::EMAIL];
    }

    /**
     * تطبيع القناة الواردة في طلب. يقبل قنوات المحادثة والبريد وSMS؛ القيمة
     * المجهولة null — فتصل قيمة مثل messenger إلى قاعدة التحقّق فتُرفض
     * برسالتها بدل أن تُعامل كواتساب.
     */
    public static function normalize(?string $channel): ?string
    {
        if ($channel === null) {
            return null;
        }

        $channel = strtolower(trim($channel));

        return in_array($channel, [...Channel::all(), Channel::EMAIL, Channel::SMS], true) ? $channel : null;
    }

    public static function isOutboundOnly(string $channel): bool
    {
        return in_array($channel, [Channel::EMAIL, Channel::SMS], true);
    }

    /** هل للمنشأة حساب فعّال على القناة؟ */
    public static function isConnected(int $organizationId, string $channel): bool
    {
        $manager = app(ChannelManager::class);

        return match ($channel) {
            Channel::WHATSAPP, Channel::TELEGRAM => $manager->activeAccount($organizationId, $channel) !== null,
            Channel::EMAIL => self::emailAccount($organizationId) !== null,
            default => false,
        };
    }

    /** حساب البريد الفعّال (SMTP التاجر). */
    public static function emailAccount(int $organizationId): ?ChannelAccount
    {
        return ChannelAccount::query()
            ->where('organization_id', $organizationId)
            ->where('channel', Channel::EMAIL)
            ->active()
            ->first();
    }

    /** أقصى طول لنصّ الحملة على القناة. */
    public static function textLimit(string $channel, bool $withMedia = false): int
    {
        return match ($channel) {
            Channel::TELEGRAM => $withMedia ? self::TELEGRAM_CAPTION_MAX : self::TELEGRAM_TEXT_MAX,
            Channel::EMAIL => self::EMAIL_TEXT_MAX,
            default => self::LEGACY_TEXT_MAX,
        };
    }

    /**
     * حدّ حجم كل نوع مرفق على القناة، أو [] حين لا مرفقات (البريد، SMS).
     *
     * @return array<string, int>
     */
    public static function mediaLimits(string $channel): array
    {
        if (self::isOutboundOnly($channel)) {
            return [];
        }

        $capabilities = app(ChannelManager::class)->capabilities($channel);
        $limits = [];

        foreach (['image', 'video', 'audio', 'document'] as $type) {
            if (!$capabilities->supportsMedia($type)) {
                continue;
            }

            $limit = $capabilities->mediaLimit($type);

            // تيليجرام يجلب الملف من رابطنا: حدّ الرابط هو الحدّ الفعلي.
            if ($channel === Channel::TELEGRAM) {
                $limit = min($limit ?? PHP_INT_MAX, self::TELEGRAM_URL_LIMITS[$type]);
            }

            $limits[$type] = $limit;
        }

        return $limits;
    }

    /**
     * ما تقبله رسالة القناة — تقرؤه الواجهة لتُشكّل النموذج، والخادم يتحقّق
     * بالقيم نفسها.
     *
     * @return array<string, mixed>
     */
    public static function rules(string $channel): array
    {
        return match ($channel) {
            Channel::WHATSAPP => ['kind' => 'template'],
            Channel::TELEGRAM => [
                'kind' => 'message',
                'text_max' => self::TELEGRAM_TEXT_MAX,
                'caption_max' => self::TELEGRAM_CAPTION_MAX,
                'media_limits' => self::mediaLimits(Channel::TELEGRAM),
                'blocked_extensions' => self::BLOCKED_ATTACHMENT_EXTENSIONS,
            ],
            Channel::EMAIL => [
                'kind' => 'email',
                'text_max' => self::EMAIL_TEXT_MAX,
                'subject_max' => self::EMAIL_SUBJECT_MAX,
                'media_limits' => [],
                'unsubscribe_footer' => true,
            ],
            default => ['kind' => 'unavailable'],
        };
    }

    /**
     * خيارات المنتقي: القنوات الأربع دائماً، والمربوطة منها قابلة للاختيار.
     *
     * @return array<int, array{value: string, label: string, connected: bool, coming_soon: bool, connect_url: ?string, rules: array}>
     */
    public static function options(int $organizationId, bool $canManage): array
    {
        return array_map(function (string $channel) use ($organizationId, $canManage) {
            $comingSoon = in_array($channel, self::COMING_SOON, true);

            return [
                'value' => $channel,
                'label' => __(Channel::label($channel)),
                'connected' => !$comingSoon && self::isConnected($organizationId, $channel),
                'coming_soon' => $comingSoon,
                // الموظّف لا يدير القنوات: لا رابط ربط له.
                'connect_url' => $comingSoon || !$canManage ? null : self::connectUrl($channel),
                'rules' => self::rules($channel),
            ];
        }, self::PICKER);
    }

    private static function connectUrl(string $channel): ?string
    {
        return match ($channel) {
            Channel::WHATSAPP => '/settings/whatsapp',
            Channel::TELEGRAM => '/settings/channels/telegram',
            Channel::EMAIL => '/settings/channels/email',
            default => null,
        };
    }
}
