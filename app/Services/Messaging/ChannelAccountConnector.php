<?php

namespace App\Services\Messaging;

use App\Models\ChannelAccount;
use App\Models\Organization;
use App\Models\Setting;
use App\Models\Template;
use App\Services\Messaging\Exceptions\ChannelConfigurationException;
use App\Services\WhatsappService;
use Illuminate\Support\Facades\Log;

/**
 * ربط حساب منصّة بالمنشأة وفصله.
 *
 * منقول من ChannelAccountController::store/destroy و
 * SettingController::deleteWhatsappIntegration كما هو، بالرسائل نفسها —
 * ليمرّ الويب والتطبيق من طريق واحد. لا جلسة ولا auth() هنا: المنشأة
 * والمستخدم يُمرَّران صراحة.
 */
class ChannelAccountConnector
{
    public function __construct(private readonly ChannelManager $channels)
    {
    }

    /**
     * ربط حساب، أو إعادة ربطه ببيانات جديدة (الربط نفسه يحدّث الموجود).
     *
     * نتحقّق من البيانات لدى المنصّة **قبل** الحفظ: حساب محفوظ ببيانات
     * خاطئة يبدو مربوطاً في الواجهة ولا يستقبل شيئاً، وهو أسوأ من رفضٍ
     * صريح لحظة الإدخال.
     *
     * @param  array<string, mixed>  $credentials
     */
    public function connect(int $organizationId, string $channel, array $credentials, ?int $userId): ChannelSettingsResult
    {
        $driver = $this->channels->driver($channel);

        try {
            $identity = $driver->verifyCredentials($credentials);
        } catch (ChannelConfigurationException $e) {
            return ChannelSettingsResult::error($e->getMessage(), ChannelSettingsResult::INVALID_CREDENTIALS);
        } catch (\Throwable $e) {
            Log::error('Channel credential verification failed', [
                'channel' => $channel,
                'error' => $e->getMessage(),
            ]);

            return ChannelSettingsResult::error(
                __('Could not reach :channel. Check the credentials and try again.', [
                    'channel' => Channel::label($channel),
                ]),
                ChannelSettingsResult::UNREACHABLE
            );
        }

        $externalId = (string) ($identity['external_id'] ?? '');

        if ($externalId === '') {
            return ChannelSettingsResult::error(
                __('Could not read the account id from :channel.', [
                    'channel' => Channel::label($channel),
                ]),
                ChannelSettingsResult::MISSING_EXTERNAL_ID
            );
        }

        // الحساب المربوط بمنشأة أخرى: القيد في قاعدة البيانات يمنعه، لكن
        // الخطأ الخام غامض. نكشفه هنا برسالة تقول ما جرى.
        $takenElsewhere = ChannelAccount::query()
            ->where('channel', $channel)
            ->where('external_id', $externalId)
            ->where('organization_id', '!=', $organizationId)
            ->exists();

        if ($takenElsewhere) {
            return ChannelSettingsResult::error(
                __('This :channel account is already connected to another organization.', [
                    'channel' => Channel::label($channel),
                ]),
                ChannelSettingsResult::TAKEN
            );
        }

        $account = ChannelAccount::updateOrCreate(
            [
                'organization_id' => $organizationId,
                'channel' => $channel,
                'external_id' => $externalId,
            ],
            [
                'name' => $identity['name'] ?? null,
                'username' => $identity['username'] ?? null,
                'avatar' => $identity['avatar'] ?? null,
                'credentials' => $credentials,
                'status' => ChannelAccount::STATUS_ACTIVE,
                'last_error' => null,
                'created_by' => $userId,
            ]
        );

        // التسجيل بعد الحفظ: تيليجرام تحتاج uuid الحساب في عنوان الـ webhook.
        if (!$driver->registerWebhook($account)) {
            return ChannelSettingsResult::warning(
                __('The account was saved but the webhook could not be registered. Messages will not arrive until it is.'),
                ChannelSettingsResult::WEBHOOK_FAILED,
                $account
            );
        }

        return ChannelSettingsResult::success(
            __(':channel was connected.', ['channel' => Channel::label($channel)]),
            $account
        );
    }

    /** فصل حساب — حذف ناعم يُبقي محادثاته وسجلّه. البريد يُفصل من هنا كذلك. */
    public function disconnect(int $organizationId, string $uuid): ChannelSettingsResult
    {
        $account = ChannelAccount::where('uuid', $uuid)
            ->where('organization_id', $organizationId)
            ->first();

        if (!$account) {
            return ChannelSettingsResult::error(__('Account not found'), ChannelSettingsResult::NOT_FOUND);
        }

        // الـ widget يُدار من صفحته (يُعطَّل هناك)؛ حذفه يُيتّم زوّاره ومحادثاتهم.
        if ($account->channel === Channel::WEBCHAT) {
            return ChannelSettingsResult::error(
                __('Web chat is set up from its own settings page.'),
                ChannelSettingsResult::MANAGED_ELSEWHERE
            );
        }

        try {
            // البريد (SMTP) بلا driver ولا webhook: يُحذف فقط.
            if (in_array($account->channel, Channel::connectable(), true)) {
                $this->channels->driver($account->channel)->unregisterWebhook($account);
            }
        } catch (\Throwable $e) {
            // فشل إلغاء التسجيل لا يمنع الفصل: الحساب محذوف عندنا فلن
            // نعالج ما يصل منه على أي حال.
            Log::warning('Channel webhook unregister failed', [
                'account_id' => $account->id,
                'error' => $e->getMessage(),
            ]);
        }

        $account->delete();

        return ChannelSettingsResult::success(
            __(':channel was disconnected.', ['channel' => Channel::label($account->channel)])
        );
    }

    /**
     * فصل واتساب: يُلغي اشتراك الـ webhook (حين يكون الربط المدمج مفعّلاً)،
     * ويمسح إعداداتها من المنشأة، ويحذف قوالبها حذفاً ناعماً — كما يفعل زرّ
     * «إزالة التكامل» في صفحة واتساب. فحص الحساب التجريبي على من يستدعي.
     */
    public function disconnectWhatsapp(int $organizationId): ChannelSettingsResult
    {
        $embeddedSignupActive = Setting::where('key', 'is_embedded_signup_active')->value('value');
        $organizationConfig = Organization::where('id', $organizationId)->first();
        $config = $organizationConfig?->metadata ? json_decode($organizationConfig->metadata, true) : [];

        if (!$organizationConfig || !isset($config['whatsapp'])) {
            return ChannelSettingsResult::error(
                __('Setup your whatsapp integration first!'),
                ChannelSettingsResult::NOT_CONNECTED
            );
        }

        if ($embeddedSignupActive == 1) {
            //Unsubscribe webhook
            $apiVersion = config('graph.api_version');

            $accessToken = $config['whatsapp']['access_token'] ?? null;
            $appId = $config['whatsapp']['app_id'] ?? null;
            $phoneNumberId = $config['whatsapp']['phone_number_id'] ?? null;
            $wabaId = $config['whatsapp']['waba_id'] ?? null;

            $whatsappService = new WhatsappService($accessToken, $apiVersion, $appId, $phoneNumberId, $wabaId, $organizationId);
            $whatsappService->unSubscribeToWaba();
        }

        //Delete whatsapp settings
        unset($config['whatsapp']);

        $organizationConfig->metadata = json_encode($config);
        $organizationConfig->save();

        //Delete templates
        $templates = Template::where('organization_id', $organizationId)->get();
        foreach ($templates as $template) {
            $template->deleted_at = now();
            $template->save();
        }

        return ChannelSettingsResult::success(__('Your integration has been removed successfully!'));
    }
}
