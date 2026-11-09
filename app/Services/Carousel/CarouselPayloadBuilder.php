<?php

namespace App\Services\Carousel;

/**
 * بناء حمولات الـ carousel: طلب الإنشاء إلى Meta، ومكوّن الإرسال، وما يُحفظ
 * في سجلّ المحادثة. دوال صافية بلا قاعدة بيانات ولا HTTP.
 */
final class CarouselPayloadBuilder
{
    /**
     * حمولة POST /{waba}/message_templates.
     *
     * $input هو الطلب بعد التحقّق: name, language, body{text, example[]},
     * carousel{format, cards[{body?{text, example[]}, buttons[]}]}.
     * $handles[i] هو header_handle المرفوع لبطاقة i.
     *
     * @param array<int, string> $handles
     */
    public static function createPayload(array $input, array $handles): array
    {
        $format = strtoupper((string) ($input['carousel']['format'] ?? 'IMAGE'));

        $body = ['type' => 'BODY', 'text' => (string) ($input['body']['text'] ?? '')];
        $bodyExamples = self::examples($input['body']['example'] ?? []);
        if (CarouselDefinition::countVariables($body['text']) > 0 && $bodyExamples !== []) {
            $body['example'] = ['body_text' => [$bodyExamples]];
        }

        $cards = [];
        foreach (array_values($input['carousel']['cards'] ?? []) as $index => $card) {
            $components = [[
                'type' => 'HEADER',
                'format' => $format,
                'example' => ['header_handle' => [(string) ($handles[$index] ?? '')]],
            ]];

            $cardText = trim((string) ($card['body']['text'] ?? ''));
            if ($cardText !== '') {
                $cardBody = ['type' => 'BODY', 'text' => (string) $card['body']['text']];
                $cardExamples = self::examples($card['body']['example'] ?? []);
                if (CarouselDefinition::countVariables($cardText) > 0 && $cardExamples !== []) {
                    $cardBody['example'] = ['body_text' => [$cardExamples]];
                }
                $components[] = $cardBody;
            }

            $buttons = [];
            foreach (array_values($card['buttons'] ?? []) as $button) {
                $buttons[] = self::createButton($button);
            }
            if ($buttons !== []) {
                $components[] = ['type' => 'BUTTONS', 'buttons' => $buttons];
            }

            $cards[] = ['components' => $components];
        }

        return [
            'name' => (string) ($input['name'] ?? ''),
            'language' => (string) ($input['language'] ?? ''),
            'category' => 'MARKETING',
            'components' => [
                $body,
                ['type' => 'CAROUSEL', 'cards' => $cards],
            ],
        ];
    }

    /**
     * مكوّن carousel في رسالة الإرسال، من carousel المحفوظ في metadata الحملة.
     *
     * $resolve يحوّل معاملاً (selection/value) إلى نصّ نهائي لجهة الاتصال،
     * وهو resolveTemplateTextValue نفسه الذي تستعمله بقية القالب.
     *
     * @param object|array $carousel
     */
    public static function sendComponent($carousel, callable $resolve): array
    {
        $carousel = json_decode(json_encode($carousel));
        $format = strtolower((string) ($carousel->format ?? 'image'));
        $cards = [];

        foreach (array_values((array) ($carousel->cards ?? [])) as $position => $card) {
            $header = $card->header ?? null;
            $media = !empty($header->media_id)
                ? ['id' => (string) $header->media_id]
                : ['link' => (string) ($header->value ?? '')];

            $components = [[
                'type' => 'header',
                'parameters' => [['type' => $format, $format => $media]],
            ]];

            $bodyParameters = (array) ($card->body->parameters ?? []);
            if ($bodyParameters !== []) {
                $components[] = [
                    'type' => 'body',
                    'parameters' => array_map(
                        fn ($parameter) => ['type' => 'text', 'text' => (string) $resolve($parameter)],
                        array_values($bodyParameters)
                    ),
                ];
            }

            foreach (array_values((array) ($card->buttons ?? [])) as $buttonPosition => $button) {
                $component = self::sendButton($button, (int) ($button->index ?? $buttonPosition), $resolve);
                if ($component !== null) {
                    $components[] = $component;
                }
            }

            $cards[] = [
                'card_index' => (int) ($card->card_index ?? $position),
                'components' => $components,
            ];
        }

        return ['type' => 'carousel', 'cards' => $cards];
    }

    /**
     * ما يُحفظ في chats.metadata تحت مفتاح carousel: مختصر عمداً ليبقى البثّ
     * تحت حدّ Pusher (10KB) حتى مع عشر بطاقات.
     *
     * @param array<int, array{media: ?string, body: ?string, buttons: array}> $cards
     */
    public static function chatCarousel(string $format, array $cards): array
    {
        return [
            'format' => strtoupper($format),
            'cards' => array_map(fn (array $card) => [
                'media' => isset($card['media']) ? mb_substr((string) $card['media'], 0, 2048) : null,
                'body' => isset($card['body']) && $card['body'] !== '' ? mb_substr((string) $card['body'], 0, CarouselDefinition::CARD_BODY_MAX) : null,
                'buttons' => array_map(fn ($button) => [
                    'type' => strtoupper((string) ($button['type'] ?? '')),
                    'text' => mb_substr((string) ($button['text'] ?? ''), 0, 40),
                ], array_values($card['buttons'] ?? [])),
            ], array_values($cards)),
        ];
    }

    /** يستبدل {{n}} بالقيمة n من $values (المصفوفة تبدأ من صفر). */
    public static function substitute(string $text, array $values): string
    {
        return preg_replace_callback('/\{\{\s*(\d+)\s*\}\}/', function ($match) use ($values) {
            $index = (int) $match[1] - 1;

            return array_key_exists($index, $values) ? (string) $values[$index] : $match[0];
        }, $text);
    }

    /** @return array<int, string> */
    private static function examples($examples): array
    {
        if (!is_array($examples)) {
            return [];
        }

        return array_values(array_map(fn ($value) => (string) $value, $examples));
    }

    private static function createButton(array $button): array
    {
        $type = strtoupper((string) ($button['type'] ?? ''));
        $text = (string) ($button['text'] ?? '');

        if ($type === 'URL') {
            $url = (string) ($button['url'] ?? '');
            $result = ['type' => 'URL', 'text' => $text, 'url' => $url];

            if (CarouselDefinition::countVariables($url) > 0) {
                $result['example'] = [(string) ($button['example'] ?? '')];
            }

            return $result;
        }

        if ($type === 'PHONE_NUMBER') {
            return [
                'type' => 'PHONE_NUMBER',
                'text' => $text,
                'phone_number' => ($button['country'] ?? '') . ($button['phone_number'] ?? ''),
            ];
        }

        return ['type' => 'QUICK_REPLY', 'text' => $text];
    }

    private static function sendButton($button, int $index, callable $resolve): ?array
    {
        $type = strtoupper((string) ($button->type ?? ''));
        $parameter = ((array) ($button->parameters ?? []))[0] ?? null;

        if ($parameter === null) {
            return null;
        }

        if ($type === 'QUICK_REPLY') {
            // الحمولة اختيارية: ثابتة فارغة تعني «لا تُرسل»، وعندها تعيد Meta
            // نصّ الزرّ نفسه في الـ webhook. ولا نمرّرها إلى $resolve لأنه يملأ
            // الفارغ برقم الهاتف.
            $selection = $parameter->selection ?? $parameter->type ?? 'static';
            if ($selection === 'static' && trim((string) ($parameter->value ?? '')) === '') {
                return null;
            }

            return [
                'type' => 'button',
                'sub_type' => 'quick_reply',
                'index' => $index,
                'parameters' => [['type' => 'payload', 'payload' => (string) $resolve($parameter)]],
            ];
        }

        if ($type === 'URL') {
            return [
                'type' => 'button',
                'sub_type' => 'url',
                'index' => $index,
                'parameters' => [['type' => 'text', 'text' => (string) $resolve($parameter)]],
            ];
        }

        return null;
    }
}
