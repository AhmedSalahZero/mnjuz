<?php

namespace App\Services\WebChat;

use Illuminate\Support\Facades\Cache;

/**
 * ملفّات الـ widget المبنيّة (npm run build:widget) في public/widget/v1.
 *
 * loader.js باسم ثابت لأنه في كود التاجر ولا يتغيّر رابطه أبداً؛ يُخزَّن
 * دقائق. app.js يُطلب برابط فيه بصمة محتواه (?v=)، فيُخزَّن طويلاً ويتجدّد
 * مع كل بناء من غير أن يلمس التاجر شيئاً.
 *
 * الرابطان من المضيف الذي يخدم الطلب (url()) لا من APP_URL: يُبنيان في
 * صفحة الإعدادات وفي /config، وكلاهما طلب HTTP على نطاقنا. فيخرجان مطلقين
 * صحيحين ولو كان APP_URL بلا scheme، ويطابق رابطُ app.js مضيفَ loader.js.
 */
final class WebChatAssets
{
    public const DIRECTORY = 'widget/v1';

    public static function bundleUrl(): ?string
    {
        $path = public_path(self::DIRECTORY . '/app.js');

        if (!is_file($path)) {
            return null;
        }

        $version = Cache::remember(
            'webchat_bundle_version:' . filemtime($path),
            3600,
            static fn () => substr((string) md5_file($path), 0, 12)
        );

        return url(self::DIRECTORY . '/app.js') . '?v=' . $version;
    }

    public static function loaderUrl(): string
    {
        return url(self::DIRECTORY . '/loader.js');
    }
}
