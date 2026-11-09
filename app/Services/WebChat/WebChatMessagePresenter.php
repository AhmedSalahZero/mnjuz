<?php

namespace App\Services\WebChat;

use App\Models\Chat;
use App\Models\WebChatVisitor;
use Illuminate\Support\Carbon;

/**
 * صفّ chats كما يراه الزائر — والشكل الوحيد الذي يخرج إليه.
 *
 * يُبنى من جدول chats وحده: الملاحظات الداخلية وسجلّ التذكرة في chat_logs
 * لا تصل الزائر أبداً. ولا يخرج من الصفّ إلا ما يُعرض: لا معرّف المنصّة،
 * ولا معرّف الموظّف، ولا الـ metadata الخام.
 */
class WebChatMessagePresenter
{
    /** أنواع تُعرض للزائر. ما عداها (system، reaction، edit…) داخلي. */
    private const VISIBLE_TYPES = ['text', 'image', 'video', 'audio', 'document', 'interactive', 'button', 'sticker'];

    public function __construct(private readonly array $settings)
    {
    }

    public static function forVisitor(WebChatVisitor $visitor): self
    {
        return new self(WebChatSettings::for($visitor->account));
    }

    public function present(Chat $chat): ?array
    {
        $metadata = json_decode((string) $chat->metadata, true);

        if (!is_array($metadata)) {
            return null;
        }

        $type = (string) ($metadata['type'] ?? 'text');

        if (!in_array($type, self::VISIBLE_TYPES, true) || !in_array($chat->type, ['inbound', 'outbound'], true)) {
            return null;
        }

        $inbound = $chat->type === 'inbound';

        return [
            'id' => (string) $chat->uuid,
            'seq' => (int) $chat->id,
            'client_id' => $inbound ? self::clientIdFrom((string) $chat->wam_id) : null,
            'direction' => $inbound ? 'in' : 'out',
            'type' => $this->displayType($type, $metadata),
            'text' => $this->visibleText($this->text($type, $metadata), $inbound ? null : $metadata),
            'media' => $this->media($chat, $type, $metadata),
            'interactive' => $inbound ? null : $this->interactive($metadata),
            'status' => (string) $chat->status,
            'created_at' => self::isoTime($chat),
            'sender' => $this->sender($chat, $inbound),
        ];
    }

    /** @param iterable<Chat> $chats */
    public function presentMany(iterable $chats): array
    {
        $out = [];

        foreach ($chats as $chat) {
            $row = $this->present($chat);

            if ($row !== null) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * معرّف العميل من wam_id الوارد: «wc:{key}:{visitor}:c{client}». يسمح
     * للـ widget بأن يستبدل فقاعته المتفائلة بالصفّ المحفوظ.
     */
    public static function clientIdFrom(string $wamId): ?string
    {
        $last = strrchr($wamId, ':');

        if ($last === false || !str_starts_with($last, ':c')) {
            return null;
        }

        $id = substr($last, 2);

        return preg_match('/^[0-9a-f-]{36}$/', $id) ? $id : null;
    }

    private function displayType(string $type, array $metadata): string
    {
        // ردّ الزائر على زرّ يُعرض نصّاً: هو ما ضغطه.
        if ($type === 'interactive' || $type === 'button') {
            return 'text';
        }

        return $type === 'sticker' ? 'image' : $type;
    }

    private function text(string $type, array $metadata): ?string
    {
        $text = match ($type) {
            'text' => $metadata['text']['body'] ?? null,
            'interactive' => $metadata['interactive']['button_reply']['title']
                ?? $metadata['interactive']['list_reply']['title']
                ?? null,
            'button' => $metadata['button']['text'] ?? $metadata['button']['payload'] ?? null,
            default => $metadata[$type]['caption'] ?? null,
        };

        $text = is_string($text) ? $text : null;

        return $text === null || $text === '' ? null : $text;
    }

    /**
     * زرّ الرابط يُحفظ كما في واتساب: المتن ثم «النصّ: الرابط» ليقرأه الموظّف
     * والجوال. الزائر يرى الزرّ نفسه، فتُحذف عنده هذه اللاحقة.
     */
    private function visibleText(?string $text, ?array $metadata): ?string
    {
        $options = $metadata['interactive_options'] ?? null;

        if ($text === null || !is_array($options) || ($options['kind'] ?? null) !== 'cta_url') {
            return $text;
        }

        $label = trim((string) ($options['display_text'] ?? ''));
        $suffix = "\n\n" . ($label !== '' ? $label . ': ' : '') . (string) ($options['url'] ?? '');

        return str_ends_with($text, $suffix) ? substr($text, 0, -strlen($suffix)) : $text;
    }

    private function media(Chat $chat, string $type, array $metadata): ?array
    {
        if (!in_array($type, ['image', 'video', 'audio', 'document', 'sticker'], true)) {
            return null;
        }

        $media = $chat->relationLoaded('media') ? $chat->media : $chat->media()->first();

        // الوارد قبل اكتمال تنزيله: الفقاعة تعرض «جارٍ الرفع» حتى يصل البثّ،
        // أو «تعذّر الرفع» إن يئست مهمّة التنزيل.
        if (!$media) {
            return [
                'url' => null,
                'name' => $metadata[$type]['filename'] ?? null,
                'mime' => $metadata[$type]['mime_type'] ?? null,
                'size' => null,
                'failed' => (bool) ($metadata['media_failed'] ?? false),
            ];
        }

        return [
            'url' => self::publicUrl((string) $media->path),
            'name' => $metadata[$type]['filename'] ?? ($media->name !== 'N/A' ? $media->name : null),
            'mime' => $media->type,
            'size' => $media->size !== null ? (int) $media->size : null,
            'failed' => false,
        ];
    }

    /** رابط الملف كما يفتحه متصفّح الزائر: مطلقٌ دائماً، فالصفحة ليست صفحتنا. */
    public static function publicUrl(string $path): ?string
    {
        $path = trim($path);

        if ($path === '') {
            return null;
        }

        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }

        $path = ltrim($path, '/');

        if (!str_starts_with($path, 'media/')) {
            $path = 'media/' . $path;
        }

        // url(): مضيف الطلب في الـ API، وAPP_URL في الـ queue (البثّ).
        return url($path);
    }

    private function interactive(array $metadata): ?array
    {
        $options = $metadata['interactive_options'] ?? null;

        if (!is_array($options) || !in_array($options['kind'] ?? null, ['buttons', 'list', 'cta_url'], true)) {
            return null;
        }

        $view = match ($options['kind']) {
            'buttons' => [
                'kind' => 'buttons',
                'buttons' => array_values(array_map(
                    static fn ($b) => ['id' => (string) $b['id'], 'title' => (string) $b['title']],
                    array_filter((array) ($options['buttons'] ?? []), static fn ($b) => isset($b['id'], $b['title']))
                )),
            ],
            'list' => [
                'kind' => 'list',
                'button' => (string) ($options['button'] ?? ''),
                'sections' => array_values(array_map(static fn ($s) => [
                    'title' => (string) ($s['title'] ?? ''),
                    'rows' => array_values(array_map(
                        static fn ($r) => array_filter([
                            'id' => (string) $r['id'],
                            'title' => (string) $r['title'],
                            'description' => isset($r['description']) ? (string) $r['description'] : null,
                        ], static fn ($v) => $v !== null),
                        array_filter((array) ($s['rows'] ?? []), static fn ($r) => isset($r['id'], $r['title']))
                    )),
                ], (array) ($options['sections'] ?? []))),
            ],
            'cta_url' => self::safeUrl($options['url'] ?? null) === null ? null : [
                'kind' => 'cta_url',
                'display_text' => (string) ($options['display_text'] ?? ''),
                'url' => self::safeUrl($options['url']),
            ],
        };

        if ($view === null) {
            return null;
        }

        $header = $this->interactiveHeader($options['header'] ?? null);

        if ($header !== null) {
            $view['header'] = $header;
        }

        if (is_string($options['footer'] ?? null) && trim($options['footer']) !== '') {
            $view['footer'] = trim($options['footer']);
        }

        return $view;
    }

    /** رأس الرسالة: نصّ، أو وسائط برابط http(s) وحده. */
    private function interactiveHeader($header): ?array
    {
        if (!is_array($header)) {
            return null;
        }

        if (($header['type'] ?? null) === 'text') {
            $text = trim((string) ($header['text'] ?? ''));

            return $text === '' ? null : ['type' => 'text', 'text' => $text];
        }

        $url = self::safeUrl($header['url'] ?? null);

        return in_array($header['type'] ?? null, ['image', 'video', 'document'], true) && $url !== null
            ? ['type' => $header['type'], 'url' => $url]
            : null;
    }

    /** روابط http(s) وحدها: «javascript:» في زرّ يُنفَّذ في موقع التاجر. */
    public static function safeUrl(mixed $url): ?string
    {
        $url = trim((string) $url);

        return preg_match('#^https?://#i', $url) && filter_var($url, FILTER_VALIDATE_URL) ? $url : null;
    }

    private function sender(Chat $chat, bool $inbound): array
    {
        if ($inbound) {
            return ['kind' => 'visitor'];
        }

        if ((int) $chat->user_id <= 0) {
            return ['kind' => 'bot'];
        }

        $name = null;

        if ($this->settings['behavior']['show_agent_names'] ?? true) {
            $user = $chat->relationLoaded('user') ? $chat->user : $chat->user()->first();
            $name = $user ? trim((string) ($user->first_name ?? '')) : null;
        }

        return array_filter(['kind' => 'agent', 'name' => $name ?: null], static fn ($v) => $v !== null);
    }

    /** الوقت بـ UTC ISO-8601: الموديل يحوّل created_at إلى منطقة المنشأة نصّاً. */
    private static function isoTime(Chat $chat): ?string
    {
        $raw = $chat->getRawOriginal('created_at') ?? $chat->getAttributes()['created_at'] ?? null;

        if (!$raw) {
            return null;
        }

        return Carbon::parse($raw, 'UTC')->utc()->toIso8601ZuluString();
    }
}
