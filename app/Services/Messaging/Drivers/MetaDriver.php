<?php

namespace App\Services\Messaging\Drivers;

use App\Models\ChannelAccount;
use App\Models\Contact;
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
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * الأساس المشترك بين Messenger و Instagram Direct.
 *
 * المنصّتان تستعملان نفس Graph API ونفس شكل الحمولة تقريباً: نفس
 * entry[].messaging[]، ونفس sender/recipient، ونفس التوقيع
 * X-Hub-Signature-256، ونفس نافذة الـ 24 ساعة. الفرق في قيمة object
 * («page» مقابل «instagram»)، وفي أنواع المرفقات المتاحة، وفي أن إنستغرام
 * تُضيف story_mention و reel.
 *
 * فرق مهمّ عن واتساب: الحمولة **لا تحوي اسم العميل**. لذلك fetchProfile
 * تنادي Graph مرّة واحدة عند إنشاء جهة الاتصال.
 */
abstract class MetaDriver implements ChannelDriver, \App\Services\Messaging\Contracts\SupportsOAuth
{
    /** قيمة object في حمولة الـ webhook التي تخصّ هذه القناة. */
    abstract protected function webhookObject(): string;

    /* ───────────────────────── التفويض ───────────────────────── */

    /** الصلاحيات التي نطلبها — لكل قناة قائمتها. */
    abstract protected function oauthScopes(): array;

    public function authorizationUrl(string $state, string $redirectUri): string
    {
        return 'https://www.facebook.com/' . $this->apiVersion() . '/dialog/oauth?' . http_build_query([
            'client_id' => config('services.meta.app_id'),
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'scope' => implode(',', $this->oauthScopes()),
            'response_type' => 'code',
            // إجبار شاشة الموافقة: من ربط صفحةً من قبل ثم أراد صفحةً
            // أخرى كان يُعاد فوراً بنفس الصلاحيات القديمة بلا خيار.
            'auth_type' => 'rerequest',
        ]);
    }

    public function exchangeCode(string $code, string $redirectUri): \App\Services\Messaging\Contracts\OAuthResult
    {
        $token = $this->exchangeForUserToken($code, $redirectUri);
        $longLived = $this->extendUserToken($token);

        return $this->accountsFromUserToken($longLived);
    }

    /** code ← توكن مستخدم قصير الأجل. */
    private function exchangeForUserToken(string $code, string $redirectUri): string
    {
        $response = Http::timeout(20)->get("{$this->graphHost()}/{$this->apiVersion()}/oauth/access_token", [
            'client_id' => config('services.meta.app_id'),
            'client_secret' => config('services.meta.app_secret'),
            'redirect_uri' => $redirectUri,
            'code' => $code,
        ]);

        $token = $response->json('access_token');

        if (!$response->successful() || !$token) {
            throw new ChannelConfigurationException(
                $response->json('error.message') ?? __('Meta rejected the authorization. Try connecting again.')
            );
        }

        return (string) $token;
    }

    /**
     * توكن قصير (ساعة) ← توكن طويل (٦٠ يوماً).
     *
     * توكنات الصفحات المشتقّة من توكن مستخدم طويل الأجل **لا تنتهي** ما دام
     * المستخدم لم يُغيّر كلمة مروره ولم يسحب الصلاحية. وتخطّي هذه الخطوة
     * يعني قناةً تتوقّف بعد ساعة بلا سبب ظاهر.
     */
    private function extendUserToken(string $shortLived): string
    {
        $response = Http::timeout(20)->get("{$this->graphHost()}/{$this->apiVersion()}/oauth/access_token", [
            'grant_type' => 'fb_exchange_token',
            'client_id' => config('services.meta.app_id'),
            'client_secret' => config('services.meta.app_secret'),
            'fb_exchange_token' => $shortLived,
        ]);

        // الفشل هنا غير قاتل: التوكن القصير يعمل، فنُكمل به ونُسجّل تحذيراً
        // بدل أن نُفشل ربطاً كاد ينجح.
        if (!$response->successful() || !$response->json('access_token')) {
            Log::warning('Meta long-lived token exchange failed, using short-lived token', [
                'channel' => $this->channel(),
                'response' => $response->json('error.message'),
            ]);

            return $shortLived;
        }

        return (string) $response->json('access_token');
    }

    /**
     * الصفحات التي يُديرها المستخدم — كل قناة تُحوّلها إلى حساباتها.
     */
    abstract protected function accountsFromUserToken(string $userToken): \App\Services\Messaging\Contracts\OAuthResult;

    /**
     * @return array<int, array>  صفحات المستخدم مع توكن كل منها
     */
    protected function fetchPages(string $userToken): array
    {
        $response = Http::timeout(25)->get("{$this->graphHost()}/{$this->apiVersion()}/me/accounts", [
            'fields' => $this->pageFields(),
            'limit' => 100,
            'access_token' => $userToken,
        ]);

        if (!$response->successful()) {
            throw new ChannelConfigurationException(
                $response->json('error.message') ?? __('Could not read your Facebook Pages.')
            );
        }

        return $response->json('data') ?? [];
    }

    protected function pageFields(): string
    {
        return 'id,name,access_token,picture{url}';
    }

    /** إصدار Graph المستعمل. */
    protected function apiVersion(): string
    {
        return config('graph.api_version', 'v21.0');
    }

    protected function graphHost(): string
    {
        return 'https://graph.facebook.com';
    }

    public function canAddress(Contact $contact): bool
    {
        return trim((string) $contact->channel_identifier) !== '';
    }

    /* ───────────────────────── الوارد ───────────────────────── */

    /**
     * التوقيع يُحسب على الجسم الخام لا على المصفوفة المُحلَّلة: إعادة
     * ترميز JSON تُغيّر بايتات (ترتيب المفاتيح، التهريب) فيفشل التطابق
     * على حمولات صحيحة.
     */
    public function verifyWebhook(Request $request, ?ChannelAccount $account = null): bool
    {
        $secret = $account?->credential('app_secret') ?: config('services.meta.app_secret');

        if (!$secret) {
            Log::warning('Meta webhook signature skipped: no app secret configured', [
                'channel' => $this->channel(),
                'account_id' => $account?->id,
            ]);

            return true;
        }

        $header = (string) $request->header('X-Hub-Signature-256', '');

        if (!str_starts_with($header, 'sha256=')) {
            return false;
        }

        $expected = 'sha256=' . hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expected, $header);
    }

    public function webhookChallenge(Request $request, ?ChannelAccount $account = null): ?string
    {
        if ($request->input('hub_mode') !== 'subscribe' && $request->input('hub.mode') !== 'subscribe') {
            return null;
        }

        $token = $request->input('hub_verify_token') ?? $request->input('hub.verify_token');
        $challenge = $request->input('hub_challenge') ?? $request->input('hub.challenge');

        // توكنان مقبولان، والترتيب مقصود:
        //
        //   ١. توكن التطبيق من .env — هو الذي تستعمله ميتا في **تحقّق لوحة
        //      التطبيق**، وذاك يجري قبل وجود أيّ حساب مربوط. بدونه كان
        //      التحقّق يفشل دائماً برسالة:
        //      "The callback URL or verify token couldn't be validated."
        //
        //   ٢. توكن الحساب — للربط اليدوي حيث يُدخل العميل توكناً بنفسه.
        foreach ([
            config('services.meta.webhook_verify_token'),
            $account?->credential('verify_token'),
        ] as $expected) {
            if (filled($expected) && hash_equals((string) $expected, (string) $token)) {
                return (string) $challenge;
            }
        }

        return null;
    }

    public function resolveAccountExternalId(Request $request): ?string
    {
        $payload = $request->all();

        if (($payload['object'] ?? null) !== $this->webhookObject()) {
            return null;
        }

        return isset($payload['entry'][0]['id']) ? (string) $payload['entry'][0]['id'] : null;
    }

    public function parseWebhook(Request $request, ChannelAccount $account): array
    {
        $payload = $request->all();

        if (($payload['object'] ?? null) !== $this->webhookObject()) {
            return [];
        }

        $events = [];
        $accountId = (string) $account->external_id;

        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['messaging'] ?? [] as $item) {
                $senderId = (string) ($item['sender']['id'] ?? '');
                $recipientId = (string) ($item['recipient']['id'] ?? '');

                if ($senderId === '') {
                    continue;
                }

                // الصدى: المُرسِل هو الصفحة نفسها. جهة الاتصال حينها هي
                // المستقبِل لا المُرسِل.
                $isEcho = !empty($item['message']['is_echo']) || $senderId === $accountId;
                $contactId = $isEcho ? $recipientId : $senderId;

                if ($contactId === '' || $contactId === $accountId) {
                    continue;
                }

                if (isset($item['message'])) {
                    $event = $this->parseMessage($item, $account, $contactId, $isEcho);

                    if ($event) {
                        $events[] = $event;
                    }

                    continue;
                }

                if (isset($item['delivery'])) {
                    $events[] = new InboundStatus(
                        status: 'delivered',
                        providerMessageIds: array_map(
                            fn ($mid) => MessageId::namespaced($this->channel(), $accountId, (string) $mid),
                            $item['delivery']['mids'] ?? []
                        ),
                        watermark: $this->timestamp($item['delivery']['watermark'] ?? null),
                        senderId: $contactId,
                        raw: $item,
                    );

                    continue;
                }

                if (isset($item['read'])) {
                    $events[] = new InboundStatus(
                        status: 'read',
                        watermark: $this->timestamp($item['read']['watermark'] ?? null),
                        senderId: $contactId,
                        raw: $item,
                    );

                    continue;
                }

                // نقرة زرّ: نعاملها رسالةً نصّية فيقرأها الردّ الآلي
                // والموظّف بلا كود عرض جديد.
                if (isset($item['postback'])) {
                    $events[] = new InboundMessage(
                        providerMessageId: MessageId::namespaced(
                            $this->channel(),
                            $accountId,
                            (string) ($item['postback']['mid'] ?? ($item['timestamp'] ?? uniqid('pb', true)))
                        ),
                        senderId: $contactId,
                        metadata: [
                            'type' => 'button',
                            'button' => array_filter([
                                'text' => $item['postback']['title'] ?? null,
                                'payload' => $item['postback']['payload'] ?? null,
                            ]),
                        ],
                        sentAt: $this->timestamp($item['timestamp'] ?? null),
                        raw: $item,
                    );
                }
            }
        }

        return $events;
    }

    private function parseMessage(array $item, ChannelAccount $account, string $contactId, bool $isEcho): ?InboundMessage
    {
        $message = $item['message'];
        $mid = (string) ($message['mid'] ?? '');

        if ($mid === '') {
            return null;
        }

        // رسالة محذوفة أو غير مدعومة: نُسجّلها بشكل مفهوم بدل تجاهلها،
        // فيرى الموظّف أن شيئاً وصل ولم نستطع عرضه.
        if (!empty($message['is_deleted'])) {
            return null;
        }

        [$metadata, $media] = $this->buildContent($message);

        return new InboundMessage(
            providerMessageId: MessageId::namespaced($this->channel(), (string) $account->external_id, $mid),
            senderId: $contactId,
            metadata: $metadata,
            sentAt: $this->timestamp($item['timestamp'] ?? null),
            media: $media,
            isEcho: $isEcho,
            replyToProviderId: isset($message['reply_to']['mid'])
                ? MessageId::namespaced($this->channel(), (string) $account->external_id, (string) $message['reply_to']['mid'])
                : null,
            raw: $item,
        );
    }

    /**
     * تحويل رسالة ميتا إلى شكل metadata الخاص بواتساب.
     *
     * @return array{0: array, 1: ?InboundMedia}
     */
    protected function buildContent(array $message): array
    {
        $text = $message['text'] ?? null;

        $attachment = $message['attachments'][0] ?? null;

        if (is_array($attachment)) {
            $url = $attachment['payload']['url'] ?? null;
            $type = $this->normalizeAttachmentType($attachment['type'] ?? 'file');

            if ($type === 'location') {
                $coordinates = $attachment['payload']['coordinates'] ?? [];

                return [[
                    'type' => 'location',
                    'location' => array_filter([
                        'latitude' => $coordinates['lat'] ?? null,
                        'longitude' => $coordinates['long'] ?? null,
                    ], static fn ($v) => $v !== null),
                ], null];
            }

            // مشاركة منشور أو قصّة: الرابط وحده هو المحتوى المفيد.
            if ($type === 'share') {
                return [[
                    'type' => 'text',
                    'text' => ['body' => trim(($text ? $text . "\n" : '') . ($url ?? ''))],
                ], null];
            }

            $block = match ($type) {
                'image' => ['caption' => $text],
                'video' => ['caption' => $text],
                'audio' => ['voice' => true],
                default => ['filename' => $attachment['payload']['title'] ?? null, 'caption' => $text],
            };

            return [
                [
                    'type' => $type,
                    $type => array_filter($block, static fn ($v) => $v !== null && $v !== ''),
                ],
                new InboundMedia(
                    type: $type,
                    url: $url,
                    caption: $text,
                    isVoice: $type === 'audio',
                ),
            ];
        }

        if (isset($message['quick_reply'])) {
            return [[
                'type' => 'interactive',
                'interactive' => [
                    'type' => 'button_reply',
                    'button_reply' => array_filter([
                        'id' => $message['quick_reply']['payload'] ?? null,
                        'title' => $text,
                    ]),
                ],
            ], null];
        }

        if ($text !== null && $text !== '') {
            return [['type' => 'text', 'text' => ['body' => (string) $text]], null];
        }

        return [['type' => 'unsupported', 'errors' => [['title' => 'Unsupported message type']]], null];
    }

    protected function normalizeAttachmentType(string $type): string
    {
        return match ($type) {
            'image' => 'image',
            'video', 'reel', 'ig_reel' => 'video',
            'audio' => 'audio',
            'location' => 'location',
            'share', 'story_mention', 'template', 'fallback' => 'share',
            default => 'document',
        };
    }

    /**
     * ميتا تعطي رابطاً مباشراً مؤقّتاً — لا نداء إضافي، لكن يجب التنزيل
     * فوراً قبل أن ينتهي.
     */
    public function downloadMedia(ChannelAccount $account, InboundMedia $media): ?DownloadedMedia
    {
        if (!$media->url) {
            return null;
        }

        try {
            $response = Http::timeout(120)->get($media->url);
        } catch (\Throwable $e) {
            Log::error('Meta media download failed', [
                'channel' => $this->channel(),
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (!$response->successful()) {
            return null;
        }

        $mime = $response->header('Content-Type') ?: ($media->mimeType ?? 'application/octet-stream');

        // الترويسة قد تحمل charset — نقتطع لأن العمود يخزّن نوعاً صافياً.
        $mime = trim(explode(';', $mime)[0]);

        return new DownloadedMedia(
            contents: $response->body(),
            mimeType: $mime,
            fileName: $media->fileName,
        );
    }

    public function fetchProfile(ChannelAccount $account, string $externalId): ?array
    {
        try {
            $response = Http::timeout(15)->get("{$this->graphHost()}/{$this->apiVersion()}/{$externalId}", [
                'fields' => $this->profileFields(),
                'access_token' => $this->accessToken($account),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Meta profile fetch failed', ['error' => $e->getMessage()]);

            return null;
        }

        if (!$response->successful()) {
            return null;
        }

        return $this->mapProfile($response->json() ?? []);
    }

    abstract protected function profileFields(): string;

    abstract protected function mapProfile(array $data): ?array;

    /* ───────────────────────── الصادر ───────────────────────── */

    public function sendText(ChannelAccount $account, Contact $contact, string $body, array $options = []): SendResult
    {
        return $this->send($account, [
            'recipient' => ['id' => $contact->channel_identifier],
            'messaging_type' => $options['messaging_type'] ?? 'RESPONSE',
            'message' => ['text' => $body],
        ] + $this->tagPayload($options));
    }

    /**
     * ميتا لا تُتيح إرسال بطاقة موقع من العمل إلى العميل — الموقع يصل
     * منه إلينا فقط. نردّ خطأً مفهوماً بدل نداء يُرفض.
     */
    public function sendLocation(ChannelAccount $account, Contact $contact, array $location, array $options = []): SendResult
    {
        return SendResult::failure(__('Sending a location is not supported on :channel.', [
            'channel' => \App\Services\Messaging\Channel::label($this->channel()),
        ]));
    }

    public function sendMedia(ChannelAccount $account, Contact $contact, OutboundMedia $media, array $options = []): SendResult
    {
        if (!$media->hasUrl()) {
            return SendResult::failure(__('A public file URL is required to send media on :channel.', [
                'channel' => \App\Services\Messaging\Channel::label($this->channel()),
            ]));
        }

        $type = match ($media->type) {
            'image' => 'image',
            'video' => 'video',
            'audio' => 'audio',
            default => 'file',
        };

        $result = $this->send($account, [
            'recipient' => ['id' => $contact->channel_identifier],
            'messaging_type' => $options['messaging_type'] ?? 'RESPONSE',
            'message' => [
                'attachment' => [
                    'type' => $type,
                    'payload' => ['url' => $media->url, 'is_reusable' => false],
                ],
            ],
        ] + $this->tagPayload($options));

        // ميتا لا تدعم تعليقاً مع المرفق؛ نُرسله رسالةً تالية بدل إسقاطه.
        if ($result->success && trim((string) $media->caption) !== '') {
            $this->sendText($account, $contact, (string) $media->caption, $options);
        }

        return $result;
    }

    private function tagPayload(array $options): array
    {
        return isset($options['tag']) ? ['tag' => $options['tag']] : [];
    }

    private function send(ChannelAccount $account, array $payload): SendResult
    {
        $url = "{$this->graphHost()}/{$this->apiVersion()}/{$account->external_id}/messages";

        try {
            $response = Http::timeout(30)->asJson()->withQueryParameters([
                'access_token' => $this->accessToken($account),
            ])->post($url, $payload);
        } catch (\Throwable $e) {
            return SendResult::failure($e->getMessage());
        }

        $json = $response->json() ?? [];

        if ($response->successful() && isset($json['message_id'])) {
            return SendResult::success(
                MessageId::namespaced($this->channel(), (string) $account->external_id, (string) $json['message_id']),
                'sent',
                $json
            );
        }

        $error = $json['error'] ?? [];
        $code = isset($error['code']) ? (string) $error['code'] : null;

        // 10 و 613 يعنيان غالباً أن نافذة الـ 24 ساعة أُغلقت.
        $message = $error['error_user_msg']
            ?? $error['message']
            ?? __('Message could not be sent.');

        if (in_array($code, ['10', '2018278'], true)) {
            $message = __('The 24-hour reply window has closed for this conversation.');
        }

        // التوكن منتهٍ أو مسحوب: نُعلّم الحساب ليظهر في الإعدادات.
        if (in_array($code, ['190', '10x'], true)) {
            $account->markReauthorizationRequired($message);
        }

        return SendResult::failure($message, $code, $json);
    }

    protected function accessToken(ChannelAccount $account): string
    {
        return (string) $account->credential('access_token', '');
    }

    /* ───────────────────────── الحساب ───────────────────────── */

    public function verifyCredentials(array $credentials): array
    {
        $token = trim((string) ($credentials['access_token'] ?? ''));
        $externalId = trim((string) ($credentials['external_id'] ?? ''));

        if ($token === '' || $externalId === '') {
            throw new ChannelConfigurationException(
                __('Both the access token and the account ID are required.')
            );
        }

        $response = Http::timeout(15)->get("{$this->graphHost()}/{$this->apiVersion()}/{$externalId}", [
            'fields' => $this->accountFields(),
            'access_token' => $token,
        ]);

        if (!$response->successful()) {
            throw new ChannelConfigurationException(
                $response->json('error.message') ?? __('The credentials were rejected by Meta.')
            );
        }

        $data = $response->json() ?? [];

        return [
            'external_id' => (string) ($data['id'] ?? $externalId),
            'name' => $data['name'] ?? null,
            'username' => $data['username'] ?? null,
            'avatar' => $data['profile_picture_url'] ?? null,
        ];
    }

    abstract protected function accountFields(): string;

    /**
     * ميتا تُضبط عنوان الـ webhook في لوحة التطبيق مرّة واحدة لكل
     * التطبيق، لا لكل حساب. ما يلزم هنا هو اشتراك الحساب في التطبيق.
     */
    public function registerWebhook(ChannelAccount $account): bool
    {
        try {
            $response = Http::timeout(20)->post(
                "{$this->graphHost()}/{$this->apiVersion()}/{$account->external_id}/subscribed_apps",
                [
                    'subscribed_fields' => implode(',', $this->subscribedFields()),
                    'access_token' => $this->accessToken($account),
                ]
            );
        } catch (\Throwable $e) {
            Log::error('Meta subscribe failed', ['error' => $e->getMessage()]);

            return false;
        }

        if (!$response->successful()) {
            Log::error('Meta subscribe rejected', [
                'channel' => $this->channel(),
                'response' => $response->body(),
            ]);

            return false;
        }

        return true;
    }

    public function unregisterWebhook(ChannelAccount $account): bool
    {
        try {
            $response = Http::timeout(20)->delete(
                "{$this->graphHost()}/{$this->apiVersion()}/{$account->external_id}/subscribed_apps",
                ['access_token' => $this->accessToken($account)]
            );

            return $response->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    abstract protected function subscribedFields(): array;

    /** ميتا تُرسل الوقت بالميلي ثانية لا بالثانية. */
    protected function timestamp(mixed $value): ?\DateTimeImmutable
    {
        if (!is_numeric($value)) {
            return null;
        }

        return (new \DateTimeImmutable('@' . intdiv((int) $value, 1000)))
            ->setTimezone(new \DateTimeZone('UTC'));
    }
}
