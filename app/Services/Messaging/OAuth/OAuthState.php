<?php

namespace App\Services\Messaging\OAuth;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * معامل `state` في تدفّق OAuth.
 *
 * ليس زينة: بدونه يستطيع مهاجم أن يُوقع موظّفاً على رابط callback يحمل
 * `code` يخصّ حساب المهاجم، فيُربط حساب المهاجم بمنشأة الضحية — ويقرأ كل
 * رسائلها بعدها. هذا هجوم CSRF على الربط، وهو معروف وموثَّق.
 *
 * ثلاث حمايات هنا:
 *   ١. **مشفّر وموقَّع** بمفتاح التطبيق: لا يستطيع أحد تركيب حالة صالحة.
 *   ٢. **ينتهي بعد عشر دقائق**: رابط مسروق من سجلّ أو من التاريخ لا يعمل.
 *   ٣. **يُستعمل مرّة واحدة**: nonce في الكاش يُحذف عند أول استعمال، فإعادة
 *      تشغيل نفس الـ callback لا تُعيد الربط.
 *
 * والحمولة تحمل المنشأة والمستخدم، فنتحقّق عند العودة أن الجلسة هي نفسها
 * التي بدأت — لا جلسة أخرى فُتحت في الأثناء.
 */
class OAuthState
{
    /** عمر الحالة. أطول من هذا يُوسّع نافذة الهجوم بلا فائدة للمستخدم. */
    public const TTL_MINUTES = 10;

    private const CACHE_PREFIX = 'channel_oauth_state:';

    /**
     * إنشاء حالة موقَّعة لبدء التفويض.
     */
    public static function issue(string $channel, int $organizationId, int $userId): string
    {
        $nonce = Str::random(40);

        Cache::put(self::CACHE_PREFIX . $nonce, true, now()->addMinutes(self::TTL_MINUTES));

        return Crypt::encryptString(json_encode([
            'channel' => $channel,
            'organization_id' => $organizationId,
            'user_id' => $userId,
            'nonce' => $nonce,
            'expires_at' => now()->addMinutes(self::TTL_MINUTES)->timestamp,
        ]));
    }

    /**
     * التحقّق من حالة عائدة واستهلاكها.
     *
     * @return array{channel: string, organization_id: int, user_id: int}|null
     *         null يعني حالةً غير صالحة — ولا نُفرّق بين الأسباب للمستخدم.
     */
    public static function consume(?string $state): ?array
    {
        if (!is_string($state) || $state === '') {
            return null;
        }

        try {
            $payload = json_decode(Crypt::decryptString($state), true);
        } catch (\Throwable) {
            // فكّ التشفير فشل: الحالة مُركَّبة أو مشوّهة.
            return null;
        }

        if (!is_array($payload)) {
            return null;
        }

        foreach (['channel', 'organization_id', 'user_id', 'nonce', 'expires_at'] as $key) {
            if (!isset($payload[$key])) {
                return null;
            }
        }

        if ((int) $payload['expires_at'] < now()->timestamp) {
            return null;
        }

        $cacheKey = self::CACHE_PREFIX . $payload['nonce'];

        // الاستهلاك ذرّي: pull تقرأ وتحذف معاً، فطلبان متزامنان لا ينجح
        // منهما إلّا واحد.
        if (Cache::pull($cacheKey) === null) {
            return null;
        }

        return [
            'channel' => (string) $payload['channel'],
            'organization_id' => (int) $payload['organization_id'],
            'user_id' => (int) $payload['user_id'],
        ];
    }
}
