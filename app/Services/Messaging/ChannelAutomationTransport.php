<?php

namespace App\Services\Messaging;

use App\Helpers\MessagingWindowHelper;
use App\Models\Contact;
use App\Services\Broadcasting\BroadcastProvider;
use App\Services\Messaging\Contracts\AutomationSender;
use App\Services\WebChat\WebChatMessagePresenter;
use App\Services\WhatsappService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Mime\MimeTypes;

/**
 * الردّ الآلي على قناة غير واتساب (المحادثة المباشرة الآن).
 *
 * بنفس توقيع WhatsappService ونفس شكل ردّه، فالردود الآلية والـ flows
 * والمساعد الذكي ورسالة ساعات العمل والتقييم ترسل به بلا تغيير في منطقها.
 * لا يُستعمل لواتساب أبداً: AutomationTransport::for يُعيد لواتساب كائنه
 * القديم نفسه.
 *
 * الفروق المقصودة عن واتساب:
 *  - طلب الموقع يُرسَل نصّاً (لا زرّ «أرسل موقعك» خارج واتساب).
 *  - النافذة تُفحص قبل الإرسال: خطوة flow مؤجّلة قد تحلّ بعد إغلاقها على
 *    ماسنجر أو تيك توك، فتفشل بسبب صريح بلا شبكة ولا إعادة محاولة.
 *  - لا يقرأ tempMessageId من الطلب الجاري: هذا إرسال آليّ لا موظّف خلفه.
 *  - لا يرمي أبداً: الـ flow لا يلتقط، والرمي يُعيد المهمّة فتتكرّر رسائل.
 */
class ChannelAutomationTransport implements AutomationSender
{
    private const DEFAULT_MIME = [
        'image' => 'image/jpeg',
        'sticker' => 'image/webp',
        'audio' => 'audio/mpeg',
        'video' => 'video/mp4',
        'document' => 'application/octet-stream',
    ];

    public function __construct(
        private readonly ChannelManager $channels,
        private readonly ChannelOutboundSender $outbound,
        private readonly OutboundMessageRecorder $recorder,
    ) {
    }

    public function sendMessage($contactUuId, $messageContent, $userId = null, $type = 'text', $buttons = [], $header = [], $footer = null, $buttonLabel = null, $location = null)
    {
        return $this->executeSendMessage($contactUuId, $messageContent, $userId, $type, $buttons, $header, $footer, $buttonLabel, null, null, $location);
    }

    public function executeSendMessage(
        $contactUuId,
        $messageContent,
        $userId = null,
        $type = 'text',
        $buttons = [],
        $header = [],
        $footer = null,
        $buttonLabel = null,
        $messageUUID = null,
        $tempMessageId = null,
        $location = null
    ) {
        $context = $this->context($contactUuId);

        if (!is_array($context)) {
            return $context;
        }

        [$contact, $driver, $account] = $context;
        $type = (string) ($type ?: 'text');
        $body = trim((string) $messageContent);
        $record = $this->record($contact, $userId);

        try {
            if ($type === WhatsappService::TYPE_LOCATION) {
                return $this->finish($contact, ChannelOutboundSender::failure(__(':feature is not available on :channel.', [
                    'feature' => __('Location'),
                    'channel' => Channel::label($contact->channel),
                ]), 'unsupported'));
            }

            if ($body === '') {
                return $this->finish($contact, ChannelOutboundSender::failure(__('Message cannot be empty.'), 'empty'));
            }

            if (OutboundInteractive::isInteractiveType($type)) {
                $interactive = OutboundInteractive::fromWhatsappArgs($type, $body, $buttons, $header, $footer, $buttonLabel);

                if ($interactive !== null) {
                    return $this->finish($contact, $this->outbound->deliverInteractive($driver, $account, $contact, $interactive, $record));
                }
            }

            // نصّ — وطلب الموقع نصّاً كذلك.
            return $this->finish($contact, $this->outbound->deliver($driver, $account, $contact, $body, null, $record));
        } catch (\Throwable $e) {
            return $this->crashed($contact, $e);
        }
    }

    public function sendMedia($contactUuId, $mediaType, $mediaFileName, $mediaFilePath, $mediaUrl, $location, $caption = null, $transcription = null, $userId = null, $tempMessageId = null, $messageUUID = null, $existingChatId = null)
    {
        $context = $this->context($contactUuId);

        if (!is_array($context)) {
            return $context;
        }

        [$contact, $driver, $account] = $context;

        try {
            $url = $this->publicUrl((string) ($mediaUrl ?: $mediaFilePath));

            if ($url === null) {
                return $this->finish($contact, ChannelOutboundSender::failure(__('The file has no public link.'), 'media_url_missing'));
            }

            $type = (string) $mediaType;
            $name = trim((string) $mediaFileName) ?: basename((string) parse_url($url, PHP_URL_PATH));
            $caption = $type === 'audio' ? '' : trim((string) $caption);
            $record = $this->record($contact, $userId);

            // نصّ الردّ الصوتي: سجلّ المساعد الذكي يقرؤه من هنا كما في واتساب.
            if ($type === 'audio' && trim((string) $transcription) !== '') {
                $record['metadata'] = ['transcript' => (string) $transcription];
            }

            return $this->finish($contact, $this->outbound->deliver($driver, $account, $contact, $caption, [
                'type' => $type,
                'url' => $url,
                'name' => $name,
                'mime_type' => $this->mimeType($name ?: $url, $type),
                'size' => $this->size((string) $mediaFilePath, (string) $location),
                'location' => $location === 'amazon' ? 'amazon' : 'local',
            ], $record));
        } catch (\Throwable $e) {
            return $this->crashed($contact, $e);
        }
    }

    /**
     * جهة الاتصال وسائقها وحسابها — أو ردّ فشل بسبب صريح.
     *
     * @return array{0: Contact, 1: \App\Services\Messaging\Contracts\ChannelDriver, 2: \App\Models\ChannelAccount}|object
     */
    private function context($contactUuId): array|object
    {
        try {
            $contact = Contact::where('uuid', (string) $contactUuId)->first();

            if (!$contact) {
                return ChannelOutboundSender::failure(__('Contact not found.'), 'contact_not_found');
            }

            $channel = $contact->channel ?: Channel::DEFAULT;

            if (!AutomationChannels::allows($contact)) {
                return ChannelOutboundSender::failure(__('Automated replies are off on :channel.', ['channel' => Channel::label($channel)]), 'automation_disabled');
            }

            $account = $this->channels->accountForContact($contact);

            if (!$account || !$account->isActive()) {
                return ChannelOutboundSender::failure(__(':channel is not connected.', ['channel' => Channel::label($channel)]), 'not_connected');
            }

            $driver = $this->channels->driver($channel);

            if (!$driver->canAddress($contact)) {
                return ChannelOutboundSender::failure(__('This contact has no :channel identifier.', ['channel' => Channel::label($channel)]), 'not_addressable');
            }

            if (!MessagingWindowHelper::isMessagingWindowOpen($contact)) {
                return ChannelOutboundSender::failure(__('The reply window is closed for this contact.'), 'window_closed');
            }

            // الردّ الآلي يجري في عامل الطابور: البثّ يأخذ إعداد مزوّده من هنا.
            BroadcastProvider::apply();

            return [$contact, $driver, $account];
        } catch (\Throwable $e) {
            Log::error('Automation transport could not resolve the contact', [
                'contact_uuid' => $contactUuId,
                'error' => $e->getMessage(),
            ]);

            return ChannelOutboundSender::failure($e->getMessage(), 'exception');
        }
    }

    private function record(Contact $contact, $userId): array
    {
        return [
            // 0 أو null: الـ flow ينهي جلسته عند كل رسالة صادرة بموظّف (> 0).
            'user_id' => $userId,
            'channel' => $contact->channel,
            'log' => ['source' => 'automation'],
        ];
    }

    /** webhook «message.sent» كما يُطلقه WhatsappService — في النجاح والفشل. */
    private function finish(Contact $contact, object $response): object
    {
        try {
            $this->recorder->fireSentWebhook((int) $contact->organization_id, $response);
        } catch (\Throwable $e) {
            Log::warning('Automation transport: message.sent webhook failed', [
                'contact_id' => $contact->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $response;
    }

    private function crashed(Contact $contact, \Throwable $e): object
    {
        Log::error('Automation transport send threw', [
            'contact_id' => $contact->id,
            'channel' => $contact->channel,
            'error' => $e->getMessage(),
        ]);

        return ChannelOutboundSender::failure($e->getMessage(), 'exception');
    }

    /** رابط يفتحه العميل: المطلق كما هو، والمسار المحلّي عبر /media. */
    private function publicUrl(string $value): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        return WebChatMessagePresenter::safeUrl(WebChatMessagePresenter::publicUrl(str_replace('\\', '/', $value)));
    }

    private function mimeType(string $nameOrUrl, string $type): string
    {
        $extension = strtolower(pathinfo((string) parse_url($nameOrUrl, PHP_URL_PATH) ?: $nameOrUrl, PATHINFO_EXTENSION));

        if ($extension !== '') {
            $guess = MimeTypes::getDefault()->getMimeTypes($extension)[0] ?? null;

            if ($guess) {
                return $guess;
            }
        }

        return self::DEFAULT_MIME[$type] ?? 'application/octet-stream';
    }

    /**
     * حجم الملف إن كان عندنا. لا نُنزّل ملفاً من رابطه لنعرف حجمه (كما يفعل
     * WhatsappService): الحجم للعرض وحده، و0 يعني «غير معروف».
     */
    private function size(string $path, string $location): int
    {
        if ($location === 'amazon' || $path === '' || preg_match('#^https?://#i', $path)) {
            return 0;
        }

        try {
            $path = ltrim(str_replace('\\', '/', $path), '/');

            return Storage::disk('local')->exists($path) ? (int) Storage::disk('local')->size($path) : 0;
        } catch (\Throwable) {
            return 0;
        }
    }
}
