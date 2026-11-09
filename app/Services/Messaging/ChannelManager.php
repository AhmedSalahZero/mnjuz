<?php

namespace App\Services\Messaging;

use App\Models\ChannelAccount;
use App\Models\Contact;
use App\Models\Organization;
use App\Services\Messaging\Contracts\ChannelDriver;
use App\Services\Messaging\Drivers\InstagramDriver;
use App\Services\Messaging\Drivers\MessengerDriver;
use App\Services\Messaging\Drivers\TelegramDriver;
use App\Services\Messaging\Drivers\TikTokDriver;
use App\Services\Messaging\Drivers\WebChatDriver;
use App\Services\Messaging\Drivers\WhatsappDriver;
use App\Services\Messaging\Exceptions\ChannelException;

/**
 * نقطة واحدة تُعطي السائق المناسب.
 *
 * من يريد إرسال رسالة لا يبني سائقاً بنفسه: يطلب من هنا بجهة الاتصال،
 * فتبقى معرفة «أي منصّة لأي جهة اتصال» في مكان واحد.
 *
 * السائقون بلا حالة (stateless): الحساب يُمرَّر في كل نداء لا يُحقن في
 * المُنشئ. هذا يجعل سائقاً واحداً يخدم كل حسابات المنصّة في نفس الطلب،
 * ويمنع الخلط بين حسابين عند معالجة دفعة من الرسائل.
 */
class ChannelManager
{
    /** @var array<string, ChannelDriver> */
    private array $drivers = [];

    /** @var array<string, class-string<ChannelDriver>> */
    private const DRIVER_MAP = [
        Channel::WHATSAPP => WhatsappDriver::class,
        Channel::TELEGRAM => TelegramDriver::class,
        Channel::MESSENGER => MessengerDriver::class,
        Channel::INSTAGRAM => InstagramDriver::class,
        Channel::TIKTOK => TikTokDriver::class,
        Channel::WEBCHAT => WebChatDriver::class,
    ];

    /** السائق الخاصّ بقناة. */
    public function driver(string $channel): ChannelDriver
    {
        $channel = Channel::normalize($channel)
            ?? throw new ChannelException("Unknown channel [{$channel}].");

        return $this->drivers[$channel] ??= app(self::DRIVER_MAP[$channel]);
    }

    /** سائق جهة الاتصال — القناة مخزَّنة على الصفّ نفسه. */
    public function forContact(Contact $contact): ChannelDriver
    {
        return $this->driver($contact->channel ?? Channel::DEFAULT);
    }

    /** قدرات قناة بلا بناء سياق كامل. */
    public function capabilities(string $channel): ChannelCapabilities
    {
        return $this->driver($channel)->capabilities();
    }

    /**
     * حساب جهة الاتصال. واتساب وحدها بلا صفّ في channel_accounts لأن
     * بياناتها ما زالت في organizations.metadata — نُركّب لها حساباً
     * افتراضياً في الذاكرة ليتوحّد التوقيع على كل القنوات.
     */
    public function accountForContact(Contact $contact): ?ChannelAccount
    {
        $channel = $contact->channel ?? Channel::DEFAULT;

        if ($channel === Channel::WHATSAPP) {
            return $this->whatsappPseudoAccount((int) $contact->organization_id);
        }

        if ($contact->channel_account_id) {
            return ChannelAccount::find($contact->channel_account_id);
        }

        return ChannelAccount::query()
            ->where('organization_id', $contact->organization_id)
            ->where('channel', $channel)
            ->active()
            ->first();
    }

    /** كل حسابات منشأة، أو حسابات قناة منها. */
    public function accountsFor(int $organizationId, ?string $channel = null)
    {
        return ChannelAccount::query()
            ->where('organization_id', $organizationId)
            ->when($channel, fn ($q) => $q->where('channel', $channel))
            ->orderBy('channel')
            ->get();
    }

    /** أول حساب فعّال لقناة في منشأة، أو null. */
    public function activeAccount(int $organizationId, string $channel): ?ChannelAccount
    {
        if ($channel === Channel::WHATSAPP) {
            return $this->whatsappPseudoAccount($organizationId);
        }

        return ChannelAccount::query()
            ->where('organization_id', $organizationId)
            ->where('channel', $channel)
            ->active()
            ->first();
    }

    /** القنوات التي ربطتها المنشأة فعلاً (واتساب محسوبة من metadata). */
    public function connectedChannels(int $organizationId): array
    {
        $channels = [];

        if ($this->whatsappPseudoAccount($organizationId) !== null) {
            $channels[] = Channel::WHATSAPP;
        }

        $others = ChannelAccount::query()
            ->where('organization_id', $organizationId)
            ->active()
            ->pluck('channel')
            ->unique()
            ->values()
            ->all();

        return array_values(array_unique(array_merge($channels, $others)));
    }

    /** القنوات المربوطة التي تصلح للحملات (المحادثة المباشرة ليست منها). */
    public function campaignChannels(int $organizationId): array
    {
        return array_values(array_filter(
            $this->connectedChannels($organizationId),
            fn (string $channel) => $this->capabilities($channel)->campaigns
        ));
    }

    /** كل القنوات التي تدعم الحملات، مربوطةً أم لا — لقواعد التحقّق. */
    public function campaignCapableChannels(): array
    {
        return array_values(array_filter(
            Channel::all(),
            fn (string $channel) => $this->capabilities($channel)->campaigns
        ));
    }

    /**
     * حساب واتساب مُركَّب من organizations.metadata.whatsapp.
     *
     * لا يُحفظ في قاعدة البيانات ولا يُعطى id: هو قشرة تُوحّد التوقيع فقط.
     * نقل واتساب إلى channel_accounts فعلياً عملية ترحيل بيانات مستقلّة،
     * وليست شرطاً لعمل بقيّة القنوات.
     */
    public function whatsappPseudoAccount(int $organizationId): ?ChannelAccount
    {
        static $cache = [];

        if (array_key_exists($organizationId, $cache)) {
            return $cache[$organizationId];
        }

        $organization = Organization::find($organizationId);

        if (!$organization) {
            return $cache[$organizationId] = null;
        }

        $metadata = $organization->metadata ? json_decode($organization->metadata, true) : [];
        $whatsapp = $metadata['whatsapp'] ?? null;

        if (!is_array($whatsapp) || empty($whatsapp['access_token']) || empty($whatsapp['phone_number_id'])) {
            return $cache[$organizationId] = null;
        }

        $account = new ChannelAccount([
            'organization_id' => $organizationId,
            'channel' => Channel::WHATSAPP,
            'external_id' => (string) $whatsapp['phone_number_id'],
            'name' => $whatsapp['verified_name'] ?? $organization->name,
            'status' => ChannelAccount::STATUS_ACTIVE,
        ]);

        // exists = false يمنع أي حفظ عرَضي لهذا الكائن المُركَّب.
        $account->exists = false;
        $account->credentials = $whatsapp;

        return $cache[$organizationId] = $account;
    }

    /**
     * رسالة خطأ صالحة للعرض إن كانت القناة غير مربوطة، أو null إن كانت
     * جاهزة. تعميم WhatsappConnectionHelper على كل القنوات.
     */
    public function connectionError(int $organizationId, string $channel): ?string
    {
        if ($channel === Channel::WHATSAPP) {
            return \App\Helpers\WhatsappConnectionHelper::errorFor($organizationId);
        }

        $account = $this->activeAccount($organizationId, $channel);

        if (!$account) {
            return __(':channel is not connected. Connect it from Settings → Channels, then try again.', [
                'channel' => Channel::label($channel),
            ]);
        }

        if ($account->needsReauthorization()) {
            return __('The :channel connection expired. Reconnect it from Settings → Channels.', [
                'channel' => Channel::label($channel),
            ]);
        }

        return null;
    }
}
