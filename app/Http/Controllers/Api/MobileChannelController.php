<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\MobileApi;
use App\Http\Controllers\Controller;
use App\Services\ActivityLogger;
use App\Services\Messaging\Channel;
use App\Services\Messaging\ChannelAccountConnector;
use App\Services\Messaging\ChannelManager;
use App\Services\Messaging\ChannelSettingsCatalog;
use App\Services\Messaging\ChannelSettingsResult;
use App\Services\WebChat\WebChatWidgetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * القنوات من التطبيق.
 *
 * GET /channels لكل الأدوار: ما تحتاجه شاشة المحادثات (فلتر القنوات وما
 * تدعمه كل قناة). وما تحت /settings/channels للمالك والمدير وحدهما — كما
 * يمنع الويب الموظّف من صفحة القنوات (RestrictOrganizationAgent).
 *
 * الربط من التطبيق للقنوات التي تُربط ببيانات تُلصق (تيليجرام). ما يُربط
 * بتفويض (ماسنجر، إنستغرام، تيك توك) أو بالربط المدمج (واتساب) يمرّ
 * بمتصفّح وجلسة ويب، فيفتحه التطبيق من connect_url. الفصل من التطبيق للكل.
 *
 * الربط والفصل في ChannelAccountConnector، والبطاقات في
 * ChannelSettingsCatalog — نفس ما يستعمله الويب.
 */
class MobileChannelController extends Controller
{
    use MobileApi;

    /** يُربط من التطبيق: بيانات تُلصق في نموذج. */
    public const CONNECT_IN_APP = 'in_app';

    /** يُربط من لوحة التحكّم في المتصفّح (تفويض المنصّة). */
    public const CONNECT_ON_WEB = 'web';

    public function __construct(
        private readonly ChannelManager $channels,
        private readonly ChannelSettingsCatalog $catalog,
        private readonly ChannelAccountConnector $connector,
    ) {
    }

    /**
     * قنوات المحادثة: المربوطة منها، وما تدعمه كلٌّ منها. البريد ليس هنا:
     * قناة حملات بلا صندوق وارد.
     */
    public function catalog(Request $request): JsonResponse
    {
        $organizationId = $this->organizationId($request);
        $connected = $this->channels->connectedChannels($organizationId);

        return $this->ok([
            'connected' => $connected,
            'channels' => array_map(fn (string $channel) => [
                'channel' => $channel,
                'label' => __(Channel::label($channel)),
                'connected' => in_array($channel, $connected, true),
                'capabilities' => $this->channels->capabilities($channel)->toArray(),
            ], Channel::all()),
        ]);
    }

    /** صفحة القنوات: واتساب، ثم بطاقة لكل منصّة، ثم البريد والمحادثة المباشرة. */
    public function index(Request $request): JsonResponse
    {
        if (!$this->isPrivileged($request)) {
            return $this->forbidden();
        }

        $organizationId = $this->organizationId($request);
        $whatsapp = $this->channels->whatsappPseudoAccount($organizationId);

        return $this->ok([
            'whatsapp' => [
                'connected' => $whatsapp !== null,
                'name' => $whatsapp?->name,
                'connect_mode' => self::CONNECT_ON_WEB,
                'connect_url' => url('/settings/whatsapp'),
                'capabilities' => $this->channels->capabilities(Channel::WHATSAPP)->toArray(),
            ],
            'channels' => array_map(fn (array $card) => $this->mobileCard($card), $this->catalog->cards($organizationId)),
            'email' => $this->catalog->emailSummary($organizationId) + [
                'connect_mode' => self::CONNECT_IN_APP,
            ],
            'webchat' => $this->catalog->webChatSummary($organizationId) + [
                // الإضافة خارج الباقة: الشاشة تعرض القناة مقفلة بدل أن تفتحها فتُرفض.
                'available' => WebChatWidgetService::moduleEnabled($organizationId),
            ],
        ]);
    }

    /**
     * ربط حساب، أو إعادة ربطه ببيانات جديدة. ما يُربط بتفويض يُرفض هنا
     * (422): connect_url في البطاقة يفتحه في المتصفّح.
     */
    public function connect(Request $request): JsonResponse
    {
        if (!$this->isPrivileged($request)) {
            return $this->forbidden();
        }

        $organizationId = $this->organizationId($request);

        $validator = Validator::make($request->all(), [
            'channel' => ['required', 'string', Rule::in(Channel::connectable())],
            'credentials' => ['required', 'array'],
        ]);

        if ($validator->fails()) {
            return $this->fail(400, __('The provided data is invalid.'), $validator->errors()->toArray());
        }

        $channel = (string) $request->input('channel');
        $card = $this->catalog->card($organizationId, $channel);

        if ($card === null || $this->connectMode($card) !== self::CONNECT_IN_APP) {
            return $this->fail(422, __('Connect :channel from the web dashboard.', ['channel' => Channel::label($channel)]));
        }

        // الحقول التي تصفها البطاقة وحدها: الويب يقبل أي مصفوفة، والتطبيق
        // لا يحفظ في بيانات الاعتماد المشفّرة مفاتيح لا يقرؤها السائق.
        $keys = array_column($card['fields'], 'key');
        $fieldRules = [];
        foreach ($keys as $key) {
            $fieldRules['credentials.' . $key] = ['required', 'string', 'max:2048'];
        }

        $fieldValidator = Validator::make($request->all(), $fieldRules);

        if ($fieldValidator->fails()) {
            return $this->fail(400, __('The provided data is invalid.'), $fieldValidator->errors()->toArray());
        }

        $result = $this->connector->connect(
            $organizationId,
            $channel,
            Arr::only((array) $request->input('credentials'), $keys),
            (int) $request->user()->id
        );

        if ($result->isError()) {
            return $this->fail(422, $result->message);
        }

        ActivityLogger::log(ActivityLogger::SETTINGS_UPDATED, Channel::label($channel), null, null, [], $organizationId);

        return $this->ok([
            'account' => $this->accountView($result->account?->toSettingsArray()),
            // false: الحساب محفوظ ولن تصل رسائله حتى يُعاد الربط.
            'webhook_registered' => $result->code !== ChannelSettingsResult::WEBHOOK_FAILED,
        ], $result->message);
    }

    /** فصل حساب (قناة أو بريد). المحادثة المباشرة تُوقف من إعداداتها لا هنا. */
    public function disconnect(Request $request, string $uuid): JsonResponse
    {
        if (!$this->isPrivileged($request)) {
            return $this->forbidden();
        }

        $organizationId = $this->organizationId($request);
        $result = $this->connector->disconnect($organizationId, $uuid);

        if ($result->isError()) {
            return $this->fail($result->code === ChannelSettingsResult::NOT_FOUND ? 404 : 422, $result->message);
        }

        ActivityLogger::log(ActivityLogger::SETTINGS_UPDATED, null, null, null, [], $organizationId);

        return $this->ok(null, $result->message);
    }

    /**
     * فصل واتساب. يحذف القوالب حذفاً ناعماً ويمسح الربط — التطبيق يطلب
     * التأكيد قبل الوصول إلى هنا.
     */
    public function disconnectWhatsapp(Request $request): JsonResponse
    {
        if (!$this->isPrivileged($request)) {
            return $this->forbidden();
        }

        $organizationId = $this->organizationId($request);

        // كفحص الحساب التجريبي في SettingController::abortIfDemo.
        if (app()->environment('demo') && $organizationId === 1) {
            return $this->fail(403, __('You cannot perform this action using the demo account. To test this feature, please create your own account.'));
        }

        $result = $this->connector->disconnectWhatsapp($organizationId);

        if ($result->isError()) {
            return $this->fail(422, $result->message);
        }

        ActivityLogger::log(ActivityLogger::SETTINGS_UPDATED, Channel::label(Channel::WHATSAPP), null, null, [], $organizationId);

        return $this->ok(null, $result->message);
    }

    /** بطاقة الويب بما يحتاجه التطبيق: طريقة الربط ورابطه، بلا إعدادات الحساب الداخلية. */
    private function mobileCard(array $card): array
    {
        $mode = $this->connectMode($card);

        return [
            'channel' => $card['channel'],
            'label' => $card['label'],
            'account' => $this->accountView($card['account']),
            'connect_mode' => $mode,
            // صفحة القناة في لوحة التحكّم — لما يُربط بتفويض، ولمن أراد التفاصيل.
            'connect_url' => url('/settings/channels/' . $card['channel']),
            // حقول النموذج لما يُربط من التطبيق وحده.
            'fields' => $mode === self::CONNECT_IN_APP ? $card['fields'] : [],
            'platform_ready' => $card['platform_ready'],
            'capabilities' => $card['capabilities'],
            'notes' => $card['notes'],
        ];
    }

    private function connectMode(array $card): string
    {
        return $card['uses_oauth'] ? self::CONNECT_ON_WEB : self::CONNECT_IN_APP;
    }

    /** الحساب كما يراه الويب، بلا settings: إعدادات داخلية لا تعرضها شاشة. */
    private function accountView(?array $account): ?array
    {
        return $account === null ? null : Arr::except($account, ['settings']);
    }
}
