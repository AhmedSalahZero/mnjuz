<?php

namespace App\Services\Carousel;

/**
 * قراءة قالب الـ carousel كما خزّنّاه.
 *
 * المصدران يختلفان في الشكل لا في البنية: الإنشاء من عندنا يحفظ الحمولة التي
 * أُرسلت إلى Meta بأنواع UPPERCASE، والمزامنة تحفظ كائن Meta نفسه (معه id
 * وstatus وروابط scontent). لذلك المقارنة كلها بلا حساسية لحالة الأحرف.
 */
final class CarouselDefinition
{
    public const MIN_CARDS = 2;
    public const MAX_CARDS = 10;
    public const MAX_BUTTONS = 2;
    public const BODY_MAX = 1024;
    public const CARD_BODY_MAX = 160;
    public const BUTTON_TEXT_MAX = 25;
    public const BUTTON_TYPES = ['QUICK_REPLY', 'URL', 'PHONE_NUMBER'];
    public const FORMATS = ['IMAGE', 'VIDEO'];

    /** هل القالب (metadata نصّاً أو مصفوفةً أو كائناً) قالب carousel؟ */
    public static function isCarousel($metadata): bool
    {
        return self::carouselComponent(self::decode($metadata)) !== null;
    }

    /**
     * تعريف موحّد للقالب أو null إن لم يكن carousel.
     *
     * @return array{
     *   body: array{text: string, variables: int},
     *   format: string,
     *   cards: array<int, array{
     *     body: ?array{text: string, variables: int},
     *     buttons: array<int, array{type: string, text: string, url: ?string, has_variable: bool, example: ?string, phone_number: ?string}>
     *   }>
     * }|null
     */
    public static function fromMetadata($metadata): ?array
    {
        $decoded = self::decode($metadata);
        $carousel = self::carouselComponent($decoded);

        if ($carousel === null) {
            return null;
        }

        $bodyText = '';
        foreach ($decoded['components'] as $component) {
            if (self::type($component) === 'BODY') {
                $bodyText = (string) ($component['text'] ?? '');
                break;
            }
        }

        $format = null;
        $cards = [];

        foreach (array_values($carousel['cards']) as $card) {
            $components = is_array($card['components'] ?? null) ? $card['components'] : [];
            $cardBody = null;
            $buttons = [];

            foreach ($components as $component) {
                $type = self::type($component);

                if ($type === 'HEADER' && $format === null) {
                    $candidate = strtoupper((string) ($component['format'] ?? ''));
                    $format = in_array($candidate, self::FORMATS, true) ? $candidate : null;
                } elseif ($type === 'BODY' && isset($component['text'])) {
                    $cardBody = [
                        'text' => (string) $component['text'],
                        'variables' => self::countVariables((string) $component['text']),
                    ];
                } elseif ($type === 'BUTTONS' && is_array($component['buttons'] ?? null)) {
                    foreach (array_values($component['buttons']) as $button) {
                        $buttons[] = self::button(is_array($button) ? $button : []);
                    }
                }
            }

            $cards[] = ['body' => $cardBody, 'buttons' => $buttons];
        }

        return [
            'body' => ['text' => $bodyText, 'variables' => self::countVariables($bodyText)],
            'format' => $format ?? 'IMAGE',
            'cards' => $cards,
        ];
    }

    /** عدد المتغيّرات المختلفة {{n}} في النص. */
    public static function countVariables(string $text): int
    {
        preg_match_all('/\{\{\s*(\d+)\s*\}\}/', $text, $matches);

        return count(array_unique($matches[1]));
    }

    /** @return array<string, mixed> */
    public static function decode($metadata): array
    {
        if (is_string($metadata)) {
            $metadata = json_decode($metadata, true);
        } elseif (is_object($metadata)) {
            $metadata = json_decode(json_encode($metadata), true);
        }

        return is_array($metadata) ? $metadata : [];
    }

    private static function carouselComponent(array $decoded): ?array
    {
        $components = $decoded['components'] ?? null;

        if (!is_array($components)) {
            return null;
        }

        foreach ($components as $component) {
            if (is_array($component)
                && self::type($component) === 'CAROUSEL'
                && is_array($component['cards'] ?? null)
                && count($component['cards']) > 0) {
                return $component;
            }
        }

        return null;
    }

    private static function type($component): string
    {
        return is_array($component) ? strtoupper((string) ($component['type'] ?? '')) : '';
    }

    private static function button(array $button): array
    {
        $type = strtoupper((string) ($button['type'] ?? ''));
        $url = isset($button['url']) ? (string) $button['url'] : null;
        $example = $button['example'] ?? null;

        if (is_array($example)) {
            $example = $example[0] ?? null;
        }

        return [
            'type' => $type,
            'text' => (string) ($button['text'] ?? ''),
            'url' => $url,
            'has_variable' => $type === 'URL' && $url !== null && self::countVariables($url) > 0,
            'example' => $example !== null ? (string) $example : null,
            'phone_number' => isset($button['phone_number']) ? (string) $button['phone_number'] : null,
        ];
    }
}
