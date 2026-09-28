<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\ChannelAccount;
use App\Services\Messaging\Channel;
use App\Services\Messaging\ChannelManager;
use App\Services\Messaging\Exceptions\ChannelConfigurationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * ربط حسابات المنصّات وفصلها.
 *
 * واتساب لا تمرّ من هنا: لها صفحتها ومسارها و EmbeddedSignup، ولم نلمسها.
 * هذه الصفحة تعرضها للاطّلاع فقط وتوجّه إلى صفحتها للتعديل.
 */
class ChannelAccountController extends Controller
{
    public function __construct(private readonly ChannelManager $channels)
    {
    }

    public function index(Request $request)
    {
        $organizationId = (int) session()->get('current_organization');

        // اختيار معلّق بعد تفويض أعاد عدّة صفحات. لا نُخرج التوكنات —
        // toChoiceList تُسقطها.
        $pending = session()->get('channel_oauth_pending');
        $pendingChoices = null;

        if (is_array($pending) && (int) ($pending['organization_id'] ?? 0) === $organizationId) {
            $pendingChoices = [
                'channel' => $pending['channel'],
                'accounts' => \App\Services\Messaging\Contracts\OAuthResult::choices($pending['accounts'])
                    ->toChoiceList(),
            ];
        }

        return Inertia::render('User/Settings/Channels', [
            'title' => 'Channels',
            'channels' => $this->channelCards($organizationId),
            'whatsappConnected' => $this->channels->whatsappPseudoAccount($organizationId) !== null,
            'webhookBaseUrl' => rtrim(config('app.url'), '/') . '/webhook/channel',
            'pendingSelection' => $pendingChoices,
        ]);
    }

    /**
     * ربط حساب.
     *
     * نتحقّق من البيانات لدى المنصّة **قبل** الحفظ: حساب محفوظ ببيانات
     * خاطئة يبدو مربوطاً في الواجهة ولا يستقبل شيئاً، وهو أسوأ من رفضٍ
     * صريح لحظة الإدخال.
     */
    public function store(Request $request)
    {
        $organizationId = (int) session()->get('current_organization');

        $validated = $request->validate([
            'channel' => ['required', Rule::in(Channel::others())],
            'credentials' => ['required', 'array'],
        ]);

        $channel = $validated['channel'];
        $driver = $this->channels->driver($channel);

        try {
            $identity = $driver->verifyCredentials($validated['credentials']);
        } catch (ChannelConfigurationException $e) {
            return back()->with('status', ['type' => 'error', 'message' => $e->getMessage()]);
        } catch (\Throwable $e) {
            Log::error('Channel credential verification failed', [
                'channel' => $channel,
                'error' => $e->getMessage(),
            ]);

            return back()->with('status', [
                'type' => 'error',
                'message' => __('Could not reach :channel. Check the credentials and try again.', [
                    'channel' => Channel::label($channel),
                ]),
            ]);
        }

        $externalId = (string) ($identity['external_id'] ?? '');

        if ($externalId === '') {
            return back()->with('status', [
                'type' => 'error',
                'message' => __('Could not read the account id from :channel.', [
                    'channel' => Channel::label($channel),
                ]),
            ]);
        }

        // الحساب المربوط بمنشأة أخرى: القيد في قاعدة البيانات يمنعه، لكن
        // الخطأ الخام غامض. نكشفه هنا برسالة تقول ما جرى.
        $takenElsewhere = ChannelAccount::query()
            ->where('channel', $channel)
            ->where('external_id', $externalId)
            ->where('organization_id', '!=', $organizationId)
            ->exists();

        if ($takenElsewhere) {
            return back()->with('status', [
                'type' => 'error',
                'message' => __('This :channel account is already connected to another organization.', [
                    'channel' => Channel::label($channel),
                ]),
            ]);
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
                'credentials' => $validated['credentials'],
                'status' => ChannelAccount::STATUS_ACTIVE,
                'last_error' => null,
                'created_by' => auth()->id(),
            ]
        );

        // التسجيل بعد الحفظ: تيليجرام تحتاج uuid الحساب في عنوان الـ webhook.
        if (!$driver->registerWebhook($account)) {
            return back()->with('status', [
                'type' => 'warning',
                'message' => __('The account was saved but the webhook could not be registered. Messages will not arrive until it is.'),
            ]);
        }

        return back()->with('status', [
            'type' => 'success',
            'message' => __(':channel was connected.', ['channel' => Channel::label($channel)]),
        ]);
    }

    /** فصل حساب — حذف ناعم يُبقي محادثاته وسجلّه. */
    public function destroy(Request $request, string $uuid)
    {
        $organizationId = (int) session()->get('current_organization');

        $account = ChannelAccount::where('uuid', $uuid)
            ->where('organization_id', $organizationId)
            ->first();

        if (!$account) {
            return back()->with('status', ['type' => 'error', 'message' => __('Account not found')]);
        }

        try {
            $this->channels->driver($account->channel)->unregisterWebhook($account);
        } catch (\Throwable $e) {
            // فشل إلغاء التسجيل لا يمنع الفصل: الحساب محذوف عندنا فلن
            // نعالج ما يصل منه على أي حال.
            Log::warning('Channel webhook unregister failed', [
                'account_id' => $account->id,
                'error' => $e->getMessage(),
            ]);
        }

        $account->delete();

        return back()->with('status', [
            'type' => 'success',
            'message' => __(':channel was disconnected.', ['channel' => Channel::label($account->channel)]),
        ]);
    }

    /**
     * بطاقة لكل قناة: ما تحتاجه الواجهة لعرض التبويب — الحقول المطلوبة،
     * والحساب المربوط إن وُجد، وقيود المنصّة ليعرفها المستخدم قبل أن يعد
     * عميله بما لا نستطيع.
     */
    private function channelCards(int $organizationId): array
    {
        $accounts = $this->channels->accountsFor($organizationId)->keyBy('channel');

        $cards = [];

        foreach (Channel::others() as $channel) {
            $capabilities = $this->channels->capabilities($channel);
            $account = $accounts->get($channel);

            $driver = $this->channels->driver($channel);
            $usesOAuth = $driver instanceof \App\Services\Messaging\Contracts\SupportsOAuth;

            $cards[] = [
                'channel' => $channel,
                'label' => Channel::label($channel),
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

    /** هل ضُبط تطبيق المنصّة على الخادم؟ (إعداد لمرّة واحدة لكل المنصّة) */
    private function platformConfigured(string $channel): bool
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
    private function fieldsFor(string $channel): array
    {
        return match ($channel) {
            Channel::TELEGRAM => [
                ['key' => 'bot_token', 'label' => __('Bot token'), 'type' => 'password', 'hint' => __('Get it from @BotFather on Telegram.')],
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
    private function notesFor(string $channel): array
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
