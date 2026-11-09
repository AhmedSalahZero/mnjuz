<?php

namespace App\Services\Messaging;

use App\Helpers\MessagingWindowHelper;
use App\Models\Campaign;
use App\Models\Contact;
use App\Services\ContactPlaceholderService;

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
        private readonly ChannelOutboundSender $outbound,
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

        return $this->outbound->deliver($driver, $account, $contact, $text, $media, [
            'user_id' => $userId,
            'channel' => $channel,
            'log' => ['campaign_id' => $campaign->id],
        ]);
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
        return ChannelOutboundSender::failure($message, $code);
    }
}
