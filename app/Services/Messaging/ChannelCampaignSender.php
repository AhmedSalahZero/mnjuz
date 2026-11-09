<?php

namespace App\Services\Messaging;

use App\Helpers\MessagingWindowHelper;
use App\Models\Campaign;
use App\Models\Contact;
use App\Services\ContactPlaceholderService;
use Illuminate\Support\Facades\Log;

/**
 * إرسال رسالة حملة على قناة غير واتساب.
 *
 * حملة واتساب تمرّ على القوالب كما كانت — لم نلمسها. وهذه تُرسل رسالة حرّة
 * (نصّ + وسائط اختيارية) لمن نافذته مفتوحة.
 *
 * نقطة تصميم: نُرجع كائناً بنفس شكل ردّ WhatsappService
 * (`{success, data->chat, data->messages[0]->id}`) لأن
 * ProcessSingleCampaignLogJob يقرأ هذا الشكل ويكتبه في campaign_logs.metadata
 * ويستعمله CampaignRetryService لاستخراج سبب الفشل. تغييره يعني تعديل أربعة
 * ملفّات ومسار إعادة المحاولة كلّه.
 */
class ChannelCampaignSender
{
    public function __construct(
        private readonly ChannelManager $channels,
        private readonly OutboundMessageRecorder $recorder,
    ) {
    }

    /**
     * @return object  بنفس شكل ردّ WhatsappService
     */
    public function send(Campaign $campaign, Contact $contact, $userId = null): object
    {
        $channel = $campaign->channel ?? Channel::DEFAULT;
        $driver = $this->channels->driver($channel);

        $account = $campaign->channel_account_id
            ? \App\Models\ChannelAccount::find($campaign->channel_account_id)
            : $this->channels->activeAccount((int) $campaign->organization_id, $channel);

        if (!$account || !$account->isActive()) {
            return $this->failure(__(':channel is not connected.', ['channel' => Channel::label($channel)]));
        }

        if (!$driver->canAddress($contact)) {
            return $this->failure(__('This contact has no :channel identifier.', [
                'channel' => Channel::label($channel),
            ]));
        }

        // النافذة: تيليجرام بلا نافذة، وميتا ٢٤ ساعة، وتيك توك ٤٨.
        //
        // نفشل بسبب صريح لا بخطأ المنصّة: «نافذة مغلقة» حالة متوقّعة لا
        // عطب، ومعرفتها تُغني عن إعادة محاولة لن تنجح حتى يكتب العميل.
        if (!MessagingWindowHelper::isMessagingWindowOpen($contact)) {
            return $this->failure(
                __('The reply window is closed for this contact.'),
                'window_closed'
            );
        }

        $payload = $campaign->messagePayload();
        $text = $this->resolvePlaceholders($campaign, $contact, $payload['text']);
        $media = $payload['media'];

        try {
            [$result, $metadata, $mediaRow] = $this->dispatch($driver, $account, $contact, $text, $media, $channel);
        } catch (\Throwable $e) {
            Log::error('Channel campaign send threw', [
                'campaign_id' => $campaign->id,
                'contact_id' => $contact->id,
                'channel' => $channel,
                'error' => $e->getMessage(),
            ]);

            return $this->failure($e->getMessage());
        }

        if (!$result->success) {
            return $this->failure($result->errorMessage ?? __('Message could not be sent.'), $result->errorCode);
        }

        $chat = $mediaRow
            ? $this->recorder->recordMedia($contact, $result->providerMessageId, $metadata, $mediaRow, [
                'user_id' => $userId,
                'status' => $result->status,
                'channel' => $channel,
            ])
            : $this->recorder->recordText($contact, $result->providerMessageId, $metadata, [
                'user_id' => $userId,
                'status' => $result->status,
                'channel' => $channel,
            ]);

        $response = $result->toLegacyObject();
        $response->data->chat = $chat;

        return $response;
    }

    /**
     * اختيار الإرسال: وسائط أم نصّ.
     *
     * القناة التي لا تجمع نصّاً وصورة (تيك توك) تُرسل الصورة وحدها ثم النصّ
     * رسالةً تالية — والسائق هو من يعرف ذلك لا نحن.
     *
     * @return array{0: SendResult, 1: array, 2: ?array}
     */
    private function dispatch($driver, $account, Contact $contact, string $text, ?array $media, string $channel): array
    {
        if (!$media || empty($media['url'])) {
            return [
                $driver->sendText($account, $contact, $text),
                ['type' => 'text', 'text' => ['body' => $text]],
                null,
            ];
        }

        $type = $media['type'] ?? 'image';
        $capabilities = $driver->capabilities();

        $outbound = new OutboundMedia(
            type: $type,
            url: $media['url'],
            fileName: $media['name'] ?? null,
            mimeType: $media['mime_type'] ?? null,
            size: isset($media['size']) ? (int) $media['size'] : null,
            caption: $capabilities->textWithMedia ? $text : null,
        );

        $result = $driver->sendMedia($account, $contact, $outbound);

        // النصّ لم يُرسَل مع الصورة لأن القناة لا تسمح: نُرسله تالياً حتى
        // لا تصل الحملة صورةً صامتة بلا رسالتها.
        if ($result->success && !$capabilities->textWithMedia && trim($text) !== '') {
            $driver->sendText($account, $contact, $text);
        }

        return [
            $result,
            [
                'type' => $type,
                $type => array_filter(
                    ['caption' => $capabilities->mediaCaption ? $text : null, 'filename' => $media['name'] ?? null],
                    static fn ($v) => $v !== null && $v !== ''
                ),
            ],
            [
                'name' => $media['name'] ?? 'N/A',
                'path' => $media['url'],
                'location' => $media['location'] ?? 'local',
                'type' => $media['mime_type'] ?? null,
                'size' => $media['size'] ?? null,
            ],
        ];
    }

    /**
     * استبدال {first_name} وأخواتها.
     *
     * نستعمل صيغة القوس المفرد لا {{...}}: الأولى هي اصطلاح المشروع كلّه
     * — الردود الجاهزة وساعات العمل والردّ الآلي — وهي ما يُدرجه منتقي
     * المتغيّرات في الواجهة. والصيغة المزدوجة خاصّة بقوالب واتساب وحدها،
     * واستعمالها هنا يعني متغيّرات تظهر في القائمة ولا تُستبدل عند الإرسال.
     */
    private function resolvePlaceholders(Campaign $campaign, Contact $contact, string $text): string
    {
        if ($text === '' || !str_contains($text, '{')) {
            return $text;
        }

        return ContactPlaceholderService::replace(
            (int) $campaign->organization_id,
            $contact->uuid,
            $text
        );
    }

    /** فشل بنفس شكل ردّ WhatsappService حتى يقرأه مسار الحملة القائم. */
    private function failure(string $message, ?string $code = null): object
    {
        return (object) [
            'success' => false,
            'message' => $message,
            'data' => (object) [
                'error' => (object) array_filter([
                    'message' => $message,
                    'code' => $code,
                ], static fn ($v) => $v !== null),
            ],
        ];
    }
}
