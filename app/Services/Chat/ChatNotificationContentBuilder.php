<?php

namespace App\Services\Chat;

use Illuminate\Contracts\Translation\Translator;
use Illuminate\Support\Facades\Lang;

/**
 * Builds the title / body of the WhatsApp-style mobile push notification
 * for a new chat. The output mirrors what WhatsApp shows:
 *
 *  - Title: contact's full name, or phone number if name is missing.
 *  - Body : the text body of the message, or `<emoji> <type-label>:<caption>`
 *           for media (e.g. "📷 Photo: nice view").
 *
 * Translations are looked up explicitly via {@see Lang::get()} for the
 * requested locale instead of toggling the application's global locale,
 * which is unsafe inside queue workers handling multiple jobs.
 */
class ChatNotificationContentBuilder
{
    private const MAX_TITLE_NAME_BYTES = 160;
    private const MAX_TITLE_PHONE_BYTES = 80;
    private const MAX_BODY_BYTES = 1000;
    private const MAX_CAPTION_BYTES = 500;

    public function __construct(private ChatBroadcastPayloadBuilder $payloadBuilder)
    {
    }

    /**
     * Title shown in the notification tray. Names are not localised, so the
     * same string is used for every locale.
     */
    public function buildTitle(array $value): string
    {
        $name = trim((string) ($value['contact_full_name'] ?? ''));
        if ($name !== '') {
            return $this->payloadBuilder->truncateToBytes($name, self::MAX_TITLE_NAME_BYTES);
        }

        $phone = trim((string) ($value['formatted_phone_number'] ?? $value['phone'] ?? ''));
        if ($phone !== '') {
            return $this->payloadBuilder->truncateToBytes($phone, self::MAX_TITLE_PHONE_BYTES);
        }

        return $this->translate('Unknown', $this->fallbackLocale());
    }

    /**
     * Body of the notification, localised to the given app locale.
     */
    public function buildBody(array $value, string $locale): string
    {
        $metaRaw = $value['metadata'] ?? null;
        $metadata = is_array($metaRaw)
            ? $metaRaw
            : $this->decodeJson(is_string($metaRaw) ? $metaRaw : null);
        $type = $metadata['type'] ?? null;

        if (!$type && !empty($value['media']) && is_array($value['media'])) {
            return $this->fromMediaMime($value['media'], $metadata, $locale);
        }

        switch ($type) {
            case 'text':
                $body = trim((string) ($metadata['text']['body'] ?? ''));
                return $body !== ''
                    ? $this->payloadBuilder->truncateToBytes($body, self::MAX_BODY_BYTES)
                    : $this->translate('Message', $locale);

            case 'button':
                $body = trim((string) (
                    ($metadata['button']['text'] ?? null)
                    ?? ($metadata['button']['payload'] ?? '')
                ));
                return $body !== ''
                    ? $this->payloadBuilder->truncateToBytes($body, self::MAX_BODY_BYTES)
                    : $this->translate('Message', $locale);

            case 'interactive':
                return $this->bodyFromInteractive($metadata['interactive'] ?? [], $locale);

            case 'image':
            case 'video':
            case 'audio':
            case 'document':
            case 'sticker':
            case 'location':
            case 'contacts':
                return $this->mediaStyleLine($type, $metadata, $locale);

            default:
                if (!empty($metadata['text']['body'])) {
                    return $this->payloadBuilder->truncateToBytes(
                        (string) $metadata['text']['body'],
                        self::MAX_BODY_BYTES
                    );
                }
                if (!empty($value['media']) && is_array($value['media'])) {
                    return $this->fromMediaMime($value['media'], $metadata, $locale);
                }
                return $this->translate('Message', $locale);
        }
    }

    private function bodyFromInteractive(array $interactive, string $locale): string
    {
        $interactiveType = $interactive['type'] ?? null;

        if ($interactiveType === 'button_reply') {
            $body = trim((string) ($interactive['button_reply']['title'] ?? ''));
            return $body !== ''
                ? $this->payloadBuilder->truncateToBytes($body, self::MAX_BODY_BYTES)
                : $this->mediaStyleLine('interactive', [], $locale);
        }

        if ($interactiveType === 'list_reply') {
            $title = trim((string) ($interactive['list_reply']['title'] ?? ''));
            $description = trim((string) ($interactive['list_reply']['description'] ?? ''));
            $line = $title !== '' && $description !== ''
                ? $title . ' — ' . $description
                : ($title !== '' ? $title : $description);

            return $line !== ''
                ? $this->payloadBuilder->truncateToBytes($line, self::MAX_BODY_BYTES)
                : $this->mediaStyleLine('interactive', [], $locale);
        }

        return $this->mediaStyleLine('interactive', [], $locale);
    }

    private function mediaStyleLine(string $type, array $metadata, string $locale): string
    {
        $label = $this->mediaLabelWithEmoji($type, $metadata, $locale);
        $caption = trim($this->extractCaptionForType($type, $metadata));

        if ($caption !== '') {
            return $label . ': ' . $this->payloadBuilder->truncateToBytes($caption, self::MAX_CAPTION_BYTES);
        }

        return $label;
    }

    private function extractCaptionForType(string $type, array $metadata): string
    {
        return match ($type) {
            'image'    => (string) ($metadata['image']['caption'] ?? ''),
            'video'    => (string) ($metadata['video']['caption'] ?? ''),
            'document' => (string) (
                ($metadata['document']['filename'] ?? '')
                ?: ($metadata['document']['caption'] ?? '')
            ),
            default    => '',
        };
    }

    private function mediaLabelWithEmoji(string $type, array $metadata, string $locale): string
    {
        return match ($type) {
            'image'       => '📷 ' . $this->translate('Photo', $locale),
            'video'       => '🎥 ' . $this->translate('Video', $locale),
            'audio'       => !empty($metadata['audio']['voice'])
                ? '🎤 ' . $this->translate('Voice message', $locale)
                : '🎵 ' . $this->translate('Audio', $locale),
            'document'    => '📄 ' . $this->translate('File', $locale),
            'sticker'     => '🎨 ' . $this->translate('Sticker', $locale),
            'location'    => '📍 ' . $this->translate('Location', $locale),
            'contacts'    => '👤 ' . $this->translate('Contact', $locale),
            'interactive' => '💬 ' . $this->translate('Message', $locale),
            default       => '💬 ' . $this->translate('Message', $locale),
        };
    }

    /**
     * @param  array<string, mixed>  $media
     * @param  array<string, mixed>  $metadata
     */
    private function fromMediaMime(array $media, array $metadata, string $locale): string
    {
        $mime = strtolower((string) ($media['type'] ?? ''));
        if (str_starts_with($mime, 'image/')) {
            return $this->mediaStyleLine('image', $metadata, $locale);
        }
        if (str_starts_with($mime, 'video/')) {
            return $this->mediaStyleLine('video', $metadata, $locale);
        }
        if (str_starts_with($mime, 'audio/')) {
            return $this->mediaStyleLine('audio', $metadata, $locale);
        }
        if (str_starts_with($mime, 'application/') || str_contains($mime, 'pdf')) {
            return $this->mediaStyleLine('document', $metadata, $locale);
        }

        return '📎 ' . $this->translate('File', $locale);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(?string $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Resolve a translation for the given locale without touching the global
     * application locale.
     */
    private function translate(string $key, string $locale): string
    {
        /** @var Translator $translator */
        $translator = Lang::getFacadeRoot();
        $translation = $translator->get($key, [], $locale);

        return is_string($translation) ? $translation : $key;
    }

    private function fallbackLocale(): string
    {
        try {
            return (string) app()->getLocale();
        } catch (\Throwable $e) {
            return 'en';
        }
    }
}
