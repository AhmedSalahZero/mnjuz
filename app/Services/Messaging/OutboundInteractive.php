<?php

namespace App\Services\Messaging;

use App\Services\WebChat\WebChatMessagePresenter;

/**
 * رسالة بأزرار أو قائمة أو زرّ رابط — مستقلّة عن المنصّة.
 *
 * تُبنى من نفس معاملات WhatsappService::sendMessage التي يمرّرها الـ flow
 * والتقييم، فلا يتغيّر عندهما شيء. وتُحفظ في chats.metadata بشكلين:
 *
 *  - text.body: ما يقرؤه من لا يرى الأزرار (الموظّف، والجوال، وسجلّ المساعد
 *    الذكي) — بنفس صيغة واتساب، وزرّ الرابط يُلحق «النصّ: الرابط».
 *  - interactive_options: ما يرسمه الـ widget، ومنه يُقرأ عنوان الخيار حين
 *    يضغطه الزائر (WebChatInboundService::resolveReply).
 */
final class OutboundInteractive
{
    public const BUTTONS = 'buttons';
    public const LIST = 'list';
    public const CTA_URL = 'cta_url';

    /** أنواع WhatsappService كما يكتبها المُستدعون. */
    private const WHATSAPP_TYPES = [
        'interactive buttons' => self::BUTTONS,
        'interactive list' => self::LIST,
        'interactive call to action url' => self::CTA_URL,
    ];

    /**
     * @param  array<int, array{id: string, title: string}>  $buttons
     * @param  array<int, array{title: string, rows: array<int, array{id: string, title: string, description?: string}>}>  $sections
     * @param  array{type: string, text?: string, url?: string}|null  $header
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $body,
        public readonly array $buttons = [],
        public readonly ?string $button = null,
        public readonly array $sections = [],
        public readonly ?string $displayText = null,
        public readonly ?string $url = null,
        public readonly ?array $header = null,
        public readonly ?string $footer = null,
    ) {
    }

    public static function isInteractiveType(?string $type): bool
    {
        return isset(self::WHATSAPP_TYPES[(string) $type]);
    }

    /**
     * من معاملات WhatsappService::sendMessage. null إن لم يكن النوع تفاعلياً
     * أو لم يبقَ فيه ما يُضغط.
     */
    public static function fromWhatsappArgs(string $type, string $body, $buttons, $header, $footer, $buttonLabel): ?self
    {
        $kind = self::WHATSAPP_TYPES[$type] ?? null;

        if ($kind === null) {
            return null;
        }

        $buttons = is_array($buttons) ? $buttons : [];
        $footer = trim((string) $footer) !== '' ? trim((string) $footer) : null;
        $header = self::header($header);

        if ($kind === self::CTA_URL) {
            $url = WebChatMessagePresenter::safeUrl($buttons['url'] ?? null);

            return $url === null ? null : new self(
                kind: $kind,
                body: $body,
                displayText: trim((string) ($buttons['display_text'] ?? '')),
                url: $url,
                header: $header,
                footer: $footer,
            );
        }

        if ($kind === self::BUTTONS) {
            $rows = [];
            foreach ($buttons as $button) {
                if (isset($button['id'], $button['title']) && trim((string) $button['title']) !== '') {
                    $rows[] = ['id' => (string) $button['id'], 'title' => (string) $button['title']];
                }
            }

            return $rows === [] ? null : new self(kind: $kind, body: $body, buttons: $rows, header: $header, footer: $footer);
        }

        $sections = [];
        foreach ($buttons as $section) {
            $rows = [];
            foreach ((array) ($section['rows'] ?? []) as $row) {
                if (!isset($row['id'], $row['title']) || trim((string) $row['title']) === '') {
                    continue;
                }

                $rows[] = array_filter([
                    'id' => (string) $row['id'],
                    'title' => (string) $row['title'],
                    'description' => isset($row['description']) && $row['description'] !== '' ? (string) $row['description'] : null,
                ], static fn ($v) => $v !== null);
            }

            if ($rows !== []) {
                $sections[] = ['title' => (string) ($section['title'] ?? ''), 'rows' => $rows];
            }
        }

        return $sections === [] ? null : new self(
            kind: $kind,
            body: $body,
            button: trim((string) $buttonLabel) !== '' ? trim((string) $buttonLabel) : null,
            sections: $sections,
            header: $header,
            footer: $footer,
        );
    }

    /**
     * رأس الرسالة كما يرسمه الـ widget: نصّ، أو وسائط برابط http(s) وحده.
     * يُقبل شكل واتساب ({type:image, image:{link}}) أو الشكل المسطّح.
     */
    private static function header($header): ?array
    {
        if (!is_array($header) || !isset($header['type'])) {
            return null;
        }

        $type = (string) $header['type'];

        if ($type === 'text') {
            $text = trim((string) ($header['text'] ?? ''));

            return $text === '' ? null : ['type' => 'text', 'text' => $text];
        }

        if (!in_array($type, ['image', 'video', 'document'], true)) {
            return null;
        }

        $url = WebChatMessagePresenter::safeUrl($header[$type]['link'] ?? $header['url'] ?? null);

        return $url === null ? null : ['type' => $type, 'url' => $url];
    }

    /** ما يُخزَّن في interactive_options. */
    public function toOptions(): array
    {
        $options = ['kind' => $this->kind];

        if ($this->header !== null) {
            $options['header'] = $this->header;
        }

        if ($this->footer !== null) {
            $options['footer'] = $this->footer;
        }

        return $options + match ($this->kind) {
            self::BUTTONS => ['buttons' => $this->buttons],
            self::LIST => ['button' => (string) $this->button, 'sections' => $this->sections],
            self::CTA_URL => ['display_text' => (string) $this->displayText, 'url' => (string) $this->url],
        };
    }

    /** صفّ chats.metadata: نصّ قابل للقراءة + ما يرسمه الـ widget. */
    public function toMetadata(): array
    {
        $metadata = $this->kind === self::CTA_URL
            ? \App\Services\WhatsappService::ctaUrlMetadata($this->body, ['display_text' => $this->displayText, 'url' => $this->url])
            : ['type' => 'text', 'text' => ['body' => $this->body]];

        $metadata['interactive_options'] = $this->toOptions();

        return $metadata;
    }

    /**
     * الرسالة نصّاً لقناة لا أزرار فيها. كل خيار في سطر، والعميل يكتب
     * عنوانه فيطابقه الـ flow كما يطابق ضغطة الزرّ (بالعنوان لا بالمعرّف).
     */
    public function fallbackText(): string
    {
        $lines = [];

        if (($this->header['type'] ?? null) === 'text') {
            $lines[] = $this->header['text'];
            $lines[] = '';
        }

        $lines[] = $this->body;

        $options = match ($this->kind) {
            self::BUTTONS => array_column($this->buttons, 'title'),
            self::LIST => array_merge(...array_map(static fn ($s) => array_column($s['rows'], 'title'), $this->sections ?: [['rows' => []]])),
            self::CTA_URL => [],
        };

        if ($options !== []) {
            $lines[] = '';
            foreach ($options as $title) {
                $lines[] = '• ' . $title;
            }
        }

        if ($this->kind === self::CTA_URL) {
            $lines[] = '';
            $lines[] = ($this->displayText ? $this->displayText . ': ' : '') . $this->url;
        }

        if ($this->footer !== null) {
            $lines[] = '';
            $lines[] = $this->footer;
        }

        return trim(implode("\n", $lines));
    }
}
