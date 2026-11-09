<?php

namespace App\Services\Carousel;

/**
 * قواعد إرسال قالب carousel (حملة أو محادثة)، من مُدخل الطلب وحده.
 *
 * بلا قاعدة بيانات عمداً: StoreCampaign::rules() تُستدعى في اختبارات بلا
 * قاعدة. ومطابقة الطلب للقالب نفسه في CarouselSendMetadata::assertMatches.
 */
final class CarouselSendRules
{
    public static function rules(array $carousel): array
    {
        $format = strtoupper((string) ($carousel['format'] ?? 'IMAGE'));
        $rules = [
            'carousel' => ['array'],
            'carousel.cards' => ['required', 'array', 'min:' . CarouselDefinition::MIN_CARDS, 'max:' . CarouselDefinition::MAX_CARDS],
        ];

        $cards = is_array($carousel['cards'] ?? null) ? $carousel['cards'] : [];

        foreach ($cards as $i => $card) {
            $card = is_array($card) ? $card : [];
            $selection = $card['header']['selection'] ?? null;

            $rules["carousel.cards.$i.header.selection"] = ['required', 'in:upload,default,history'];

            if ($selection === 'upload') {
                $rules["carousel.cards.$i.header.value"] = $format === 'VIDEO'
                    ? ['required', 'file', 'mimes:mp4', 'max:16384']
                    : ['required', 'image', 'mimes:png,jpg,jpeg', 'max:5120'];
            } elseif ($selection === 'history') {
                $rules["carousel.cards.$i.header.value"] = ['required', 'string', 'max:2048'];
            }

            foreach (array_keys(is_array($card['body']['parameters'] ?? null) ? $card['body']['parameters'] : []) as $j) {
                $rules["carousel.cards.$i.body.parameters.$j.value"] = ['required', 'string', 'max:' . CarouselDefinition::CARD_BODY_MAX];
            }

            foreach ((is_array($card['buttons'] ?? null) ? $card['buttons'] : []) as $j => $button) {
                $type = strtoupper((string) ($button['type'] ?? ''));

                if (empty($button['parameters'])) {
                    continue;
                }

                if ($type === 'URL') {
                    $rules["carousel.cards.$i.buttons.$j.parameters.0.value"] = ['required', 'string', 'max:2000'];
                } elseif ($type === 'QUICK_REPLY') {
                    $rules["carousel.cards.$i.buttons.$j.parameters.0.value"] = ['nullable', 'string', 'max:256'];
                }
            }
        }

        return $rules;
    }

    public static function messages(array $carousel): array
    {
        $format = strtoupper((string) ($carousel['format'] ?? 'IMAGE'));
        $messages = [
            'carousel.cards.required' => __('Choose the media for every card.'),
            'carousel.cards.min' => __('Add between 2 and 10 cards.'),
            'carousel.cards.max' => __('Add between 2 and 10 cards.'),
        ];

        $cards = is_array($carousel['cards'] ?? null) ? $carousel['cards'] : [];

        foreach (array_keys($cards) as $i) {
            $number = ['number' => (int) $i + 1];
            $mediaMessage = $format === 'VIDEO'
                ? __('Card :number: the video must be an MP4 of up to 16MB.', $number)
                : __('Card :number: the image must be a PNG or JPG of up to 5MB.', $number);

            $messages["carousel.cards.$i.header.selection.required"] = __('Card :number: choose an image or video.', $number);
            $messages["carousel.cards.$i.header.selection.in"] = __('Card :number: choose an image or video.', $number);
            $messages["carousel.cards.$i.header.value.required"] = __('Card :number: choose an image or video.', $number);
            $messages["carousel.cards.$i.header.value.image"] = $mediaMessage;
            $messages["carousel.cards.$i.header.value.file"] = $mediaMessage;
            $messages["carousel.cards.$i.header.value.mimes"] = $mediaMessage;
            $messages["carousel.cards.$i.header.value.max"] = $mediaMessage;
            $messages["carousel.cards.$i.body.parameters.*.value.required"] = __('Card :number: fill in every variable.', $number);
            $messages["carousel.cards.$i.body.parameters.*.value.max"] = __('Card :number: a variable value is too long.', $number);
            $messages["carousel.cards.$i.buttons.*.parameters.0.value.required"] = __('Card :number: fill in the button link value.', $number);
            $messages["carousel.cards.$i.buttons.*.parameters.0.value.max"] = __('Card :number: the button value is too long.', $number);
        }

        return $messages;
    }
}
