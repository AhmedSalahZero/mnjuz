<?php

namespace App\Services\Messaging\Drivers;

use App\Models\ChannelAccount;
use App\Models\Contact;
use App\Services\Messaging\Channel;
use App\Services\Messaging\ChannelCapabilities;
use App\Services\Messaging\Contracts\ChannelDriver;
use App\Services\Messaging\Contracts\DownloadedMedia;
use App\Services\Messaging\InboundMedia;
use App\Services\Messaging\OutboundMedia;
use App\Services\Messaging\SendResult;
use App\Services\WhatsappService;
use Illuminate\Http\Request;

/**
 * غلاف رقيق فوق WhatsappService القائم.
 *
 * قاعدة صارمة هنا: **لا يتغيّر سلوك واتساب بحرف واحد**. هذا السائق لا
 * يُعيد تنفيذ شيء، بل يُنادي نفس الدوالّ بنفس المعاملات. الغرض منه أن
 * يتمكّن الكود الأعلى من التعامل مع كل القنوات بصيغة واحدة، لا أن يُحسّن
 * واتساب.
 *
 * وWebhook واتساب يبقى على مساره القديم (WebhookController) ولا يمرّ من
 * هنا — لذلك parseWebhook وأخواتها ترمي أو تُرجع فارغاً عمداً: استدعاؤها
 * يعني خطأً في التوجيه لا حالةً مشروعة.
 */
class WhatsappDriver implements ChannelDriver
{
    public function channel(): string
    {
        return Channel::WHATSAPP;
    }

    public function capabilities(): ChannelCapabilities
    {
        return new ChannelCapabilities(
            windowHours: 24,
            templates: true,
            interactive: true,
            location: true,
            locationRequest: true,
            mediaCaption: true,
            textWithMedia: true,
            multipleAttachments: false,
            deliveryReceipts: true,
            readReceipts: true,
            blockContact: true,
            reactions: true,
            replyTo: true,
            mediaLimits: [
                'image' => 5 * 1024 * 1024,
                'video' => 16 * 1024 * 1024,
                'audio' => 16 * 1024 * 1024,
                'document' => 100 * 1024 * 1024,
                'sticker' => 500 * 1024,
            ],
            imageMimeTypes: ['image/jpeg', 'image/png'],
            campaigns: true,
        );
    }

    public function canAddress(Contact $contact): bool
    {
        return WhatsappService::isSendableNumber($contact);
    }

    /* ───────────────────────── الوارد ───────────────────────── */

    /**
     * واتساب لا تمرّ من هنا: مسارها /webhook/whatsapp/{identifier} كما هو.
     * التحقّق يبقى حيث هو حتى لا نُغيّر سلوكاً يعمل على الإنتاج.
     */
    public function verifyWebhook(Request $request, ?ChannelAccount $account = null): bool
    {
        return true;
    }

    public function webhookChallenge(Request $request, ?ChannelAccount $account = null): ?string
    {
        return null;
    }

    public function resolveAccountExternalId(Request $request): ?string
    {
        return null;
    }

    public function parseWebhook(Request $request, ChannelAccount $account): array
    {
        return [];
    }

    /**
     * تنزيل وسائط واتساب يبقى في ProcessMediaDownloadJob بمنطقه المُجرَّب
     * (نداء Graph للحصول على الرابط ثم تنزيله بالتوكن).
     */
    public function downloadMedia(ChannelAccount $account, InboundMedia $media): ?DownloadedMedia
    {
        return null;
    }

    public function fetchProfile(ChannelAccount $account, string $externalId): ?array
    {
        return null;
    }

    /* ───────────────────────── الصادر ───────────────────────── */

    public function sendText(ChannelAccount $account, Contact $contact, string $body, array $options = []): SendResult
    {
        $service = $this->service($account);

        $response = $service->executeSendMessage(
            $contact->uuid,
            $body,
            $options['user_id'] ?? null,
            $options['type'] ?? 'text',
            $options['buttons'] ?? [],
            $options['header'] ?? [],
            $options['footer'] ?? null,
            $options['button_label'] ?? null,
            $options['message_uuid'] ?? null,
            $options['temp_message_id'] ?? null,
            $options['location'] ?? null
        );

        return $this->toSendResult($response);
    }

    public function sendLocation(ChannelAccount $account, Contact $contact, array $location, array $options = []): SendResult
    {
        $response = $this->service($account)->sendLocation(
            $contact->uuid,
            $location,
            $options['user_id'] ?? null
        );

        return $this->toSendResult($response);
    }

    public function sendMedia(ChannelAccount $account, Contact $contact, OutboundMedia $media, array $options = []): SendResult
    {
        $service = $this->service($account);

        $response = $service->sendMedia(
            $contact->uuid,
            $media->type,
            $media->fileName,
            $media->path,
            $media->url,
            $options['location'] ?? 'local',
            $media->caption,
            $options['transcription'] ?? null,
            $options['user_id'] ?? null,
            $options['temp_message_id'] ?? null,
            $options['message_uuid'] ?? null,
            $options['existing_chat_id'] ?? null
        );

        return $this->toSendResult($response);
    }

    /* ───────────────────────── الحساب ───────────────────────── */

    public function verifyCredentials(array $credentials): array
    {
        // ربط واتساب يمرّ بمسار الإعدادات القائم و EmbeddedSignup، ولا
        // يُنشأ من صفحة القنوات الجديدة.
        return [
            'external_id' => (string) ($credentials['phone_number_id'] ?? ''),
            'name' => $credentials['verified_name'] ?? null,
        ];
    }

    public function registerWebhook(ChannelAccount $account): bool
    {
        return true;
    }

    public function unregisterWebhook(ChannelAccount $account): bool
    {
        return true;
    }

    /* ───────────────────────── داخلي ───────────────────────── */

    /**
     * بناء WhatsappService بنفس ما يفعله ChatService::initializeWhatsappService
     * حرفياً — نفس المفاتيح ونفس مصدر الإعداد.
     */
    private function service(ChannelAccount $account): WhatsappService
    {
        $credentials = $account->credentials ?? [];

        return new WhatsappService(
            $credentials['access_token'] ?? null,
            config('graph.api_version'),
            $credentials['app_id'] ?? null,
            $credentials['phone_number_id'] ?? null,
            $credentials['waba_id'] ?? null,
            (int) $account->organization_id
        );
    }

    /** ردّ WhatsappService الخام إلى SendResult موحّدة. */
    private function toSendResult(mixed $response): SendResult
    {
        if (!is_object($response) || ($response->success ?? false) !== true) {
            $error = WhatsappService::describeSendError($response);

            return SendResult::failure(
                $error['message'] ?? ($response->message ?? __('Message could not be sent.')),
                isset($error['code']) ? (string) $error['code'] : null,
                $response
            );
        }

        return SendResult::success(
            $response->data->messages[0]->id ?? null,
            'delivered',
            $response
        );
    }
}
