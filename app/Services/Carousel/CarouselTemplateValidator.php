<?php

namespace App\Services\Carousel;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\MessageBag;
use Illuminate\Validation\Rule;

/**
 * قواعد Meta لقالب الـ carousel، تُفحص كلها قبل أي طلب إلى Meta.
 *
 * رفض Meta يأتي بعد رفع كل صور البطاقات، وبرسالة عامّة لا تقول أيّ بطاقة
 * أخطأت. فما نستطيع فحصه هنا نفحصه هنا.
 */
final class CarouselTemplateValidator
{
    /** يوحّد حالة أنواع الأزرار والصيغة قبل التحقّق والبناء. */
    public static function normalize(array $input): array
    {
        if (isset($input['category'])) {
            $input['category'] = strtoupper((string) $input['category']);
        }

        if (isset($input['carousel']['format'])) {
            $input['carousel']['format'] = strtoupper((string) $input['carousel']['format']);
        }

        foreach (($input['carousel']['cards'] ?? []) as $i => $card) {
            foreach (($card['buttons'] ?? []) as $j => $button) {
                if (is_array($button) && isset($button['type'])) {
                    $input['carousel']['cards'][$i]['buttons'][$j]['type'] = strtoupper((string) $button['type']);
                }
            }
        }

        return $input;
    }

    public function validate(array $input): MessageBag
    {
        $format = $input['carousel']['format'] ?? null;
        $mediaRule = $format === 'VIDEO'
            ? ['required', 'file', 'mimes:mp4', 'max:16384']
            : ['required', 'image', 'mimes:png,jpg,jpeg', 'max:5120'];

        $rules = [
            'name' => ['required', 'string', 'max:512', 'regex:/^[a-z0-9_]+$/'],
            'language' => ['required', 'string'],
            'category' => ['required', Rule::in(['MARKETING'])],
            'body.text' => ['required', 'string', 'max:' . CarouselDefinition::BODY_MAX],
            'carousel' => ['required', 'array'],
            'carousel.format' => ['required', Rule::in(CarouselDefinition::FORMATS)],
            'carousel.cards' => ['required', 'array', 'min:' . CarouselDefinition::MIN_CARDS, 'max:' . CarouselDefinition::MAX_CARDS],
            'carousel.cards.*.header.example' => $mediaRule,
            'carousel.cards.*.body.text' => ['nullable', 'string', 'max:' . CarouselDefinition::CARD_BODY_MAX],
            'carousel.cards.*.buttons' => ['nullable', 'array', 'max:' . CarouselDefinition::MAX_BUTTONS],
            'carousel.cards.*.buttons.*.type' => ['required', Rule::in(CarouselDefinition::BUTTON_TYPES)],
            'carousel.cards.*.buttons.*.text' => ['required', 'string', 'max:' . CarouselDefinition::BUTTON_TEXT_MAX],
        ];

        $messages = [
            'name.regex' => __('The template name may only contain lowercase letters, numbers and underscores.'),
            'category.in' => __('Carousel templates are available for the Marketing category only.'),
            'body.text.required' => __('The message body is required.'),
            'body.text.max' => __('The message body must not exceed :max characters.'),
            'carousel.cards.required' => __('Add between 2 and 10 cards.'),
            'carousel.cards.min' => __('Add between 2 and 10 cards.'),
            'carousel.cards.max' => __('Add between 2 and 10 cards.'),
            'carousel.cards.*.header.example.required' => __('Every card needs an image or a video.'),
            'carousel.cards.*.header.example.image' => __('Card media must be a PNG or JPG image of up to 5MB.'),
            'carousel.cards.*.header.example.mimes' => $format === 'VIDEO'
                ? __('Card media must be an MP4 video of up to 16MB.')
                : __('Card media must be a PNG or JPG image of up to 5MB.'),
            'carousel.cards.*.header.example.max' => $format === 'VIDEO'
                ? __('Card media must be an MP4 video of up to 16MB.')
                : __('Card media must be a PNG or JPG image of up to 5MB.'),
            'carousel.cards.*.body.text.max' => __('Card text must not exceed :max characters.'),
            'carousel.cards.*.buttons.max' => __('A card can have at most 2 buttons.'),
            'carousel.cards.*.buttons.*.text.required' => __('Every button needs a label.'),
            'carousel.cards.*.buttons.*.text.max' => __('Button labels must not exceed :max characters.'),
        ];

        $validator = Validator::make($input, $rules, $messages);

        $validator->after(function ($validator) use ($input) {
            $this->checkBody($validator, $input);
            $this->checkCards($validator, $input);
        });

        return $validator->errors();
    }

    private function checkBody($validator, array $input): void
    {
        $text = (string) ($input['body']['text'] ?? '');
        $this->checkVariables($validator, 'body', $text, $input['body']['example'] ?? []);
    }

    private function checkCards($validator, array $input): void
    {
        $cards = $input['carousel']['cards'] ?? null;

        if (!is_array($cards) || count($cards) < CarouselDefinition::MIN_CARDS) {
            return;
        }

        $cards = array_values($cards);
        $withBody = 0;
        $signature = null;

        foreach ($cards as $i => $card) {
            $number = $i + 1;
            $cardText = trim((string) ($card['body']['text'] ?? ''));

            if ($cardText !== '') {
                $withBody++;
                $this->checkVariables($validator, "carousel.cards.$i.body", $cardText, $card['body']['example'] ?? []);
            }

            $buttons = array_values(is_array($card['buttons'] ?? null) ? $card['buttons'] : []);
            $types = array_map(fn ($button) => strtoupper((string) ($button['type'] ?? '')), $buttons);

            if ($signature === null) {
                $signature = $types;
            } elseif ($types !== $signature) {
                $validator->errors()->add(
                    "carousel.cards.$i.buttons",
                    __('Card :number must have the same buttons, in the same order, as the first card.', ['number' => $number])
                );
            }

            foreach ($buttons as $j => $button) {
                $this->checkButton($validator, "carousel.cards.$i.buttons.$j", $button);
            }
        }

        if ($withBody !== 0 && $withBody !== count($cards)) {
            $validator->errors()->add(
                'carousel.cards',
                __('Either every card has text or none of them does.')
            );
        }
    }

    private function checkButton($validator, string $key, $button): void
    {
        if (!is_array($button)) {
            return;
        }

        $type = strtoupper((string) ($button['type'] ?? ''));

        if ($type === 'URL') {
            $url = trim((string) ($button['url'] ?? ''));
            $variables = CarouselDefinition::countVariables($url);
            $plain = preg_replace('/\{\{\s*\d+\s*\}\}/', 'x', $url);

            if ($url === '' || !filter_var($plain, FILTER_VALIDATE_URL) || mb_strlen($url) > 2000) {
                $validator->errors()->add("$key.url", __('Enter a valid website address for the button.'));

                return;
            }

            if ($variables > 0) {
                if ($variables > 1 || !preg_match('/\{\{\s*1\s*\}\}$/', $url)) {
                    $validator->errors()->add("$key.url", __('A button link can have one variable, and only at the end.'));
                } elseif (trim((string) ($button['example'] ?? '')) === '') {
                    $validator->errors()->add("$key.example", __('Add a sample value for the link variable.'));
                }
            }
        }

        if ($type === 'PHONE_NUMBER') {
            $phone = ($button['country'] ?? '') . ($button['phone_number'] ?? '');

            if (!preg_match('/^\+?\d{6,20}$/', (string) $phone)) {
                $validator->errors()->add("$key.phone_number", __('Enter a valid phone number for the button.'));
            }
        }
    }

    /** المتغيّرات {{1}}..{{n}} متتالية، ولكلّ منها مثال غير فارغ. */
    private function checkVariables($validator, string $key, string $text, $examples): void
    {
        preg_match_all('/\{\{\s*(\d+)\s*\}\}/', $text, $matches);
        $numbers = array_values(array_unique(array_map('intval', $matches[1])));
        sort($numbers);

        if ($numbers === []) {
            return;
        }

        if ($numbers !== range(1, count($numbers))) {
            $validator->errors()->add("$key.text", __('Variables must be numbered in order, starting from 1.'));

            return;
        }

        $examples = is_array($examples) ? array_values($examples) : [];

        foreach ($numbers as $number) {
            if (trim((string) ($examples[$number - 1] ?? '')) === '') {
                $validator->errors()->add("$key.example", __('Add a sample value for every variable.'));

                return;
            }
        }
    }

    /** الملف المرفوع لبطاقة، إن وُجد. */
    public static function cardFile(array $input, int $index): ?UploadedFile
    {
        $file = $input['carousel']['cards'][$index]['header']['example'] ?? null;

        return $file instanceof UploadedFile ? $file : null;
    }
}
