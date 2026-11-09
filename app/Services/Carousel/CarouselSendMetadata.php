<?php

namespace App\Services\Carousel;

use App\Models\Template;
use App\Services\CampaignMediaHistoryService;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * من طلب الإرسال (حملة أو محادثة) إلى مفتاح carousel في metadata.
 *
 * النصوص وتسميات الأزرار وعدد المتغيّرات تأتي من تعريف القالب لا من
 * المتصفح: المتصفح يختار الوسائط ويملأ القيم فقط. فلا يُرسَل إلى Meta
 * عدد بطاقات أو أزرار لا يطابق القالب المعتمد.
 */
class CarouselSendMetadata
{
    public function __construct(
        private CarouselMediaStorer $storer,
        private CampaignMediaHistoryService $history,
    ) {
    }

    /** يرفض طلباً لا يطابق القالب، قبل أي حفظ أو رفع. */
    public function assertMatches($carouselInput, array $definition, Template $template): void
    {
        $cards = is_array($carouselInput) && is_array($carouselInput['cards'] ?? null)
            ? array_values($carouselInput['cards'])
            : [];

        if ($cards === []) {
            throw ValidationException::withMessages([
                'carousel' => __('This is a carousel template. Choose the media for every card.'),
            ]);
        }

        $format = strtoupper((string) ($carouselInput['format'] ?? $definition['format']));

        if (count($cards) !== count($definition['cards']) || $format !== $definition['format']) {
            throw ValidationException::withMessages([
                'carousel' => __('The cards do not match the template. Reload the page and try again.'),
            ]);
        }

        $stored = self::storedMedia($template);
        $errors = [];

        foreach ($definition['cards'] as $i => $definitionCard) {
            $card = is_array($cards[$i]) ? $cards[$i] : [];
            $number = ['number' => $i + 1];
            $selection = $card['header']['selection'] ?? null;

            if ($selection === 'default' && empty($stored[$i]['url'])) {
                $errors["carousel.cards.$i.header.value"] = __('Card :number: this template has no saved media. Upload an image or video.', $number);
            } elseif ($selection === 'upload' && !($card['header']['value'] ?? null) instanceof UploadedFile) {
                $errors["carousel.cards.$i.header.value"] = __('Card :number: choose an image or video.', $number);
            }

            for ($k = 0; $k < ($definitionCard['body']['variables'] ?? 0); $k++) {
                if (trim((string) ($card['body']['parameters'][$k]['value'] ?? '')) === '') {
                    $errors["carousel.cards.$i.body.parameters.$k.value"] = __('Card :number: fill in every variable.', $number);
                }
            }

            foreach ($definitionCard['buttons'] as $j => $button) {
                if ($button['type'] === 'URL' && $button['has_variable']
                    && trim((string) ($card['buttons'][$j]['parameters'][0]['value'] ?? '')) === '') {
                    $errors["carousel.cards.$i.buttons.$j.parameters.0.value"] = __('Card :number: fill in the button link value.', $number);
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * يحلّ وسائط كل بطاقة (رفع/افتراضي/سابق) ويبني مفتاح carousel.
     *
     * @return array{format: string, cards: array}
     */
    public function build($carouselInput, array $definition, Template $template, int $organizationId, ?int $userId, bool $recordHistory): array
    {
        $cards = array_values($carouselInput['cards'] ?? []);
        $stored = self::storedMedia($template);
        $result = [];

        foreach ($definition['cards'] as $i => $definitionCard) {
            $card = is_array($cards[$i] ?? null) ? $cards[$i] : [];

            $body = null;
            if ($definitionCard['body'] !== null) {
                $parameters = [];
                for ($k = 0; $k < $definitionCard['body']['variables']; $k++) {
                    $parameters[] = ['type' => 'text'] + self::parameter($card['body']['parameters'][$k] ?? []);
                }
                $body = ['text' => $definitionCard['body']['text'], 'parameters' => $parameters];
            }

            $buttons = [];
            foreach ($definitionCard['buttons'] as $j => $button) {
                $sendsParameter = $button['type'] === 'QUICK_REPLY'
                    || ($button['type'] === 'URL' && $button['has_variable']);

                $buttons[] = [
                    'index' => $j,
                    'type' => $button['type'],
                    'text' => $button['text'],
                    'url' => $button['url'],
                    'parameters' => $sendsParameter
                        ? [self::parameter($card['buttons'][$j]['parameters'][0] ?? [])]
                        : [],
                ];
            }

            $result[] = [
                'card_index' => $i,
                'header' => $this->header($card['header'] ?? [], $stored[$i] ?? null, $definition['format'], $organizationId, $userId, $recordHistory, $i),
                'body' => $body,
                'buttons' => $buttons,
            ];
        }

        return ['format' => $definition['format'], 'cards' => $result];
    }

    /** نسخنا من وسائط بطاقات القالب (templates.carousel_media)، بالترتيب. */
    public static function storedMedia(Template $template): array
    {
        $raw = $template->getAttribute('carousel_media');
        $decoded = is_string($raw) ? json_decode($raw, true) : (is_array($raw) ? $raw : null);

        return is_array($decoded['cards'] ?? null) ? array_values($decoded['cards']) : [];
    }

    private static function parameter($parameter): array
    {
        $parameter = is_array($parameter) ? $parameter : [];
        $selection = ($parameter['selection'] ?? $parameter['type'] ?? 'static') === 'dynamic' ? 'dynamic' : 'static';

        return ['selection' => $selection, 'value' => (string) ($parameter['value'] ?? '')];
    }

    private function header(array $header, ?array $stored, string $format, int $organizationId, ?int $userId, bool $recordHistory, int $index): array
    {
        $selection = $header['selection'] ?? null;
        $value = $header['value'] ?? null;

        if ($selection === 'upload' && $value instanceof UploadedFile) {
            $media = $this->storer->store($value, $organizationId, $userId, $format, $recordHistory);

            return ['type' => $format, 'selection' => 'upload', 'value' => $media['url'], 'chat_media_id' => $media['chat_media_id']];
        }

        if ($selection === 'history') {
            $item = $this->history->findByReferenceForOrganization($organizationId, (string) $value);

            if (!$item) {
                throw ValidationException::withMessages([
                    "carousel.cards.$index.header.value" => __('The selected media file is no longer available.'),
                ]);
            }

            return ['type' => $format, 'selection' => 'history', 'value' => $item->path, 'chat_media_id' => $item->chat_media_id];
        }

        if ($selection === 'default' && !empty($stored['url'])) {
            return ['type' => $format, 'selection' => 'default', 'value' => $stored['url'], 'chat_media_id' => $stored['chat_media_id'] ?? null];
        }

        throw ValidationException::withMessages([
            "carousel.cards.$index.header.value" => __('Card :number: choose an image or video.', ['number' => $index + 1]),
        ]);
    }
}
