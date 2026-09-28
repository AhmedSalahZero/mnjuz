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
use App\Services\Messaging\InboundStatus;
use App\Services\Messaging\OutboundMedia;
use App\Services\Messaging\SendResult;
use App\Services\Messaging\Support\MessageId;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * TikTok Business Messaging API (open_api v1.3).
 *
 * أضيق المنصّات وأكثرها مؤونة:
 *   ١. نافذة الردّ ٤٨ ساعة، والعميل يبدأ المحادثة دائماً.
 *   ٢. نصّ **أو** صورة واحدة — لا الاثنان معاً، ولا فيديو ولا مستندات.
 *   ٣. access token يعيش يوماً واحداً و refresh token ثلاثين يوماً، فتجديد
 *      الصلاحيات عمل دوري مستمرّ لا إعداد مرّة واحدة.
 *   ٤. التوقيع يشمل الوقت، ويُرفض إن تجاوز الفارق خمس ثوانٍ — فساعة
 *      الخادم يجب أن تكون مضبوطة على NTP.
 *
 * المعرّف الذي نُرسل إليه هو conversation_id لا معرّف المستخدم.
 */
class TikTokDriver implements ChannelDriver, \App\Services\Messaging\Contracts\RefreshesCredentials, \App\Services\Messaging\Contracts\SupportsOAuth
{
    /**
     * الصلاحيات التي يطلبها التفويض.
     *
     * الثلاث الأخيرة هي جوهر المراسلة، والبقيّة تُتيح قراءة اسم الحساب
     * وصورته. ونتحقّق عند العودة أنها مُنحت كلّها: تيك توك تسمح للمستخدم
     * بمنح بعضها، والربط الناقص يبدو ناجحاً ثم يفشل أول إرسال.
     */
    public const REQUIRED_SCOPES = [
        'user.info.basic',
        'user.info.username',
        'user.info.profile',
        'user.account.type',
        'message.list.read',
        'message.list.send',
        'message.list.manage',
    ];

    private const API = 'https://business-api.tiktok.com/open_api';

    /** فارق الوقت المقبول في التوقيع، بالثواني. */
    private const SIGNATURE_TOLERANCE = 5;

    private const MAX_IMAGE_BYTES = 3 * 1024 * 1024;

    public function channel(): string
    {
        return Channel::TIKTOK;
    }

    public function capabilities(): ChannelCapabilities
    {
        return new ChannelCapabilities(
            windowHours: 48,
            templates: false,
            interactive: false,
            location: false,
            locationRequest: false,
            mediaCaption: false,
            textWithMedia: false,       // لا نصّ وصورة في رسالة واحدة
            multipleAttachments: false,
            deliveryReceipts: false,
            readReceipts: true,
            blockContact: false,
            reactions: false,
            replyTo: true,
            mediaLimits: ['image' => self::MAX_IMAGE_BYTES],
            imageMimeTypes: ['image/jpeg', 'image/png'],
            campaigns: true,
        );
    }

    public function canAddress(Contact $contact): bool
    {
        return trim((string) $contact->channel_identifier) !== '';
    }

    /* ───────────────────────── الوارد ───────────────────────── */

    /**
     * التوقيع: Tiktok-Signature: t=<unix>,s=<hex>
     * والبصمة hmac_sha256(app_secret, "<t>.<raw body>").
     */
    public function verifyWebhook(Request $request, ?ChannelAccount $account = null): bool
    {
        $secret = config('services.tiktok.app_secret');

        if (!$secret) {
            Log::warning('TikTok webhook signature skipped: no app secret configured');

            return true;
        }

        $header = (string) $request->header('Tiktok-Signature', '');

        if ($header === '') {
            return false;
        }

        $parts = [];

        foreach (explode(',', $header) as $piece) {
            $kv = explode('=', trim($piece), 2);

            if (count($kv) === 2) {
                $parts[$kv[0]] = $kv[1];
            }
        }

        $timestamp = isset($parts['t']) ? (int) $parts['t'] : 0;
        $signature = $parts['s'] ?? '';

        if ($timestamp === 0 || $signature === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $request->getContent(), $secret);

        if (!hash_equals($expected, $signature)) {
            return false;
        }

        // الفارق يُحسب بالقيمة المطلقة: ساعة الخادم قد تسبق ساعة تيك توك.
        return abs(time() - $timestamp) <= self::SIGNATURE_TOLERANCE;
    }

    public function webhookChallenge(Request $request, ?ChannelAccount $account = null): ?string
    {
        return null;
    }

    /** تيك توك تُرسل معرّف الحساب في user_openid. */
    public function resolveAccountExternalId(Request $request): ?string
    {
        $openId = $request->input('user_openid');

        return $openId ? (string) $openId : null;
    }

    public function parseWebhook(Request $request, ChannelAccount $account): array
    {
        $event = (string) $request->input('event', '');
        $content = $request->input('content');

        // content يصل نصّاً يحوي JSON لا كائناً.
        if (is_string($content)) {
            $content = json_decode($content, true);
        }

        if (!is_array($content)) {
            return [];
        }

        $businessId = (string) $account->external_id;
        $conversationId = (string) ($content['conversation_id'] ?? '');

        if ($conversationId === '') {
            return [];
        }

        if ($event === 'im_mark_read_msg') {
            return [new InboundStatus(
                status: 'read',
                watermark: $this->timestamp($content['read']['last_read_timestamp'] ?? null),
                senderId: $conversationId,
                raw: $content,
            )];
        }

        if (!in_array($event, ['im_receive_msg', 'im_send_msg'], true)) {
            return [];
        }

        $messageId = (string) ($content['message_id'] ?? '');

        if ($messageId === '') {
            return [];
        }

        // im_send_msg هو صدى رسالة صادرة: إمّا أرسلناها نحن، وإمّا كتبها
        // الموظّف من تطبيق تيك توك.
        $isEcho = $event === 'im_send_msg'
            || (string) ($content['from_user']['id'] ?? '') === $businessId;

        [$metadata, $media] = $this->buildContent($content, $conversationId, $messageId);

        return [new InboundMessage(
            providerMessageId: MessageId::namespaced(Channel::TIKTOK, $businessId, $messageId),
            senderId: $conversationId,
            metadata: $metadata,
            sentAt: $this->timestamp($content['timestamp'] ?? null),
            senderName: $content['from'] ?? null,
            senderUsername: $content['from'] ?? null,
            media: $media,
            isEcho: $isEcho,
            replyToProviderId: isset($content['referenced_message_info']['referenced_message_id'])
                ? MessageId::namespaced(
                    Channel::TIKTOK,
                    $businessId,
                    (string) $content['referenced_message_info']['referenced_message_id']
                )
                : null,
            raw: $content,
        )];
    }

    /**
     * @return array{0: array, 1: ?InboundMedia}
     */
    private function buildContent(array $content, string $conversationId, string $messageId): array
    {
        $type = $content['type'] ?? 'text';

        if ($type === 'image') {
            $mediaId = $content['image']['media_id'] ?? null;

            return [
                ['type' => 'image', 'image' => []],
                $mediaId ? new InboundMedia(
                    type: 'image',
                    providerId: (string) $mediaId,
                    mimeType: 'image/jpeg',
                    extra: [
                        'conversation_id' => $conversationId,
                        'message_id' => $messageId,
                    ],
                ) : null,
            ];
        }

        // مشاركة منشور: الرابط هو المحتوى.
        if ($type === 'share_post') {
            $url = $content['share_post']['embed_url'] ?? '';

            return [['type' => 'text', 'text' => ['body' => (string) $url]], null];
        }

        if ($type === 'text') {
            return [['type' => 'text', 'text' => ['body' => (string) ($content['text']['body'] ?? '')]], null];
        }

        return [['type' => 'unsupported', 'errors' => [['title' => 'Unsupported TikTok message type: ' . $type]]], null];
    }

    public function downloadMedia(ChannelAccount $account, InboundMedia $media): ?DownloadedMedia
    {
        if (!$media->providerId) {
            return null;
        }

        $token = $this->accessToken($account);

        $response = $this->post($account, '/business/message/media/download/', [
            'business_id' => $account->external_id,
            'conversation_id' => $media->extra['conversation_id'] ?? null,
            'message_id' => $media->extra['message_id'] ?? null,
            'media_id' => $media->providerId,
            'media_type' => 'IMAGE',
        ]);

        $url = data_get($response, 'data.download_url');

        if (!$url) {
            Log::error('TikTok media download url missing', ['account_id' => $account->id]);

            return null;
        }

        // التنزيل يحتاج التوكن في ترويسة x-user لا Authorization.
        $file = Http::timeout(120)->withHeaders(['x-user' => $token])->get($url);

        if (!$file->successful()) {
            return null;
        }

        return new DownloadedMedia(
            contents: $file->body(),
            mimeType: trim(explode(';', $file->header('Content-Type') ?: 'image/jpeg')[0]),
            fileName: $media->fileName,
        );
    }

    public function fetchProfile(ChannelAccount $account, string $externalId): ?array
    {
        // الاسم يصل داخل الحدث (from)، ولا واجهة تُعطي ملف المستخدم من
        // conversation_id. فلا نداء هنا.
        return null;
    }

    /* ───────────────────────── الصادر ───────────────────────── */

    public function sendText(ChannelAccount $account, Contact $contact, string $body, array $options = []): SendResult
    {
        $payload = [
            'business_id' => $account->external_id,
            'recipient_type' => 'CONVERSATION',
            'recipient' => $contact->channel_identifier,
            'message_type' => 'TEXT',
            'text' => ['body' => $body],
        ];

        if (!empty($options['reply_to'])) {
            $payload['referenced_message_info'] = [
                'referenced_message_id' => $this->stripNamespace($options['reply_to']),
            ];
        }

        return $this->sendMessage($account, $payload);
    }

    /** تيك توك لا تدعم غير النصّ والصورة. */
    public function sendLocation(ChannelAccount $account, Contact $contact, array $location, array $options = []): SendResult
    {
        return SendResult::failure(__('Sending a location is not supported on :channel.', [
            'channel' => Channel::label(Channel::TIKTOK),
        ]));
    }

    public function sendMedia(ChannelAccount $account, Contact $contact, OutboundMedia $media, array $options = []): SendResult
    {
        if ($media->type !== 'image') {
            return SendResult::failure(__('TikTok supports image attachments only.'));
        }

        if ($media->size !== null && $media->size > self::MAX_IMAGE_BYTES) {
            return SendResult::failure(__('TikTok image attachments must be smaller than 3 MB.'));
        }

        $mediaId = $this->uploadImage($account, $media);

        if (!$mediaId) {
            return SendResult::failure(__('The image could not be uploaded to TikTok.'));
        }

        return $this->sendMessage($account, [
            'business_id' => $account->external_id,
            'recipient_type' => 'CONVERSATION',
            'recipient' => $contact->channel_identifier,
            'message_type' => 'IMAGE',
            'image' => ['media_id' => $mediaId],
        ]);
    }

    private function sendMessage(ChannelAccount $account, array $payload): SendResult
    {
        $response = $this->post($account, '/business/message/send/', $payload);

        $messageId = data_get($response, 'data.message.message_id');

        if ($messageId) {
            return SendResult::success(
                MessageId::namespaced(Channel::TIKTOK, (string) $account->external_id, (string) $messageId),
                'sent',
                $response
            );
        }

        $code = data_get($response, 'code');
        $message = data_get($response, 'message') ?: __('Message could not be sent.');

        return SendResult::failure($message, $code !== null ? (string) $code : null, $response);
    }

    /** رفع الصورة أولاً للحصول على media_id — تيك توك لا تقبل رابطاً. */
    private function uploadImage(ChannelAccount $account, OutboundMedia $media): ?string
    {
        $contents = null;
        $fileName = $media->fileName ?: 'image.jpg';

        if ($media->hasLocalPath()) {
            $contents = file_get_contents($media->path);
        } elseif ($media->hasUrl()) {
            $fetched = Http::timeout(60)->get($media->url);
            $contents = $fetched->successful() ? $fetched->body() : null;
        }

        if ($contents === null || $contents === false) {
            return null;
        }

        try {
            $response = Http::timeout(120)
                ->withHeaders(['Access-Token' => $this->accessToken($account)])
                ->attach('file', $contents, $fileName)
                ->post(self::API . '/' . $this->version() . '/business/message/media/upload/', [
                    'business_id' => $account->external_id,
                    'media_type' => 'IMAGE',
                ]);
        } catch (\Throwable $e) {
            Log::error('TikTok media upload failed', ['error' => $e->getMessage()]);

            return null;
        }

        $json = $response->json() ?? [];

        if (($json['code'] ?? null) !== 0) {
            Log::error('TikTok media upload rejected', ['response' => $json]);

            return null;
        }

        return data_get($json, 'data.media_id');
    }

    /* ───────────────────────── الصلاحيات ───────────────────────── */

    /**
     * التوكن الصالح، مع تجديده إن لزم.
     *
     * القفل يمنع تجديدين متوازيين: تيك توك تُبطل التوكن القديم عند
     * التجديد، فتجديدان في اللحظة نفسها يُبطلان أحدهما الآخر ويتركان
     * الحساب معطّلاً.
     */
    /**
     * تجديد استباقي تُناديه المهمّة المجدولة.
     *
     * الغرض ليس السرعة بل بقاء الربط: refresh token يموت بعد ثلاثين يوماً
     * من الخمول، فالحساب الذي لا تصله رسائل شهراً يفقد ربطه نهائياً.
     */
    public function refreshCredentials(ChannelAccount $account, int $leewayMinutes = 5): bool
    {
        if (!$account->tokenExpired($leewayMinutes)) {
            return true;
        }

        if ($account->refreshTokenExpired()) {
            $account->markReauthorizationRequired(
                __('The TikTok authorization expired. Reconnect the account.')
            );

            return false;
        }

        $this->refreshAccessToken($account);

        return $account->fresh()->isActive();
    }

    protected function accessToken(ChannelAccount $account): string
    {
        if (!$account->tokenExpired()) {
            return (string) $account->credential('access_token', '');
        }

        if ($account->refreshTokenExpired()) {
            $account->markReauthorizationRequired(__('The TikTok authorization expired. Reconnect the account.'));

            return (string) $account->credential('access_token', '');
        }

        $lock = Cache::lock('tiktok_refresh_' . $account->id, 30);

        if (!$lock->get()) {
            // عملية أخرى تُجدّد الآن؛ التوكن الحالي ما زال صالحاً دقائق.
            return (string) $account->credential('access_token', '');
        }

        try {
            $account->refresh();

            if (!$account->tokenExpired()) {
                return (string) $account->credential('access_token', '');
            }

            return $this->refreshAccessToken($account);
        } finally {
            $lock->release();
        }
    }

    private function refreshAccessToken(ChannelAccount $account): string
    {
        $response = Http::timeout(30)->asJson()->post(
            self::API . '/' . $this->version() . '/tt_user/oauth2/refresh_token/',
            [
                'client_id' => config('services.tiktok.app_id'),
                'client_secret' => config('services.tiktok.app_secret'),
                'grant_type' => 'refresh_token',
                'refresh_token' => $account->credential('refresh_token'),
            ]
        );

        $json = $response->json() ?? [];

        if (($json['code'] ?? null) !== 0) {
            Log::error('TikTok token refresh failed', [
                'account_id' => $account->id,
                'response' => $json,
            ]);

            $account->markReauthorizationRequired(
                data_get($json, 'message') ?: __('Could not refresh the TikTok token.')
            );

            return (string) $account->credential('access_token', '');
        }

        $data = $json['data'] ?? [];

        $account->mergeCredentials([
            'access_token' => $data['access_token'] ?? null,
            'refresh_token' => $data['refresh_token'] ?? null,
        ]);

        $account->forceFill([
            'token_expires_at' => now()->addSeconds((int) ($data['expires_in'] ?? 86400)),
            'refresh_token_expires_at' => now()->addSeconds((int) ($data['refresh_token_expires_in'] ?? 2592000)),
            'status' => ChannelAccount::STATUS_ACTIVE,
            'last_error' => null,
        ])->save();

        return (string) ($data['access_token'] ?? '');
    }

    /* ───────────────────────── الحساب ───────────────────────── */

    /* ───────────────────────── التفويض ───────────────────────── */

    public function authorizationUrl(string $state, string $redirectUri): string
    {
        return 'https://www.tiktok.com/v2/auth/authorize?' . http_build_query([
            'client_key' => config('services.tiktok.app_id'),
            'response_type' => 'code',
            'scope' => implode(',', self::REQUIRED_SCOPES),
            'redirect_uri' => $redirectUri,
            'state' => $state,
        ]);
    }

    public function exchangeCode(string $code, string $redirectUri): \App\Services\Messaging\Contracts\OAuthResult
    {
        $response = Http::timeout(30)->asJson()->post(
            self::API . '/' . $this->version() . '/tt_user/oauth2/token/',
            [
                'client_id' => config('services.tiktok.app_id'),
                'client_secret' => config('services.tiktok.app_secret'),
                'grant_type' => 'authorization_code',
                'auth_code' => $code,
                'redirect_uri' => $redirectUri,
            ]
        );

        $json = $response->json() ?? [];

        if (($json['code'] ?? null) !== 0) {
            throw new ChannelConfigurationException(
                $json['message'] ?? __('TikTok rejected the authorization. Try connecting again.')
            );
        }

        $data = $json['data'] ?? [];
        $businessId = (string) ($data['open_id'] ?? '');

        if ($businessId === '') {
            throw new ChannelConfigurationException(__('TikTok did not return an account id.'));
        }

        // الصلاحيات الممنوحة قد تكون أقلّ ممّا طلبنا: تيك توك تسمح للمستخدم
        // برفض بعضها. الربط الناقص يبدو ناجحاً ثم يفشل أول إرسال برسالة
        // غامضة، فنكشفه هنا.
        $granted = array_filter(array_map('trim', explode(',', (string) ($data['scope'] ?? ''))));
        $missing = array_diff(self::REQUIRED_SCOPES, $granted);

        if ($missing !== []) {
            throw new ChannelConfigurationException(__(
                'TikTok did not grant all the permissions we need (missing: :scopes). Connect again and accept all of them.',
                ['scopes' => implode(', ', $missing)]
            ));
        }

        $credentials = [
            'access_token' => $data['access_token'] ?? null,
            'refresh_token' => $data['refresh_token'] ?? null,
            'expires_in' => (int) ($data['expires_in'] ?? 86400),
            'refresh_token_expires_in' => (int) ($data['refresh_token_expires_in'] ?? 2592000),
        ];

        // اسم الحساب وصورته للعرض — فشلهما لا يمنع الربط.
        $profile = $this->businessDetails($businessId, (string) $credentials['access_token']);

        return \App\Services\Messaging\Contracts\OAuthResult::single(
            externalId: $businessId,
            credentials: $credentials,
            name: $profile['display_name'] ?? null,
            username: $profile['username'] ?? null,
            avatar: $profile['profile_image'] ?? null,
        );
    }

    /** @return array{display_name?: string, username?: string, profile_image?: string} */
    private function businessDetails(string $businessId, string $accessToken): array
    {
        try {
            $response = Http::timeout(15)
                ->withHeaders(['Access-Token' => $accessToken])
                ->get(self::API . '/' . $this->version() . '/business/get/', [
                    'business_id' => $businessId,
                    'fields' => json_encode(['username', 'display_name', 'profile_image']),
                ]);

            $json = $response->json() ?? [];

            return ($json['code'] ?? null) === 0 ? ($json['data'] ?? []) : [];
        } catch (\Throwable $e) {
            Log::warning('TikTok business details fetch failed', ['error' => $e->getMessage()]);

            return [];
        }
    }

    public function verifyCredentials(array $credentials): array
    {
        $token = trim((string) ($credentials['access_token'] ?? ''));
        $businessId = trim((string) ($credentials['business_id'] ?? $credentials['external_id'] ?? ''));

        if ($token === '' || $businessId === '') {
            throw new ChannelConfigurationException(
                __('Connect TikTok through the authorization link — the token and business ID come from it.')
            );
        }

        $response = Http::timeout(15)
            ->withHeaders(['Access-Token' => $token])
            ->get(self::API . '/' . $this->version() . '/business/get/', [
                'business_id' => $businessId,
                'fields' => json_encode(['username', 'display_name', 'profile_image']),
            ]);

        $json = $response->json() ?? [];

        if (($json['code'] ?? null) !== 0) {
            throw new ChannelConfigurationException(
                data_get($json, 'message') ?: __('TikTok rejected these credentials.')
            );
        }

        return [
            'external_id' => $businessId,
            'name' => data_get($json, 'data.display_name'),
            'username' => data_get($json, 'data.username'),
            'avatar' => data_get($json, 'data.profile_image'),
        ];
    }

    /**
     * الـ webhook في تيك توك يُضبط على مستوى التطبيق لا الحساب: عنوان واحد
     * لكل العملاء، والتوجيه بعده يتمّ بـ user_openid.
     */
    public function registerWebhook(ChannelAccount $account): bool
    {
        $response = Http::timeout(20)->asJson()->post(
            self::API . '/' . $this->version() . '/business/webhook/update/',
            [
                'app_id' => config('services.tiktok.app_id'),
                'secret' => config('services.tiktok.app_secret'),
                'event_type' => 'DIRECT_MESSAGE',
                'callback_url' => route('channels.webhook', ['channel' => Channel::TIKTOK]),
            ]
        );

        $json = $response->json() ?? [];

        if (($json['code'] ?? null) !== 0) {
            Log::error('TikTok webhook registration failed', ['response' => $json]);

            return false;
        }

        return true;
    }

    public function unregisterWebhook(ChannelAccount $account): bool
    {
        // عنوان مشترك بين كل الحسابات — إلغاؤه لحساب واحد يقطع البقيّة.
        return true;
    }

    /* ───────────────────────── داخلي ───────────────────────── */

    private function version(): string
    {
        return config('services.tiktok.api_version', 'v1.3');
    }

    private function post(ChannelAccount $account, string $path, array $payload): array
    {
        $token = $this->accessToken($account);

        // التوكن ميّت ولا سبيل إلى تجديده: الطلب مرفوض حتماً. إرساله يُهدر
        // رحلة شبكة ويُغرق سجلّ تيك توك بمحاولات فاشلة من حساب واحد، وقد
        // يُسرّع تقييدنا. نردّ خطأً مفهوماً فوراً.
        if ($account->needsReauthorization()) {
            return [
                'code' => -2,
                'message' => __('The TikTok authorization expired. Reconnect the account from Settings → Channels.'),
            ];
        }

        try {
            $response = Http::timeout(30)
                ->withHeaders(['Access-Token' => $token])
                ->asJson()
                ->post(self::API . '/' . $this->version() . $path, array_filter(
                    $payload,
                    static fn ($v) => $v !== null
                ));
        } catch (\Throwable $e) {
            return ['code' => -1, 'message' => $e->getMessage()];
        }

        return $response->json() ?? ['code' => -1, 'message' => 'Invalid response from TikTok'];
    }

    /** إزالة بادئة التنميق قبل إرسال المعرّف إلى تيك توك. */
    private function stripNamespace(string $id): string
    {
        $parts = explode(':', $id);

        return end($parts) ?: $id;
    }

    private function timestamp(mixed $value): ?\DateTimeImmutable
    {
        if (!is_numeric($value)) {
            return null;
        }

        return (new \DateTimeImmutable('@' . intdiv((int) $value, 1000)))
            ->setTimezone(new \DateTimeZone('UTC'));
    }
}
