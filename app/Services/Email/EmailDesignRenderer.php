<?php

namespace App\Services\Email;

/**
 * HTML رسالة بريد من تصميم الـ builder، لمستلم واحد.
 *
 *  - المتغيّرات تُستبدل في النصّ الخام (resolve)، ثم يُهرَّب كل شيء في
 *    Blade: لا HTML من التاجر ولا من حقول جهة الاتصال.
 *  - الروابط تُفحص بعد الاستبدال: ما ليس http/https يسقط فيُعرض الزر نصّاً
 *    بلا رابط.
 *  - تذييل «وصلتك هذه الرسالة… إلغاء الاشتراك» في القالب دائماً، خارج
 *    العناصر — لا يُحذف من الـ builder.
 *  - جداول وأنماط مضمّنة: ما تفهمه برامج البريد.
 */
class EmailDesignRenderer
{
    /** عرض المحتوى (600) ناقص الحشو (24×2): مرجع عرض الصور لبرامج لا تفهم %. */
    private const CONTENT_WIDTH = 552;

    /**
     * @param \Closure(string): string $resolve استبدال المتغيّرات لهذا المستلم.
     * @param array{locale: string, subject: string, fromName: string, unsubscribeUrl: string} $layout
     */
    public function render(array $design, \Closure $resolve, array $layout): string
    {
        $design = EmailDesign::normalize($design, $layout['locale']);
        $settings = $design['settings'];
        $rtl = $settings['direction'] === 'rtl';

        $blocks = [];

        foreach ($design['blocks'] as $block) {
            $prepared = $this->prepare($block['type'], $block['props'], $resolve, $rtl);

            if ($prepared !== null) {
                $blocks[] = $prepared;
            }
        }

        return view('emails.campaign-design', [
            'locale' => $layout['locale'],
            'subject' => $layout['subject'],
            'fromName' => $layout['fromName'],
            'unsubscribeUrl' => $layout['unsubscribeUrl'],
            'direction' => $settings['direction'],
            'pageBg' => $settings['page_bg'],
            'contentBg' => $settings['content_bg'],
            'textColor' => $settings['text_color'],
            'font' => EmailDesign::FONT_STACKS[$settings['font']],
            'blocks' => $blocks,
        ])->render();
    }

    /**
     * بيانات قالب العنصر بعد الاستبدال، أو null لعنصر فارغ لا يُعرض.
     *
     * @return array<string, mixed>|null
     */
    private function prepare(string $type, array $props, \Closure $resolve, bool $rtl): ?array
    {
        $align = $this->cssAlign($props['align'] ?? 'start', $rtl);

        switch ($type) {
            case 'header':
                $text = $resolve($props['text']);

                return $text === '' && $props['logo'] === '' ? null : [
                    'type' => 'header', 'text' => $text, 'logo' => $props['logo'], 'bg_color' => $props['bg_color'],
                    'color' => $props['color'], 'align' => $align, 'font_size' => $props['font_size'],
                ];

            case 'heading':
            case 'text':
            case 'footer':
                $text = $resolve($props['text']);

                return $text === '' ? null : [
                    'type' => $type, 'text' => $text, 'color' => $props['color'], 'align' => $align, 'font_size' => $props['font_size'],
                ];

            case 'image':
                return $props['src'] === '' ? null : $this->image($props['src'], $props['alt'], $this->link($props['link'], $resolve), $props['width'], $align);

            case 'button':
                $label = $resolve($props['label']);

                return $label === '' ? null : [
                    'type' => 'button', 'label' => $label, 'href' => $this->link($props['url'], $resolve),
                    'bg_color' => $props['bg_color'], 'color' => $props['color'], 'align' => $align,
                ];

            case 'image_text':
                $text = $resolve($props['text']);

                if ($props['src'] === '') {
                    return $text === '' ? null : [
                        'type' => 'text', 'text' => $text, 'color' => $props['color'], 'align' => $this->cssAlign('start', $rtl), 'font_size' => $props['font_size'],
                    ];
                }

                if ($text === '') {
                    return $this->image($props['src'], $props['alt'], null, 100, $this->cssAlign('center', $rtl));
                }

                return [
                    'type' => 'image_text', 'src' => $props['src'], 'alt' => $props['alt'], 'text' => $text,
                    'order' => $props['image_position'] === 'end' ? ['text', 'image'] : ['image', 'text'],
                    'color' => $props['color'], 'font_size' => $props['font_size'], 'text_align' => $this->cssAlign('start', $rtl),
                ];

            case 'divider':
                return ['type' => 'divider', 'color' => $props['color']];

            case 'spacer':
                return ['type' => 'spacer', 'height' => $props['height']];

            case 'social':
                $links = [];

                foreach ($props['links'] as $link) {
                    $href = $this->link($link['url'], $resolve);

                    if ($href !== null) {
                        $links[] = ['href' => $href, 'label' => EmailDesign::networkLabel($link['network'])];
                    }
                }

                return $links === [] ? null : ['type' => 'social', 'links' => $links, 'color' => $props['color'], 'align' => $align];
        }

        return null;
    }

    private function image(string $src, string $alt, ?string $href, int $width, string $align): array
    {
        return [
            'type' => 'image', 'src' => $src, 'alt' => $alt, 'href' => $href, 'width' => $width,
            'width_px' => (int) round(self::CONTENT_WIDTH * $width / 100), 'align' => $align,
        ];
    }

    /** الرابط بعد الاستبدال إن بقي http/https سليماً، وإلا null. */
    private function link(string $url, \Closure $resolve): ?string
    {
        if ($url === '') {
            return null;
        }

        $resolved = trim($resolve($url));

        return EmailDesign::isSafeUrl($resolved) ? $resolved : null;
    }

    /** start/end حسب اتجاه الرسالة؛ برامج البريد لا تفهم text-align: start. */
    private function cssAlign(string $align, bool $rtl): string
    {
        return match ($align) {
            'center' => 'center',
            'end' => $rtl ? 'left' : 'right',
            default => $rtl ? 'right' : 'left',
        };
    }
}
