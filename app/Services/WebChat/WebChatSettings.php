<?php

namespace App\Services\WebChat;

use App\Models\ChannelAccount;

/**
 * إعدادات widget المحادثة المباشرة: القيم الافتراضية، والتطبيع، وما يصل
 * منها إلى متصفّح الزائر.
 *
 * المخزَّن في channel_accounts.settings جزئي دائماً (ما غيّره التاجر)، فكل
 * قراءة تمرّ من هنا لتُكمَّل بالافتراضي — ولا يقرأ أحدٌ المصفوفة الخام.
 */
final class WebChatSettings
{
    public const DEFAULTS = [
        'enabled' => true,
        // فارغة = كل المواقع. التاجر يقصرها على دوميناته من الإعدادات.
        'allowed_domains' => [],
        'appearance' => [
            'color' => '#16a34a',
            'position' => 'right',
            'offset_x' => 20,
            'offset_y' => 20,
            'logo_url' => null,
            // شعار رُفع عندنا (تخزين محلّي): المسار يُحفظ والرابط يُحسب عند العرض.
            'logo_path' => null,
            'title' => '',
            'subtitle' => '',
            'welcome_message' => '',
            'language' => 'auto',
        ],
        'prechat' => [
            'default_country' => 'SA',
            // off | optional | required
            'email' => 'off',
            'name_label' => null,
            'phone_label' => null,
            'consent_text' => null,
        ],
        'behavior' => [
            'show_agent_names' => true,
            'sound' => true,
            'uploads' => true,
        ],
    ];

    public const POSITIONS = ['right', 'left'];
    public const LANGUAGES = ['auto', 'ar', 'en'];
    public const EMAIL_MODES = ['off', 'optional', 'required'];

    /** الإعدادات كاملةً: المخزَّن فوق الافتراضي. */
    public static function for(ChannelAccount $account): array
    {
        return self::merge((array) ($account->settings ?? []));
    }

    public static function merge(array $stored): array
    {
        $settings = self::DEFAULTS;

        foreach (['enabled'] as $flag) {
            if (array_key_exists($flag, $stored)) {
                $settings[$flag] = (bool) $stored[$flag];
            }
        }

        // قائمة لا خريطة: تُستبدل كاملةً، فلا يبقى دومين حذفه التاجر.
        if (isset($stored['allowed_domains']) && is_array($stored['allowed_domains'])) {
            $settings['allowed_domains'] = self::normalizeDomains($stored['allowed_domains']);
        }

        foreach (['appearance', 'prechat', 'behavior'] as $group) {
            if (isset($stored[$group]) && is_array($stored[$group])) {
                $settings[$group] = array_replace($settings[$group], array_intersect_key($stored[$group], $settings[$group]));
            }
        }

        $appearance = &$settings['appearance'];
        $appearance['color'] = self::color($appearance['color']);
        $appearance['position'] = in_array($appearance['position'], self::POSITIONS, true) ? $appearance['position'] : 'right';
        $appearance['language'] = in_array($appearance['language'], self::LANGUAGES, true) ? $appearance['language'] : 'auto';
        $appearance['offset_x'] = max(0, min(200, (int) $appearance['offset_x']));
        $appearance['offset_y'] = max(0, min(200, (int) $appearance['offset_y']));
        $appearance['logo_url'] = self::httpsUrl($appearance['logo_url']);
        $appearance['logo_path'] = is_string($appearance['logo_path'])
            && preg_match('#^public/webchat-logos/\d+/[a-z0-9]+\.(png|jpe?g|webp)$#', $appearance['logo_path'])
            ? $appearance['logo_path']
            : null;
        foreach (['title', 'subtitle', 'welcome_message'] as $text) {
            $appearance[$text] = mb_substr(trim((string) $appearance[$text]), 0, $text === 'welcome_message' ? 500 : 80);
        }
        unset($appearance);

        $prechat = &$settings['prechat'];
        $prechat['email'] = in_array($prechat['email'], self::EMAIL_MODES, true) ? $prechat['email'] : 'off';
        $prechat['default_country'] = self::country($prechat['default_country']);
        foreach (['name_label', 'phone_label', 'consent_text'] as $text) {
            $value = trim((string) $prechat[$text]);
            $prechat[$text] = $value === '' ? null : mb_substr($value, 0, $text === 'consent_text' ? 500 : 60);
        }
        unset($prechat);

        foreach (array_keys(self::DEFAULTS['behavior']) as $flag) {
            $settings['behavior'][$flag] = (bool) $settings['behavior'][$flag];
        }

        return $settings;
    }

    /**
     * ما يحتاجه الـ widget ليرسم نفسه — لا شيء غير ذلك. قائمة الدومينات
     * لا تخرج: لا يحتاجها المتصفّح، ولا داعي لكشف مواقع التاجر.
     */
    public static function publicView(ChannelAccount $account): array
    {
        $settings = self::for($account);

        $appearance = $settings['appearance'];

        // الشعار المرفوع عندنا يُخدم من /media، والمسار نفسه لا يخرج.
        if ($appearance['logo_path']) {
            $appearance['logo_url'] = WebChatMessagePresenter::publicUrl($appearance['logo_path']);
        }

        unset($appearance['logo_path']);

        return [
            'name' => (string) ($account->name ?? ''),
            'appearance' => $appearance,
            'prechat' => $settings['prechat'],
            'behavior' => $settings['behavior'],
        ];
    }

    /* ───────────────────────── الدومينات ───────────────────────── */

    /**
     * هل يُسمح للـ widget بالعمل على هذا الموقع؟
     *
     * القائمة الفارغة تعني كل المواقع. «*.example.com» يطابق النطاقات
     * الفرعية والنطاق نفسه. والمقارنة على اسم المضيف وحده: المنفذ والمسار لا
     * يُغيّران صاحب الموقع.
     */
    public static function hostAllowed(array $settings, ?string $host): bool
    {
        $allowed = $settings['allowed_domains'] ?? [];

        if ($allowed === []) {
            return true;
        }

        $host = self::normalizeDomain((string) $host);

        if ($host === null) {
            return false;
        }

        foreach ($allowed as $rule) {
            if (str_starts_with($rule, '*.')) {
                $base = substr($rule, 2);

                if ($host === $base || str_ends_with($host, '.' . $base)) {
                    return true;
                }

                continue;
            }

            if ($host === $rule) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int, string> */
    public static function normalizeDomains(array $domains): array
    {
        $clean = [];

        foreach ($domains as $domain) {
            $normalized = self::normalizeDomain((string) $domain, allowWildcard: true);

            if ($normalized !== null && !in_array($normalized, $clean, true)) {
                $clean[] = $normalized;
            }
        }

        return array_slice($clean, 0, 20);
    }

    /**
     * «https://Shop.Example.com:8080/path» → «shop.example.com».
     * التاجر يلصق الرابط كما هو في المتصفّح، فنقبله ونستخرج المضيف.
     */
    public static function normalizeDomain(string $value, bool $allowWildcard = false): ?string
    {
        $value = strtolower(trim($value));

        if ($value === '') {
            return null;
        }

        $wildcard = false;

        if ($allowWildcard && str_starts_with($value, '*.')) {
            $wildcard = true;
            $value = substr($value, 2);
        }

        if (!str_contains($value, '://')) {
            $value = 'https://' . $value;
        }

        $host = parse_url($value, PHP_URL_HOST);

        if (!is_string($host) || $host === '') {
            return null;
        }

        $host = rtrim($host, '.');

        if (str_starts_with($host, 'www.') && !$wildcard) {
            // www وبدونها موقع واحد عند التاجر.
            $host = substr($host, 4);
        }

        if (!preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/', $host)
            && filter_var($host, FILTER_VALIDATE_IP) === false
        ) {
            return null;
        }

        return ($wildcard ? '*.' : '') . $host;
    }

    /* ───────────────────────── قيم مفردة ───────────────────────── */

    private static function color(mixed $value): string
    {
        $value = trim((string) $value);

        return preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? strtolower($value) : self::DEFAULTS['appearance']['color'];
    }

    private static function country(mixed $value): string
    {
        $value = strtoupper(trim((string) $value));

        return preg_match('/^[A-Z]{2}$/', $value) ? $value : self::DEFAULTS['prechat']['default_country'];
    }

    /** شعار الـ widget يُعرض في موقع التاجر: https وحدها، ولا مخطّط آخر. */
    private static function httpsUrl(mixed $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '' || !str_starts_with(strtolower($value), 'https://')) {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_URL) ? mb_substr($value, 0, 1024) : null;
    }
}
