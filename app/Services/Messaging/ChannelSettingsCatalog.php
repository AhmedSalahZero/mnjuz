<?php

namespace App\Services\Messaging;

use App\Models\ChannelAccount;
use App\Services\Messaging\Contracts\SupportsOAuth;
use App\Services\WebChat\WebChatSettings;
use App\Services\WebChat\WebChatWidgetService;

/**
 * ما تعرضه صفحة «القنوات»: بطاقة لكل منصّة تُربط من الإعدادات، وسطر حالة
 * للبريد وللمحادثة المباشرة.
 *
 * كانت كلّها دوالاً خاصّة في ChannelAccountController؛ نُقلت هنا كما هي
 * ليقرأها تطبيق الجوال من المصدر نفسه. لا جلسة هنا: المنشأة تُمرَّر صراحة.
 */
class ChannelSettingsCatalog
{
    public function __construct(private readonly ChannelManager $channels)
    {
    }

    /**
     * بطاقة لكل قناة: ما تحتاجه الواجهة لعرض التبويب — الحقول المطلوبة،
     * والحساب المربوط إن وُجد، وقيود المنصّة ليعرفها المستخدم قبل أن يعد
     * عميله بما لا نستطيع.
     */
    public function cards(int $organizationId): array
    {
        $accounts = $this->channels->accountsFor($organizationId)->keyBy('channel');

        $cards = [];

        foreach (Channel::connectable() as $channel) {
            $capabilities = $this->channels->capabilities($channel);
            $account = $accounts->get($channel);

            $driver = $this->channels->driver($channel);
            $usesOAuth = $driver instanceof SupportsOAuth;

            $cards[] = [
                'channel' => $channel,
                // يُترجم هنا عند العرض: label() نفسها تغذّي رسائل الخادم أيضاً.
                'label' => __(Channel::label($channel)),
                'account' => $account?->toSettingsArray(),
                'fields' => $this->fieldsFor($channel),
                'capabilities' => $capabilities->toArray(),
                'notes' => $this->notesFor($channel),
                // القناة التي تُربط بتفويض تُخفي نموذج اللصق وتعرض زرّاً.
                'uses_oauth' => $usesOAuth,
                // ومضبوطة على الخادم أم لا: زرّ يقود إلى خطأ أسوأ من
                // رسالة تقول إن المنصّة لم تُضبط بعد.
                'platform_ready' => $usesOAuth ? $this->platformConfigured($channel) : true,
            ];
        }

        return $cards;
    }

    /** بطاقة قناة واحدة، أو null إن لم تكن ممّا يُربط من الإعدادات. */
    public function card(int $organizationId, string $channel): ?array
    {
        return collect($this->cards($organizationId))->firstWhere('channel', $channel);
    }

    /** سطر حالة بريد الحملات (SMTP التاجر). لا شيء من بيانات الاتصال. */
    public function emailSummary(int $organizationId): array
    {
        $account = ChannelAccount::query()
            ->where('organization_id', $organizationId)
            ->where('channel', Channel::EMAIL)
            ->first();

        return [
            'connected' => $account !== null && $account->isActive(),
            'needs_attention' => $account !== null && !$account->isActive(),
            'from_address' => $account?->username,
        ];
    }

    /** سطر حالة المحادثة المباشرة: مفعّلة أم موقوفة، أم لم تُنشأ بعد. */
    public function webChatSummary(int $organizationId): array
    {
        $widget = app(WebChatWidgetService::class)->forOrganization($organizationId);

        return [
            'exists' => $widget !== null,
            'enabled' => $widget !== null && (bool) (WebChatSettings::for($widget)['enabled'] ?? false),
        ];
    }

    /** هل ضُبط تطبيق المنصّة على الخادم؟ (إعداد لمرّة واحدة لكل المنصّة) */
    public function platformConfigured(string $channel): bool
    {
        return match ($channel) {
            Channel::MESSENGER, Channel::INSTAGRAM => filled(config('services.meta.app_id'))
                && filled(config('services.meta.app_secret')),
            Channel::TIKTOK => filled(config('services.tiktok.app_id'))
                && filled(config('services.tiktok.app_secret')),
            default => true,
        };
    }

    /**
     * الحقول التي يملؤها المستخدم لكل منصّة.
     *
     * تُوصف هنا لا في الواجهة: إضافة منصّة جديدة تلمس ملفّاً واحداً.
     */
    public function fieldsFor(string $channel): array
    {
        return match ($channel) {
            Channel::TELEGRAM => [
                ['key' => 'bot_token', 'label' => __('Bot token'), 'type' => 'password', 'hint' => __('Get it from :bot on Telegram.', ['bot' => '@BotFather'])],
            ],
            Channel::MESSENGER => [
                ['key' => 'external_id', 'label' => __('Facebook Page ID'), 'type' => 'text', 'hint' => null],
                ['key' => 'access_token', 'label' => __('Page access token'), 'type' => 'password', 'hint' => __('A long-lived page token with messaging permissions.')],
                ['key' => 'verify_token', 'label' => __('Webhook verify token'), 'type' => 'text', 'hint' => __('Any value you choose — enter the same one in the Meta app.')],
            ],
            Channel::INSTAGRAM => [
                ['key' => 'external_id', 'label' => __('Instagram account ID'), 'type' => 'text', 'hint' => null],
                ['key' => 'access_token', 'label' => __('Access token'), 'type' => 'password', 'hint' => __('The token of the Facebook Page linked to this Instagram account.')],
                ['key' => 'verify_token', 'label' => __('Webhook verify token'), 'type' => 'text', 'hint' => null],
            ],
            Channel::TIKTOK => [
                ['key' => 'business_id', 'label' => __('Business account ID'), 'type' => 'text', 'hint' => null],
                ['key' => 'access_token', 'label' => __('Access token'), 'type' => 'password', 'hint' => null],
                ['key' => 'refresh_token', 'label' => __('Refresh token'), 'type' => 'password', 'hint' => __('Needed — the access token expires every 24 hours.')],
            ],
            default => [],
        };
    }

    /** قيود المنصّة كما ينبغي أن يعرفها المستخدم قبل الربط. */
    public function notesFor(string $channel): array
    {
        return match ($channel) {
            Channel::TELEGRAM => [
                __('No reply window — you can message a customer at any time.'),
                __('Telegram does not report delivery or read status to bots.'),
            ],
            Channel::MESSENGER => [
                __('24-hour reply window from the customer’s last message.'),
                __('A caption is sent as a separate message after the attachment.'),
            ],
            Channel::INSTAGRAM => [
                __('24-hour reply window from the customer’s last message.'),
                __('Images and videos only — no documents.'),
            ],
            Channel::TIKTOK => [
                __('48-hour reply window, and the customer must message first.'),
                __('Text or one image per message — never both, and no video.'),
                __('The token expires daily and is refreshed automatically.'),
            ],
            default => [],
        };
    }
}
