<?php

namespace App\Services\Messaging\Drivers;

use App\Models\ChannelAccount;
use App\Models\Contact;
use App\Services\Messaging\Channel;
use App\Services\Messaging\ChannelCapabilities;
use App\Services\Messaging\Contracts\ChannelDriver;
use App\Services\Messaging\Contracts\DownloadedMedia;
use App\Services\Messaging\Contracts\SupportsInteractive;
use App\Services\Messaging\Exceptions\ChannelConfigurationException;
use App\Services\Messaging\InboundMedia;
use App\Services\Messaging\InboundMessage;
use App\Services\Messaging\OutboundInteractive;
use App\Services\Messaging\OutboundMedia;
use App\Services\Messaging\SendResult;
use App\Services\Messaging\Support\MessageId;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * تيليجرام عبر Bot API.
 *
 * أبسط المنصّات: لا نافذة زمنية، ولا موافقات مسبقة، ولا قوالب. التوكن
 * وحده يكفي. ولذلك بدأنا بها — تكشف عيوب التصميم بأقلّ تكلفة.
 *
 * ثلاثة فروق جوهرية عن واتساب:
 *   ١. message_id تسلسلي **لكل محادثة**، فيلزم تنميقه بـ chat_id.
 *   ٢. لا إشعارات تسليم ولا قراءة للبوتات — الحالة تقف عند sent.
 *   ٣. تنزيل الملفات محدود بعشرين ميغابايت (حدّ Bot API لا حدّ تيليجرام).
 */
class TelegramDriver implements ChannelDriver, SupportsInteractive
{
    private const API = 'https://api.telegram.org';

    /** حدّ تنزيل البوت من تيليجرام. */
    private const DOWNLOAD_LIMIT = 20 * 1024 * 1024;

    /**
     * التحديثات التي يطلبها الـ webhook. callback_query ضغطة زرّ inline: بدونها
     * تدور الأزرار ولا يصلنا شيء. وتيليجرام تحفظ القائمة مع الـ webhook، فالبوت
     * المربوط قبل الأزرار يُعاد تسجيله مرّة (ensureButtonUpdates).
     */
    private const ALLOWED_UPDATES = ['message', 'edited_message', 'callback_query'];

    /** علامة في بيانات الحساب: سُجّل الـ webhook بـ ALLOWED_UPDATES الحالية. */
    private const UPDATES_MARK = 'callback_query';

    /** حدّ callback_data في Bot API (بايت لا حرف). */
    private const CALLBACK_DATA_LIMIT = 64;

    /** حدّ تعليق الوسائط؛ المتن الأطول يُرسل رسالةً بعد الوسائط. */
    private const CAPTION_LIMIT = 1024;

    public function channel(): string
    {
        return Channel::TELEGRAM;
    }

    public function capabilities(): ChannelCapabilities
    {
        return new ChannelCapabilities(
            windowHours: null,          // لا نافذة
            templates: false,
            interactive: true,          // inline keyboard: أزرار وقوائم وزرّ رابط
            location: true,
            locationRequest: false,
            mediaCaption: true,
            textWithMedia: true,
            multipleAttachments: false,
            deliveryReceipts: false,    // البوتات لا تتلقّى إشعار تسليم
            readReceipts: false,
            blockContact: false,
            reactions: false,
            replyTo: true,
            mediaLimits: [
                'image' => 10 * 1024 * 1024,
                'video' => 50 * 1024 * 1024,
                'audio' => 50 * 1024 * 1024,
                'document' => 50 * 1024 * 1024,
                'sticker' => 512 * 1024,
            ],
            campaigns: true,
        );
    }

    public function canAddress(Contact $contact): bool
    {
        return trim((string) $contact->channel_identifier) !== '';
    }

    /* ───────────────────────── الوارد ───────────────────────── */

    /**
     * تيليجرام لا توقّع الحمولة، بل تُعيد secret_token الذي سجّلناه في
     * ترويسة. مقارنته بالمخزَّن هي كل التحقّق المتاح — ونقارن بـ hash_equals
     * لا بـ === لئلّا يُستدلّ على السرّ بقياس الزمن.
     */
    public function verifyWebhook(Request $request, ?ChannelAccount $account = null): bool
    {
        if (!$account) {
            return false;
        }

        $expected = (string) $account->credential('webhook_secret', '');
        $received = (string) $request->header('X-Telegram-Bot-Api-Secret-Token', '');

        if ($expected === '') {
            // حساب قديم بلا سرّ: نقبل مؤقّتاً ونُسجّل، فالرفض يقطع رسائل
            // عميل يعمل. يُصلَح بإعادة تسجيل الـ webhook.
            Log::warning('Telegram webhook has no stored secret', ['account_id' => $account->id]);

            return true;
        }

        return $received !== '' && hash_equals($expected, $received);
    }

    public function webhookChallenge(Request $request, ?ChannelAccount $account = null): ?string
    {
        return null;    // لا handshake في تيليجرام
    }

    /**
     * تيليجرام لا تُرسل معرّف البوت في الحمولة. لذلك يحمل المسار نفسه
     * معرّف الحساب: /webhook/channel/telegram/{account_uuid}.
     */
    public function resolveAccountExternalId(Request $request): ?string
    {
        return null;
    }

    public function parseWebhook(Request $request, ChannelAccount $account): array
    {
        $update = $request->all();

        if (is_array($update['callback_query'] ?? null)) {
            return $this->parseButtonPress($update['callback_query'], $update, $account);
        }

        $message = $update['message'] ?? $update['edited_message'] ?? null;

        if (!is_array($message)) {
            return [];
        }

        $chat = $message['chat'] ?? [];

        // المحادثات الجماعية والقنوات ليست محادثات عملاء.
        if (($chat['type'] ?? '') !== 'private') {
            return [];
        }

        $chatId = (string) ($chat['id'] ?? '');
        $messageId = (string) ($message['message_id'] ?? '');

        if ($chatId === '' || $messageId === '') {
            return [];
        }

        $from = $message['from'] ?? [];
        [$metadata, $media] = $this->buildContent($message);

        return [new InboundMessage(
            providerMessageId: MessageId::namespaced(
                Channel::TELEGRAM,
                (string) $account->external_id,
                $messageId,
                $chatId
            ),
            senderId: $chatId,
            metadata: $metadata,
            sentAt: isset($message['date'])
                ? (new \DateTimeImmutable('@' . (int) $message['date']))->setTimezone(new \DateTimeZone('UTC'))
                : null,
            senderName: trim(($from['first_name'] ?? '') . ' ' . ($from['last_name'] ?? '')) ?: null,
            senderUsername: $from['username'] ?? null,
            media: $media,
            replyToProviderId: isset($message['reply_to_message']['message_id'])
                ? MessageId::namespaced(
                    Channel::TELEGRAM,
                    (string) $account->external_id,
                    (string) $message['reply_to_message']['message_id'],
                    $chatId
                )
                : null,
            raw: $update,
        )];
    }

    /**
     * ضغطة زرّ inline: تصل ردّاً تفاعلياً بشكل واتساب (button_reply/list_reply)
     * بعنوان الزرّ، فيطابقها الـ flow كما يطابق ضغطة زرّ واتساب.
     *
     * العنوان من لوحة الأزرار في الرسالة نفسها — هو ما رآه العميل — ثم من
     * callback_data إن لم تصل الرسالة (تيليجرام تحجب الرسائل القديمة جداً).
     * callback_data يكتبها عميلٌ معدَّل كما يشاء، لكنّ أقصى ما تفعله أن تساوي
     * كتابة العنوان نصّاً.
     *
     * @return array<int, InboundMessage>
     */
    private function parseButtonPress(array $callback, array $update, ChannelAccount $account): array
    {
        $message = is_array($callback['message'] ?? null) ? $callback['message'] : [];
        $from = is_array($callback['from'] ?? null) ? $callback['from'] : [];

        if (($message['chat']['type'] ?? 'private') !== 'private') {
            return [];
        }

        $chatId = (string) ($message['chat']['id'] ?? $from['id'] ?? '');
        $callbackId = (string) ($callback['id'] ?? '');
        $data = (string) ($callback['data'] ?? '');

        if ($chatId === '' || $callbackId === '' || $data === '') {
            return [];
        }

        // يوقف دوران الزرّ عند العميل. قبل أيّ شيء آخر: الضغطة وصلت.
        $this->answerButtonPress($account, $callbackId);

        $title = $this->buttonTitle($message, $data);

        if ($title === null) {
            return [];
        }

        $kind = str_starts_with($data, 'l') ? 'list_reply' : 'button_reply';

        return [new InboundMessage(
            // معرّف الضغطة فريد عند تيليجرام، فإعادة الـ webhook لا تكرّرها.
            providerMessageId: MessageId::namespaced(
                Channel::TELEGRAM,
                (string) $account->external_id,
                'cb' . $callbackId,
                $chatId
            ),
            senderId: $chatId,
            metadata: [
                'type' => 'interactive',
                'interactive' => [
                    'type' => $kind,
                    $kind => ['id' => $data, 'title' => $title],
                ],
            ],
            senderName: trim(($from['first_name'] ?? '') . ' ' . ($from['last_name'] ?? '')) ?: null,
            senderUsername: $from['username'] ?? null,
            raw: $update,
        )];
    }

    /** عنوان الزرّ الذي ضُغط: من لوحة الرسالة، وإلا من callback_data. */
    private function buttonTitle(array $message, string $data): ?string
    {
        foreach ((array) ($message['reply_markup']['inline_keyboard'] ?? []) as $row) {
            foreach ((array) $row as $button) {
                if (is_array($button) && ($button['callback_data'] ?? null) === $data) {
                    $text = trim((string) ($button['text'] ?? ''));

                    return $text !== '' ? $text : null;
                }
            }
        }

        // «b:العنوان» / «l:العنوان». الصيغة بالرقم («b#2») تحتاج اللوحة.
        if (strlen($data) > 2 && $data[1] === ':') {
            $title = trim(substr($data, 2));

            return $title !== '' ? $title : null;
        }

        return null;
    }

    private function answerButtonPress(ChannelAccount $account, string $callbackId): void
    {
        try {
            Http::timeout(5)->asJson()->post(
                self::API . '/bot' . $this->token($account) . '/answerCallbackQuery',
                ['callback_query_id' => $callbackId]
            );
        } catch (\Throwable $e) {
            // ضغطةٌ بلا إجابة تتوقّف وحدها بعد ثوانٍ؛ لا تُسقط الردّ.
            Log::warning('Telegram answerCallbackQuery failed', [
                'account_id' => $account->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * تحويل رسالة تيليجرام إلى شكل metadata الخاص بواتساب.
     *
     * هذا هو الموضع الذي يجعل الفقاعة وتطبيق الجوال يعملان بلا تعديل:
     * نُخرج {"type":"text","text":{"body":…}} تماماً كما يفعل
     * ChatMetadataHelper، لا شكلاً خاصاً بتيليجرام.
     *
     * @return array{0: array, 1: ?InboundMedia}
     */
    private function buildContent(array $message): array
    {
        $caption = $message['caption'] ?? null;

        // الصورة تصل بعدّة مقاسات، آخرها الأكبر.
        if (!empty($message['photo']) && is_array($message['photo'])) {
            $photo = end($message['photo']);

            return [
                ['type' => 'image', 'image' => array_filter(['caption' => $caption])],
                new InboundMedia(
                    type: 'image',
                    providerId: $photo['file_id'] ?? null,
                    mimeType: 'image/jpeg',
                    size: $photo['file_size'] ?? null,
                    caption: $caption,
                ),
            ];
        }

        foreach (['video' => 'video', 'animation' => 'video'] as $key => $type) {
            if (!empty($message[$key])) {
                return [
                    ['type' => 'video', 'video' => array_filter(['caption' => $caption])],
                    new InboundMedia(
                        type: 'video',
                        providerId: $message[$key]['file_id'] ?? null,
                        mimeType: $message[$key]['mime_type'] ?? 'video/mp4',
                        fileName: $message[$key]['file_name'] ?? null,
                        size: $message[$key]['file_size'] ?? null,
                        caption: $caption,
                    ),
                ];
            }
        }

        if (!empty($message['voice'])) {
            return [
                ['type' => 'audio', 'audio' => ['voice' => true]],
                new InboundMedia(
                    type: 'audio',
                    providerId: $message['voice']['file_id'] ?? null,
                    mimeType: $message['voice']['mime_type'] ?? 'audio/ogg',
                    size: $message['voice']['file_size'] ?? null,
                    isVoice: true,
                ),
            ];
        }

        if (!empty($message['audio'])) {
            return [
                ['type' => 'audio', 'audio' => []],
                new InboundMedia(
                    type: 'audio',
                    providerId: $message['audio']['file_id'] ?? null,
                    mimeType: $message['audio']['mime_type'] ?? 'audio/mpeg',
                    fileName: $message['audio']['file_name'] ?? null,
                    size: $message['audio']['file_size'] ?? null,
                ),
            ];
        }

        if (!empty($message['document'])) {
            return [
                [
                    'type' => 'document',
                    'document' => array_filter([
                        'filename' => $message['document']['file_name'] ?? null,
                        'caption' => $caption,
                    ]),
                ],
                new InboundMedia(
                    type: 'document',
                    providerId: $message['document']['file_id'] ?? null,
                    mimeType: $message['document']['mime_type'] ?? 'application/octet-stream',
                    fileName: $message['document']['file_name'] ?? null,
                    size: $message['document']['file_size'] ?? null,
                    caption: $caption,
                ),
            ];
        }

        if (!empty($message['sticker'])) {
            // الملصق المتحرّك webm/tgs لا يعرضه المتصفّح؛ نحفظ الإيموجي
            // المكافئ نصّاً فيبقى للموظّف معنى بدل فقاعة فارغة.
            $emoji = $message['sticker']['emoji'] ?? null;

            if ($emoji && !empty($message['sticker']['is_animated'])) {
                return [['type' => 'text', 'text' => ['body' => $emoji]], null];
            }

            return [
                ['type' => 'sticker', 'sticker' => []],
                new InboundMedia(
                    type: 'sticker',
                    providerId: $message['sticker']['file_id'] ?? null,
                    mimeType: 'image/webp',
                    size: $message['sticker']['file_size'] ?? null,
                ),
            ];
        }

        if (!empty($message['location'])) {
            return [[
                'type' => 'location',
                'location' => array_filter([
                    'latitude' => $message['location']['latitude'] ?? null,
                    'longitude' => $message['location']['longitude'] ?? null,
                ], static fn ($v) => $v !== null),
            ], null];
        }

        if (!empty($message['contact'])) {
            $c = $message['contact'];
            $name = trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? ''));

            return [[
                'type' => 'contacts',
                'contacts' => [[
                    'name' => ['formatted_name' => $name ?: ($c['phone_number'] ?? '')],
                    'phones' => [['phone' => $c['phone_number'] ?? '']],
                ]],
            ], null];
        }

        if (isset($message['text'])) {
            return [['type' => 'text', 'text' => ['body' => (string) $message['text']]], null];
        }

        return [['type' => 'unsupported', 'errors' => [['title' => 'Unsupported Telegram message type']]], null];
    }

    public function downloadMedia(ChannelAccount $account, InboundMedia $media): ?DownloadedMedia
    {
        if (!$media->providerId) {
            return null;
        }

        if ($media->size !== null && $media->size > self::DOWNLOAD_LIMIT) {
            Log::warning('Telegram file exceeds bot download limit', [
                'account_id' => $account->id,
                'size' => $media->size,
            ]);

            return null;
        }

        $token = $this->token($account);

        $info = Http::timeout(20)->get(self::API . "/bot{$token}/getFile", [
            'file_id' => $media->providerId,
        ]);

        $filePath = $info->json('result.file_path');

        if (!$info->successful() || !$filePath) {
            Log::error('Telegram getFile failed', [
                'account_id' => $account->id,
                'response' => $info->body(),
            ]);

            return null;
        }

        $file = Http::timeout(120)->get(self::API . "/file/bot{$token}/{$filePath}");

        if (!$file->successful()) {
            return null;
        }

        return new DownloadedMedia(
            contents: $file->body(),
            mimeType: $media->mimeType ?? ($file->header('Content-Type') ?: 'application/octet-stream'),
            fileName: $media->fileName ?? basename($filePath),
        );
    }

    public function fetchProfile(ChannelAccount $account, string $externalId): ?array
    {
        // الاسم يصل داخل كل رسالة، فلا حاجة إلى نداء إضافي. الصورة تحتاج
        // نداءين (getUserProfilePhotos ثم getFile) ولا تستحقّهما هنا.
        return null;
    }

    /* ───────────────────────── الصادر ───────────────────────── */

    public function sendText(ChannelAccount $account, Contact $contact, string $body, array $options = []): SendResult
    {
        return $this->call($account, 'sendMessage', array_filter([
            'chat_id' => $contact->channel_identifier,
            'text' => $body,
            'reply_to_message_id' => $options['reply_to'] ?? null,
        ], static fn ($v) => $v !== null), $contact);
    }

    public function sendLocation(ChannelAccount $account, Contact $contact, array $location, array $options = []): SendResult
    {
        $latitude = $location['latitude'] ?? null;
        $longitude = $location['longitude'] ?? null;

        if (!is_numeric($latitude) || !is_numeric($longitude)) {
            return SendResult::failure(__('A valid location is required.'));
        }

        // تيليجرام ترفض الإحداثيات نصوصاً — تُرسل أرقاماً، كما تفعل Meta.
        return $this->call($account, 'sendLocation', [
            'chat_id' => $contact->channel_identifier,
            'latitude' => (float) $latitude,
            'longitude' => (float) $longitude,
        ], $contact);
    }

    public function sendMedia(ChannelAccount $account, Contact $contact, OutboundMedia $media, array $options = []): SendResult
    {
        [$method, $field] = match ($media->type) {
            'image' => ['sendPhoto', 'photo'],
            'video' => ['sendVideo', 'video'],
            'audio' => $media->isVoice ? ['sendVoice', 'voice'] : ['sendAudio', 'audio'],
            'sticker' => ['sendSticker', 'sticker'],
            default => ['sendDocument', 'document'],
        };

        if (!$media->hasUrl()) {
            return SendResult::failure(__('A public file URL is required to send media on Telegram.'));
        }

        $payload = array_filter([
            'chat_id' => $contact->channel_identifier,
            $field => $media->url,
            'caption' => $media->type === 'sticker' ? null : $media->caption,
        ], static fn ($v) => $v !== null && $v !== '');

        return $this->call($account, $method, $payload, $contact);
    }

    /**
     * أزرار الـ flow وقوائمه وزرّ الرابط لوحةَ inline تحت الرسالة.
     *
     * لا قوائم منسدلة في تيليجرام: كل صفّ في القائمة زرٌّ في سطر، وما للصفوف
     * من وصفٍ يُلحق بالمتن. ورأس الوسائط يُرسل صورةً (أو فيديو أو ملفاً)
     * تعليقها المتن وتحتها الأزرار، ما لم يطُل المتن عن حدّ التعليق.
     */
    public function sendInteractive(ChannelAccount $account, Contact $contact, OutboundInteractive $message, array $options = []): SendResult
    {
        $this->ensureButtonUpdates($account);

        $text = $this->interactiveText($message);
        $markup = ['inline_keyboard' => $this->keyboard($message)];
        $header = $message->header;

        if (in_array($header['type'] ?? null, ['image', 'video', 'document'], true) && !empty($header['url'])) {
            [$method, $field] = match ($header['type']) {
                'image' => ['sendPhoto', 'photo'],
                'video' => ['sendVideo', 'video'],
                default => ['sendDocument', 'document'],
            };

            if (mb_strlen($text) <= self::CAPTION_LIMIT) {
                return $this->call($account, $method, [
                    'chat_id' => $contact->channel_identifier,
                    $field => $header['url'],
                    'caption' => $text,
                    'reply_markup' => $markup,
                ], $contact);
            }

            $media = $this->call($account, $method, [
                'chat_id' => $contact->channel_identifier,
                $field => $header['url'],
            ], $contact);

            if (!$media->success) {
                return $media;
            }
        }

        return $this->call($account, 'sendMessage', [
            'chat_id' => $contact->channel_identifier,
            'text' => $text,
            'reply_markup' => $markup,
        ], $contact);
    }

    /** المتن: رأسٌ نصّي، ثم الرسالة، ثم أوصاف صفوف القائمة، ثم التذييل. */
    private function interactiveText(OutboundInteractive $message): string
    {
        $parts = [];

        if (($message->header['type'] ?? null) === 'text') {
            $parts[] = $message->header['text'];
        }

        $parts[] = $message->body;

        if ($message->kind === OutboundInteractive::LIST) {
            $described = [];

            foreach ($message->sections as $section) {
                foreach ($section['rows'] as $row) {
                    if (!empty($row['description'])) {
                        $described[] = '• ' . $row['title'] . ': ' . $row['description'];
                    }
                }
            }

            if ($described !== []) {
                $parts[] = implode("\n", $described);
            }
        }

        if ($message->footer !== null) {
            $parts[] = $message->footer;
        }

        return trim(implode("\n\n", array_filter($parts, static fn ($p) => trim((string) $p) !== '')));
    }

    /**
     * سطر لكل زرّ: العناوين العربية تُقصّ إن تجاورت.
     *
     * @return array<int, array<int, array<string, string>>>
     */
    private function keyboard(OutboundInteractive $message): array
    {
        if ($message->kind === OutboundInteractive::CTA_URL) {
            return [[[
                'text' => $message->displayText ?: (string) $message->url,
                'url' => (string) $message->url,
            ]]];
        }

        $titles = $message->kind === OutboundInteractive::BUTTONS
            ? array_column($message->buttons, 'title')
            : array_merge(...array_map(static fn ($s) => array_column($s['rows'], 'title'), $message->sections ?: [['rows' => []]]));

        $prefix = $message->kind === OutboundInteractive::LIST ? 'l' : 'b';
        $rows = [];

        foreach (array_values($titles) as $index => $title) {
            $rows[] = [['text' => (string) $title, 'callback_data' => $this->callbackData($prefix, $index, (string) $title)]];
        }

        return $rows;
    }

    /**
     * «b:العنوان» إن اتّسع له حدّ الـ 64 بايت، وإلا «b#الترتيب» ويُقرأ العنوان
     * من لوحة الرسالة عند الضغط. والعنوان أوّلاً لأنه يُقرأ ولو حُجبت الرسالة.
     */
    private function callbackData(string $prefix, int $index, string $title): string
    {
        $data = $prefix . ':' . $title;

        return strlen($data) <= self::CALLBACK_DATA_LIMIT ? $data : $prefix . '#' . $index;
    }

    /**
     * بوتٌ رُبط قبل الأزرار سُجّل webhook-ه بلا callback_query، فتدور أزراره ولا
     * يصلنا شيء. يُعاد تسجيله مرّة قبل أوّل رسالة بأزرار، بالرابط نفسه والسرّ
     * نفسه. والفشل لا يمنع الإرسال: الأزرار تظهر، ويبقى للعميل أن يكتب.
     */
    private function ensureButtonUpdates(ChannelAccount $account): void
    {
        if ($account->credential('webhook_updates') === self::UPDATES_MARK) {
            return;
        }

        try {
            // الرابط الذي يعرفه تيليجرام لا route(): هذا يجري في عامل الطابور
            // حيث يُبنى الرابط من APP_URL لا من النطاق الذي رُبط منه البوت —
            // ورابطٌ خاطئ هنا يقطع رسائل البوت كلّها، لا الأزرار وحدها.
            $info = $this->webhookInfo($account);
            if ($info === null || ($info['url'] ?? '') === '') {
                Log::warning('Telegram webhook info unavailable; buttons re-registration skipped', ['account_id' => $account->id]);

                return;
            }

            if (in_array('callback_query', (array) ($info['allowed_updates'] ?? []), true)) {
                $account->mergeCredentials(['webhook_updates' => self::UPDATES_MARK]);

                return;
            }

            $this->registerWebhook($account, $info['url']);
        } catch (\Throwable $e) {
            Log::warning('Telegram webhook re-registration for buttons failed', [
                'account_id' => $account->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /* ───────────────────────── الحساب ───────────────────────── */

    public function verifyCredentials(array $credentials): array
    {
        $token = trim((string) ($credentials['bot_token'] ?? ''));

        if ($token === '') {
            throw new ChannelConfigurationException(__('The Telegram bot token is required.'));
        }

        $response = Http::timeout(15)->get(self::API . "/bot{$token}/getMe");

        if (!$response->successful() || $response->json('ok') !== true) {
            throw new ChannelConfigurationException(
                __('Telegram rejected this bot token. Copy it again from :bot.', ['bot' => '@BotFather'])
            );
        }

        $result = $response->json('result');

        return [
            'external_id' => (string) ($result['id'] ?? ''),
            'name' => $result['first_name'] ?? null,
            'username' => $result['username'] ?? null,
        ];
    }

    /**
     * تسجيل الـ webhook. المسار يحمل uuid الحساب لأن تيليجرام لا تُرسل
     * معرّف البوت مع التحديثات، فالمسار وحده ما يُميّز حساباً عن آخر.
     */
    /** ما سجّله تيليجرام لهذا البوت (url وallowed_updates)، أو null إن تعذّرت القراءة. */
    private function webhookInfo(ChannelAccount $account): ?array
    {
        $response = Http::timeout(15)->get(self::API . '/bot' . $this->token($account) . '/getWebhookInfo');

        if (!$response->successful() || $response->json('ok') !== true || !is_array($response->json('result'))) {
            return null;
        }

        return $response->json('result');
    }

    /**
     * @param string|null $url رابطٌ مسجّل سلفاً يُعاد كما هو؛ بدونه يُبنى من
     *                         المسار (الربط الأوّل، من طلب الويب).
     */
    public function registerWebhook(ChannelAccount $account, ?string $url = null): bool
    {
        $secret = $account->credential('webhook_secret');

        if (!$secret) {
            $secret = bin2hex(random_bytes(24));
            $account->mergeCredentials(['webhook_secret' => $secret]);
        }

        $response = Http::timeout(20)->post(self::API . '/bot' . $this->token($account) . '/setWebhook', [
            'url' => $url ?? route('channels.webhook', ['channel' => Channel::TELEGRAM, 'account' => $account->uuid]),
            'secret_token' => $secret,
            'allowed_updates' => self::ALLOWED_UPDATES,
            'drop_pending_updates' => false,
        ]);

        if (!$response->successful() || $response->json('ok') !== true) {
            Log::error('Telegram setWebhook failed', [
                'account_id' => $account->id,
                'response' => $response->body(),
            ]);

            return false;
        }

        $account->mergeCredentials(['webhook_updates' => self::UPDATES_MARK]);

        return true;
    }

    public function unregisterWebhook(ChannelAccount $account): bool
    {
        $response = Http::timeout(15)->post(self::API . '/bot' . $this->token($account) . '/deleteWebhook');

        return $response->successful();
    }

    /* ───────────────────────── داخلي ───────────────────────── */

    private function token(ChannelAccount $account): string
    {
        return (string) $account->credential('bot_token', '');
    }

    /** نداء واحد إلى Bot API مع ترجمة الأخطاء المعروفة إلى رسائل مفهومة. */
    private function call(ChannelAccount $account, string $method, array $payload, Contact $contact): SendResult
    {
        try {
            $response = Http::timeout(30)->asJson()
                ->post(self::API . '/bot' . $this->token($account) . '/' . $method, $payload);
        } catch (\Throwable $e) {
            return SendResult::failure($e->getMessage());
        }

        $json = $response->json();

        if ($response->successful() && ($json['ok'] ?? false) === true) {
            $messageId = (string) ($json['result']['message_id'] ?? '');

            return SendResult::success(
                MessageId::namespaced(
                    Channel::TELEGRAM,
                    (string) $account->external_id,
                    $messageId,
                    (string) $contact->channel_identifier
                ),
                // لا إشعار تسليم في تيليجرام، فـ sent هي الحالة النهائية.
                'sent',
                $json
            );
        }

        $code = (string) ($json['error_code'] ?? '');
        $description = (string) ($json['description'] ?? __('Message could not be sent.'));

        // 403 يعني أن العميل حظر البوت — نحفظها على جهة الاتصال بدل أن
        // نُعيد المحاولة على ما لن يتغيّر.
        if ($code === '403') {
            $description = __('This customer has blocked the bot on Telegram.');
        }

        if ($code === '429') {
            $retry = $json['parameters']['retry_after'] ?? null;
            $description = __('Telegram rate limit reached. Retry after :seconds seconds.', ['seconds' => $retry ?? 1]);
        }

        return SendResult::failure($description, $code ?: null, $json);
    }
}
