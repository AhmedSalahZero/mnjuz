<?php

namespace App\Services\Email;

use App\Services\Messaging\CampaignChannels;
use Illuminate\Support\Str;

/**
 * تصميم قالب البريد كما يبنيه التاجر في الـ builder: إعدادات عامّة وعناصر
 * مرتّبة (ترويسة، عنوان، نص، صورة، زر…).
 *
 *     {version: 1,
 *      settings: {page_bg, content_bg, text_color, font, direction},
 *      blocks: [{id, type, props}]}
 *
 * هذا الكلاس مصدر الحقيقة للشكل: ما يقبله الخادم (validate)، وما يُخزَّن
 * (normalize — قائمة سماح للخصائص لا يمرّ غيرها)، والنسخة النصّية
 * (toPlainText). النصوص نصّ خام لا HTML — تُهرَّب عند العرض.
 */
final class EmailDesign
{
    public const VERSION = 1;

    public const MAX_BLOCKS = 60;

    public const MAX_SOCIAL_LINKS = 10;

    public const TYPES = ['header', 'heading', 'text', 'image', 'button', 'image_text', 'divider', 'spacer', 'social', 'footer'];

    public const FONT_STACKS = [
        'tahoma' => 'Tahoma, Arial, sans-serif',
        'arial' => 'Arial, Helvetica, sans-serif',
        'verdana' => 'Verdana, Geneva, sans-serif',
        'georgia' => 'Georgia, Times New Roman, serif',
    ];

    public const ALIGNMENTS = ['start', 'center', 'end'];

    public const SOCIAL_NETWORKS = ['website', 'instagram', 'x', 'facebook', 'tiktok', 'snapchat', 'youtube', 'linkedin', 'whatsapp'];

    private const URL_MAX = 2048;

    /**
     * خصائص كل عنصر: النوع والقيمة الافتراضية والحدود. الخاصية غير المذكورة
     * هنا تُسقط عند الحفظ.
     *
     * kinds: text (سطر/فقرة بحدّ max)، color (#RRGGBB)، int (min..max)،
     * align، enum (values)، url (http/https، فارغ مسموح، يقبل متغيّرات)،
     * image (رابط صورة http/https بلا متغيّرات)، links (روابط التواصل).
     */
    private const SCHEMA = [
        'header' => [
            'text' => ['kind' => 'text', 'max' => 200, 'default' => ''],
            'logo' => ['kind' => 'image', 'default' => ''],
            'bg_color' => ['kind' => 'color', 'default' => '#034737'],
            'color' => ['kind' => 'color', 'default' => '#ffffff'],
            'align' => ['kind' => 'align', 'default' => 'center'],
            'font_size' => ['kind' => 'int', 'min' => 14, 'max' => 40, 'default' => 22],
        ],
        'heading' => [
            'text' => ['kind' => 'text', 'max' => 200, 'default' => ''],
            'color' => ['kind' => 'color', 'default' => '#111827'],
            'align' => ['kind' => 'align', 'default' => 'center'],
            'font_size' => ['kind' => 'int', 'min' => 16, 'max' => 48, 'default' => 28],
        ],
        'text' => [
            'text' => ['kind' => 'text', 'max' => 3000, 'default' => ''],
            'color' => ['kind' => 'color', 'default' => '#374151'],
            'align' => ['kind' => 'align', 'default' => 'start'],
            'font_size' => ['kind' => 'int', 'min' => 12, 'max' => 28, 'default' => 16],
        ],
        'image' => [
            'src' => ['kind' => 'image', 'default' => ''],
            'alt' => ['kind' => 'text', 'max' => 200, 'default' => ''],
            'link' => ['kind' => 'url', 'default' => ''],
            'width' => ['kind' => 'int', 'min' => 20, 'max' => 100, 'default' => 100],
            'align' => ['kind' => 'align', 'default' => 'center'],
        ],
        'button' => [
            'label' => ['kind' => 'text', 'max' => 60, 'default' => ''],
            'url' => ['kind' => 'url', 'default' => ''],
            'bg_color' => ['kind' => 'color', 'default' => '#034737'],
            'color' => ['kind' => 'color', 'default' => '#ffffff'],
            'align' => ['kind' => 'align', 'default' => 'center'],
        ],
        'image_text' => [
            'src' => ['kind' => 'image', 'default' => ''],
            'alt' => ['kind' => 'text', 'max' => 200, 'default' => ''],
            'text' => ['kind' => 'text', 'max' => 3000, 'default' => ''],
            'image_position' => ['kind' => 'enum', 'values' => ['start', 'end'], 'default' => 'start'],
            'color' => ['kind' => 'color', 'default' => '#374151'],
            'font_size' => ['kind' => 'int', 'min' => 12, 'max' => 28, 'default' => 16],
        ],
        'divider' => [
            'color' => ['kind' => 'color', 'default' => '#e5e7eb'],
        ],
        'spacer' => [
            'height' => ['kind' => 'int', 'min' => 8, 'max' => 96, 'default' => 24],
        ],
        'social' => [
            'links' => ['kind' => 'links', 'default' => []],
            'color' => ['kind' => 'color', 'default' => '#034737'],
            'align' => ['kind' => 'align', 'default' => 'center'],
        ],
        'footer' => [
            'text' => ['kind' => 'text', 'max' => 1000, 'default' => ''],
            'color' => ['kind' => 'color', 'default' => '#6b7280'],
            'align' => ['kind' => 'align', 'default' => 'center'],
            'font_size' => ['kind' => 'int', 'min' => 11, 'max' => 18, 'default' => 13],
        ],
    ];

    /** @return array{page_bg: string, content_bg: string, text_color: string, font: string, direction: string} */
    public static function defaultSettings(?string $locale = null): array
    {
        $locale = $locale ?? (string) config('app.locale');

        return [
            'page_bg' => '#f4f5f7',
            'content_bg' => '#ffffff',
            'text_color' => '#1f2937',
            'font' => 'tahoma',
            'direction' => $locale === 'ar' ? 'rtl' : 'ltr',
        ];
    }

    /**
     * أخطاء التصميم بمساراتها في الطلب (design.blocks.3.props.url).
     *
     * @return array<string, string>
     */
    public static function validate(array $design): array
    {
        $errors = [];
        $blocks = $design['blocks'] ?? null;

        if (!is_array($blocks) || $blocks === []) {
            return ['design.blocks' => __('Add at least one element to the email.')];
        }

        if (count($blocks) > self::MAX_BLOCKS) {
            return ['design.blocks' => __('An email can have at most :max elements.', ['max' => self::MAX_BLOCKS])];
        }

        foreach (['page_bg', 'content_bg', 'text_color'] as $key) {
            $value = $design['settings'][$key] ?? null;

            if ($value !== null && !self::isColor($value)) {
                $errors["design.settings.{$key}"] = __('Enter a color like #1A2B3C.');
            }
        }

        $font = $design['settings']['font'] ?? null;
        if ($font !== null && !array_key_exists($font, self::FONT_STACKS)) {
            $errors['design.settings.font'] = __('Choose a value from the list.');
        }

        $direction = $design['settings']['direction'] ?? null;
        if ($direction !== null && !in_array($direction, ['rtl', 'ltr'], true)) {
            $errors['design.settings.direction'] = __('Choose a value from the list.');
        }

        foreach (array_values($blocks) as $index => $block) {
            $type = is_array($block) ? ($block['type'] ?? null) : null;

            if (!is_string($type) || !array_key_exists($type, self::SCHEMA)) {
                $errors["design.blocks.{$index}.type"] = __('Unknown element type.');
                continue;
            }

            $props = is_array($block['props'] ?? null) ? $block['props'] : [];

            foreach (self::SCHEMA[$type] as $name => $spec) {
                if (!array_key_exists($name, $props)) {
                    continue;
                }

                $error = self::propertyError($spec, $props[$name]);

                if ($error !== null) {
                    $errors["design.blocks.{$index}.props.{$name}"] = $error;
                }
            }
        }

        if ($errors === []) {
            $normalized = self::normalize($design);

            if (!self::hasContent($normalized)) {
                $errors['design.blocks'] = __('Add some content to the email.');
            } elseif (mb_strlen(self::toPlainText($normalized)) > CampaignChannels::EMAIL_TEXT_MAX) {
                $errors['design.blocks'] = __('The email text is too long (maximum :max characters).', ['max' => CampaignChannels::EMAIL_TEXT_MAX]);
            }
        }

        return $errors;
    }

    /**
     * الشكل المخزَّن: خصائص قائمة السماح وحدها بقيمها الافتراضية، والأرقام
     * داخل حدودها، والمعرّفات فريدة. يُستدعى بعد validate، ويُستدعى أيضاً
     * على ما يُقرأ من الحمولة قبل العرض (دفاعاً).
     *
     * @return array{version: int, settings: array<string, string>, blocks: array<int, array{id: string, type: string, props: array<string, mixed>}>}
     */
    public static function normalize(array $design, ?string $locale = null): array
    {
        $settings = self::defaultSettings($locale);
        $input = is_array($design['settings'] ?? null) ? $design['settings'] : [];

        foreach (['page_bg', 'content_bg', 'text_color'] as $key) {
            if (self::isColor($input[$key] ?? null)) {
                $settings[$key] = strtolower($input[$key]);
            }
        }

        if (array_key_exists($input['font'] ?? '', self::FONT_STACKS)) {
            $settings['font'] = $input['font'];
        }

        if (in_array($input['direction'] ?? null, ['rtl', 'ltr'], true)) {
            $settings['direction'] = $input['direction'];
        }

        $blocks = [];
        $seen = [];

        foreach (array_slice(array_values(is_array($design['blocks'] ?? null) ? $design['blocks'] : []), 0, self::MAX_BLOCKS) as $block) {
            $type = is_array($block) ? ($block['type'] ?? null) : null;

            if (!is_string($type) || !array_key_exists($type, self::SCHEMA)) {
                continue;
            }

            $id = is_string($block['id'] ?? null) && preg_match('/^[A-Za-z0-9_-]{1,40}$/', $block['id']) ? $block['id'] : '';

            if ($id === '' || isset($seen[$id])) {
                $id = 'b' . Str::lower(Str::random(10));
            }

            $seen[$id] = true;
            $props = is_array($block['props'] ?? null) ? $block['props'] : [];
            $clean = [];

            foreach (self::SCHEMA[$type] as $name => $spec) {
                $clean[$name] = self::normalizeProperty($spec, $props[$name] ?? null);
            }

            $blocks[] = ['id' => $id, 'type' => $type, 'props' => $clean];
        }

        return ['version' => self::VERSION, 'settings' => $settings, 'blocks' => $blocks];
    }

    /**
     * النسخة النصّية: جزء text/plain في الرسالة، ومقتطف القائمة، ومن يقرأ
     * حمولة الحملة نصّاً (تطبيق الجوال).
     */
    public static function toPlainText(array $design): string
    {
        $parts = [];

        foreach ($design['blocks'] ?? [] as $block) {
            $props = $block['props'] ?? [];

            $parts[] = match ($block['type'] ?? null) {
                'header', 'heading', 'text', 'image_text', 'footer' => trim((string) ($props['text'] ?? '')),
                'image' => trim((string) ($props['link'] ?? '')),
                'button' => self::labelled((string) ($props['label'] ?? ''), (string) ($props['url'] ?? '')),
                'social' => implode("\n", array_map(
                    static fn (array $link) => self::labelled(self::networkLabel($link['network']), $link['url']),
                    $props['links'] ?? []
                )),
                default => '',
            };
        }

        return trim(implode("\n\n", array_filter($parts, static fn ($part) => $part !== '')));
    }

    /** قالب نصّي قديم (قبل الـ builder) كعنصر نصّ واحد. */
    public static function fromPlainText(string $body, ?string $locale = null): array
    {
        return self::normalize([
            'blocks' => [['type' => 'text', 'props' => ['text' => $body]]],
        ], $locale);
    }

    /** اسم الشبكة كما يُعرض في الرسالة. */
    public static function networkLabel(string $network): string
    {
        return match ($network) {
            'website' => __('Website'),
            'instagram' => __('Instagram'),
            'x' => 'X',
            'facebook' => __('Facebook'),
            'tiktok' => __('TikTok'),
            'snapchat' => __('Snapchat'),
            'youtube' => __('YouTube'),
            'linkedin' => __('LinkedIn'),
            'whatsapp' => __('WhatsApp'),
            default => $network,
        };
    }

    /**
     * رابط http/https بلا مسافات ولا محارف تكسر السمة. يقبل العربية في
     * المسار (FILTER_VALIDATE_URL يرفضها).
     */
    public static function isSafeUrl(string $url): bool
    {
        if ($url === '' || mb_strlen($url) > self::URL_MAX || preg_match('/[\s<>"\'`\\\\\x00-\x1F\x7F]/u', $url)) {
            return false;
        }

        $parts = parse_url($url);

        return is_array($parts)
            && in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            && ($parts['host'] ?? '') !== '';
    }

    /** رابط قد يحوي متغيّرات ({url:first_name}): يُفحص بعد وضع قيمة مكانها. */
    public static function isSafeUrlTemplate(string $url): bool
    {
        return self::isSafeUrl((string) preg_replace('/\{(url:)?[\w\x{0600}-\x{06FF}]+\}/u', 'x', $url));
    }

    public static function isColor(mixed $value): bool
    {
        return is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1;
    }

    /** هل في التصميم ما يُرى؟ نصّ أو صورة. */
    private static function hasContent(array $design): bool
    {
        if (self::toPlainText($design) !== '') {
            return true;
        }

        foreach ($design['blocks'] as $block) {
            if (($block['props']['src'] ?? '') !== '' || ($block['props']['logo'] ?? '') !== '') {
                return true;
            }
        }

        return false;
    }

    private static function labelled(string $label, string $url): string
    {
        $label = trim($label);
        $url = trim($url);

        if ($url === '') {
            return $label;
        }

        return $label === '' ? $url : $label . ': ' . $url;
    }

    private static function propertyError(array $spec, mixed $value): ?string
    {
        return match ($spec['kind']) {
            'text' => !is_string($value) && $value !== null
                ? __('Enter text.')
                : (mb_strlen((string) $value) > $spec['max'] ? __('This text is too long (maximum :max characters).', ['max' => $spec['max']]) : null),
            'color' => self::isColor($value) ? null : __('Enter a color like #1A2B3C.'),
            'int' => is_numeric($value) && (int) $value >= $spec['min'] && (int) $value <= $spec['max']
                ? null
                : __('Choose a value between :min and :max.', ['min' => $spec['min'], 'max' => $spec['max']]),
            'align' => in_array($value, self::ALIGNMENTS, true) ? null : __('Choose a value from the list.'),
            'enum' => in_array($value, $spec['values'], true) ? null : __('Choose a value from the list.'),
            'url' => $value === null || $value === '' || (is_string($value) && self::isSafeUrlTemplate($value))
                ? null
                : __('Enter a link that starts with http:// or https://.'),
            'image' => $value === null || $value === '' || (is_string($value) && !str_contains($value, '{') && self::isSafeUrl($value))
                ? null
                : __('Choose an image from the library.'),
            'links' => self::linksError($value),
            default => null,
        };
    }

    private static function linksError(mixed $links): ?string
    {
        if (!is_array($links)) {
            return __('Choose a value from the list.');
        }

        if (count($links) > self::MAX_SOCIAL_LINKS) {
            return __('Add at most :max links.', ['max' => self::MAX_SOCIAL_LINKS]);
        }

        foreach ($links as $link) {
            if (!is_array($link) || !in_array($link['network'] ?? null, self::SOCIAL_NETWORKS, true)) {
                return __('Choose a value from the list.');
            }

            $url = $link['url'] ?? '';

            if (!is_string($url) || ($url !== '' && !self::isSafeUrlTemplate($url))) {
                return __('Enter a link that starts with http:// or https://.');
            }
        }

        return null;
    }

    private static function normalizeProperty(array $spec, mixed $value): mixed
    {
        return match ($spec['kind']) {
            'text' => mb_substr(is_scalar($value) ? (string) $value : '', 0, $spec['max']),
            'color' => self::isColor($value) ? strtolower($value) : $spec['default'],
            'int' => is_numeric($value) ? max($spec['min'], min($spec['max'], (int) $value)) : $spec['default'],
            'align' => in_array($value, self::ALIGNMENTS, true) ? $value : $spec['default'],
            'enum' => in_array($value, $spec['values'], true) ? $value : $spec['default'],
            'url' => is_string($value) && self::isSafeUrlTemplate(trim($value)) ? trim($value) : '',
            'image' => is_string($value) && !str_contains($value, '{') && self::isSafeUrl(trim($value)) ? trim($value) : '',
            'links' => self::normalizeLinks($value),
            default => $spec['default'],
        };
    }

    /** @return array<int, array{network: string, url: string}> */
    private static function normalizeLinks(mixed $links): array
    {
        if (!is_array($links)) {
            return [];
        }

        $clean = [];

        foreach (array_slice(array_values($links), 0, self::MAX_SOCIAL_LINKS) as $link) {
            $network = is_array($link) ? ($link['network'] ?? null) : null;
            $url = is_array($link) && is_string($link['url'] ?? null) ? trim($link['url']) : '';

            if (!in_array($network, self::SOCIAL_NETWORKS, true) || !self::isSafeUrlTemplate($url)) {
                continue;
            }

            $clean[] = ['network' => $network, 'url' => $url];
        }

        return $clean;
    }
}
