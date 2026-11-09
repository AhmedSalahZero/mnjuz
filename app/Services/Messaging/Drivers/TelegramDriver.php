<?php

namespace App\Services\Messaging\Drivers;

use App\Models\ChannelAccount;
use App\Models\Contact;
use App\Services\Messaging\Channel;
use App\Services\Messaging\ChannelCapabilities;
use App\Services\Messaging\Contracts\ChannelDriver;
use App\Services\Messaging\Contracts\DownloadedMedia;
use App\Services\Messaging\Exceptions\ChannelConfigurationException;
use App\Services\Messaging\InboundMedia;
use App\Services\Messaging\InboundMessage;
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
class TelegramDriver implements ChannelDriver
{
    private const API = 'https://api.telegram.org';

    /** حدّ تنزيل البوت من تيليجرام. */
    private const DOWNLOAD_LIMIT = 20 * 1024 * 1024;

    public function channel(): string
    {
        return Channel::TELEGRAM;
    }

    public function capabilities(): ChannelCapabilities
    {
        return new ChannelCapabilities(
            windowHours: null,          // لا نافذة
            templates: false,
            interactive: false,
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
    public function registerWebhook(ChannelAccount $account): bool
    {
        $secret = $account->credential('webhook_secret');

        if (!$secret) {
            $secret = bin2hex(random_bytes(24));
            $account->mergeCredentials(['webhook_secret' => $secret]);
        }

        $response = Http::timeout(20)->post(self::API . '/bot' . $this->token($account) . '/setWebhook', [
            'url' => route('channels.webhook', ['channel' => Channel::TELEGRAM, 'account' => $account->uuid]),
            'secret_token' => $secret,
            'allowed_updates' => ['message', 'edited_message'],
            'drop_pending_updates' => false,
        ]);

        if (!$response->successful() || $response->json('ok') !== true) {
            Log::error('Telegram setWebhook failed', [
                'account_id' => $account->id,
                'response' => $response->body(),
            ]);

            return false;
        }

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
