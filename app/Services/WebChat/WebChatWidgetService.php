<?php

namespace App\Services\WebChat;

use App\Models\ChannelAccount;
use App\Services\Messaging\Channel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * إنشاء widget المحادثة المباشرة وإيجاده.
 *
 * الـ widget صفّ في channel_accounts بقناة webchat: external_id هو المفتاح
 * العامّ الذي يُلصق في موقع التاجر. عامّ لا سرّي — يراه كل زائر في مصدر
 * الصفحة — فلا يمنح إلا ما يمنحه الـ widget نفسه: فتح محادثة جديدة.
 */
class WebChatWidgetService
{
    /** «wc_» + ٢٤ حرفاً: نحو ١٤٣ بتاً من العشوائية، لا يُخمَّن. */
    public const KEY_PATTERN = '/^wc_[A-Za-z0-9]{24}$/';

    public static function newKey(): string
    {
        return 'wc_' . Str::random(24);
    }

    public static function isWellFormedKey(string $key): bool
    {
        return (bool) preg_match(self::KEY_PATTERN, $key);
    }

    /** widget المنشأة (واحد في هذه المرحلة)، أو null. */
    public function forOrganization(int $organizationId): ?ChannelAccount
    {
        return ChannelAccount::query()
            ->where('organization_id', $organizationId)
            ->where('channel', Channel::WEBCHAT)
            ->orderBy('id')
            ->first();
    }

    /** widget المنشأة، يُنشأ بالإعدادات الافتراضية إن لم يوجد. */
    public function ensureForOrganization(int $organizationId, ?string $name = null, ?int $userId = null): ChannelAccount
    {
        return $this->forOrganization($organizationId)
            ?? ChannelAccount::create([
                'organization_id' => $organizationId,
                'channel' => Channel::WEBCHAT,
                'external_id' => self::newKey(),
                'name' => $name !== null ? mb_substr($name, 0, 191) : null,
                'credentials' => [],
                'settings' => ['enabled' => true],
                // يبقى active دائماً: الإيقاف بـ settings.enabled، لأن الحالة
                // غير الفعّالة تمنع الموظّف من الردّ على محادثات قائمة.
                'status' => ChannelAccount::STATUS_ACTIVE,
                'created_by' => $userId,
            ]);
    }

    /** المفتاح المسرَّب يُستبدل: الصفحات القديمة تتوقّف، والمحادثات تبقى. */
    public function rotateKey(ChannelAccount $account): ChannelAccount
    {
        $account->forceFill(['external_id' => self::newKey()])->save();

        return $account;
    }

    /**
     * هل تقبل المنشأة رسائل واردة الآن؟ اشتراك ساري ولم تبلغ حدّ الرسائل.
     * نافذة قصيرة في الذاكرة المؤقّتة: هذا يُسأل مع كل رسالة وكل تحميل صفحة.
     */
    public static function acceptsInbound(int $organizationId): bool
    {
        return Cache::remember("webchat_accepts_inbound_{$organizationId}", 60, function () use ($organizationId) {
            return \App\Services\SubscriptionService::isSubscriptionActive((string) $organizationId)
                && !\App\Services\SubscriptionService::isSubscriptionLimitReachedForInboundMessages($organizationId);
        });
    }

    public static function moduleEnabled(int $organizationId): bool
    {
        return Cache::remember("webchat_module_enabled_{$organizationId}", 60, function () use ($organizationId) {
            return \App\Helpers\CustomHelper::isModuleEnabled('Web Chat', $organizationId);
        });
    }
}
