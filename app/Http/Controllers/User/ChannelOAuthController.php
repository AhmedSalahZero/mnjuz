<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\ChannelAccount;
use App\Services\Messaging\Channel;
use App\Services\Messaging\ChannelManager;
use App\Services\Messaging\Contracts\OAuthResult;
use App\Services\Messaging\Contracts\SupportsOAuth;
use App\Services\Messaging\Exceptions\ChannelConfigurationException;
use App\Services\Messaging\OAuth\OAuthState;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * تدفّق ربط القنوات بالتفويض.
 *
 * ثلاث خطوات:
 *   ١. redirect  — نُصدر حالة موقَّعة ونُحوّل إلى صفحة تفويض المنصّة.
 *   ٢. callback  — نتحقّق من الحالة، ونُبدّل الـ code بتوكن.
 *   ٣. select    — إن كان للمستخدم أكثر من صفحة، يختار واحدة.
 *
 * تيليجرام لا تمرّ من هنا: توكن @BotFather يُلصق مباشرةً.
 *
 * ملاحظة أمنية: نتائج الخطوة الثانية تُحفظ في الجلسة لا في العنوان — فيها
 * توكنات صفحات، ووضعها في رابط يُسرّبها إلى سجلّ الخادم وتاريخ المتصفّح.
 */
class ChannelOAuthController extends Controller
{
    /** مفتاح الجلسة الذي يحمل الحسابات المعروضة للاختيار. */
    private const PENDING_KEY = 'channel_oauth_pending';

    public function __construct(private readonly ChannelManager $channels)
    {
    }

    /** الخطوة ١ — التحويل إلى صفحة التفويض. */
    public function redirect(Request $request, string $channel)
    {
        $channel = Channel::normalize($channel);
        $organizationId = (int) session()->get('current_organization');

        if ($channel === null || $organizationId === 0) {
            return redirect('/settings/channels')->with('status', [
                'type' => 'error',
                'message' => __('Unknown channel.'),
            ]);
        }

        $driver = $this->channels->driver($channel);

        if (!$driver instanceof SupportsOAuth) {
            return redirect('/settings/channels')->with('status', [
                'type' => 'error',
                'message' => __(':channel is connected by pasting a token, not by authorization.', [
                    'channel' => Channel::label($channel),
                ]),
            ]);
        }

        if (!$this->platformConfigured($channel)) {
            return redirect('/settings/channels')->with('status', [
                'type' => 'error',
                'message' => __('This platform is not configured on the server yet. Contact the administrator.'),
            ]);
        }

        $state = OAuthState::issue($channel, $organizationId, (int) auth()->id());

        // away لا redirect: العنوان خارجيّ، وredirect يُضيف فحوصاً لا معنى
        // لها لنطاق لا نملكه.
        return redirect()->away($driver->authorizationUrl($state, $this->redirectUri($channel)));
    }

    /** الخطوة ٢ — العودة من المنصّة. */
    public function callback(Request $request, string $channel)
    {
        $normalized = Channel::normalize($channel);

        // رفض المستخدم أو خطأ من المنصّة: يصل قبل أي code.
        if ($request->filled('error') || $request->filled('error_description')) {
            return $this->back(
                'error',
                $request->input('error_description')
                    ?: __('The authorization was cancelled.')
            );
        }

        $state = OAuthState::consume($request->input('state'));

        // الحالة غير صالحة: منتهية، أو مُستعملة، أو مُركَّبة. لا نُفرّق —
        // التفريق يُعطي المهاجم إشارة.
        if ($state === null) {
            return $this->back('error', __('This authorization link is no longer valid. Start again.'));
        }

        if ($normalized === null || $state['channel'] !== $normalized) {
            return $this->back('error', __('Unknown channel.'));
        }

        // الجلسة يجب أن تكون نفسها التي بدأت: جلسة أخرى فُتحت في الأثناء
        // تعني ربط حساب بمنشأة لم تطلبه.
        if ((int) auth()->id() !== $state['user_id']
            || (int) session()->get('current_organization') !== $state['organization_id']) {
            return $this->back('error', __('Your session changed during the authorization. Start again.'));
        }

        $code = (string) $request->input('code', '');

        if ($code === '') {
            return $this->back('error', __('The authorization did not return a code. Start again.'));
        }

        $driver = $this->channels->driver($normalized);

        try {
            $result = $driver->exchangeCode($code, $this->redirectUri($normalized));
        } catch (ChannelConfigurationException $e) {
            return $this->back('error', $e->getMessage());
        } catch (\Throwable $e) {
            Log::error('Channel OAuth exchange failed', [
                'channel' => $normalized,
                'error' => $e->getMessage(),
            ]);

            return $this->back('error', __('Could not complete the authorization. Try again.'));
        }

        if ($result->isEmpty()) {
            return $this->back('error', $this->nothingToConnectMessage($normalized));
        }

        if ($result->needsSelection()) {
            session()->put(self::PENDING_KEY, [
                'channel' => $normalized,
                'organization_id' => $state['organization_id'],
                'accounts' => $result->accounts,
            ]);

            return redirect('/settings/channels?select=' . $normalized);
        }

        return $this->persist($normalized, $state['organization_id'], $result->first());
    }

    /** الخطوة ٣ — اختيار صفحة من عدّة صفحات. */
    public function select(Request $request)
    {
        $validated = $request->validate([
            'external_id' => ['required', 'string'],
        ]);

        $pending = session()->get(self::PENDING_KEY);

        if (!is_array($pending) || empty($pending['accounts'])) {
            return $this->back('error', __('This authorization link is no longer valid. Start again.'));
        }

        if ((int) $pending['organization_id'] !== (int) session()->get('current_organization')) {
            session()->forget(self::PENDING_KEY);

            return $this->back('error', __('Your session changed during the authorization. Start again.'));
        }

        $account = OAuthResult::choices($pending['accounts'])->find($validated['external_id']);

        // معرّف لم يفوّضه المستخدم: محاولة ربط صفحة لا يملكها.
        if ($account === null) {
            return $this->back('error', __('That account was not part of the authorization.'));
        }

        session()->forget(self::PENDING_KEY);

        return $this->persist($pending['channel'], (int) $pending['organization_id'], $account);
    }

    /** إلغاء الاختيار المعلّق — توكنات الصفحات لا تبقى في الجلسة بلا داعٍ. */
    public function cancelSelection(Request $request)
    {
        session()->forget(self::PENDING_KEY);

        return redirect('/settings/channels');
    }

    /* ───────────────────────── داخلي ───────────────────────── */

    private function persist(string $channel, int $organizationId, array $account)
    {
        $externalId = (string) $account['external_id'];

        $takenElsewhere = ChannelAccount::query()
            ->where('channel', $channel)
            ->where('external_id', $externalId)
            ->where('organization_id', '!=', $organizationId)
            ->exists();

        if ($takenElsewhere) {
            return $this->back('error', __('This :channel account is already connected to another organization.', [
                'channel' => Channel::label($channel),
            ]));
        }

        $credentials = $account['credentials'];

        // مدد الصلاحية تُخزَّن أعمدةً لا داخل JSON: المهمّة المجدولة
        // تستعلم عنها بفهرس.
        $expiresAt = isset($credentials['expires_in'])
            ? now()->addSeconds((int) $credentials['expires_in'])
            : null;
        $refreshExpiresAt = isset($credentials['refresh_token_expires_in'])
            ? now()->addSeconds((int) $credentials['refresh_token_expires_in'])
            : null;

        unset($credentials['expires_in'], $credentials['refresh_token_expires_in']);

        $saved = ChannelAccount::updateOrCreate(
            [
                'organization_id' => $organizationId,
                'channel' => $channel,
                'external_id' => $externalId,
            ],
            [
                'name' => $account['name'] ?? null,
                'username' => $account['username'] ?? null,
                'avatar' => $account['avatar'] ?? null,
                'credentials' => $credentials,
                'token_expires_at' => $expiresAt,
                'refresh_token_expires_at' => $refreshExpiresAt,
                'status' => ChannelAccount::STATUS_ACTIVE,
                'last_error' => null,
                'created_by' => auth()->id(),
            ]
        );

        if (!$this->channels->driver($channel)->registerWebhook($saved)) {
            return $this->back('warning', __('The account was connected but the webhook could not be registered. Messages will not arrive until it is.'));
        }

        return $this->back('success', __(':channel was connected.', ['channel' => Channel::label($channel)]));
    }

    /** عنوان العودة — يجب أن يطابق حرفياً ما سُجّل في لوحة المنصّة. */
    private function redirectUri(string $channel): string
    {
        return route('channels.oauth.callback', ['channel' => $channel]);
    }

    private function platformConfigured(string $channel): bool
    {
        return match ($channel) {
            Channel::MESSENGER, Channel::INSTAGRAM => filled(config('services.meta.app_id'))
                && filled(config('services.meta.app_secret')),
            Channel::TIKTOK => filled(config('services.tiktok.app_id'))
                && filled(config('services.tiktok.app_secret')),
            default => false,
        };
    }

    /** لماذا لم نجد ما نربطه — السبب يختلف بين المنصّتين. */
    private function nothingToConnectMessage(string $channel): string
    {
        return match ($channel) {
            Channel::MESSENGER => __('No Facebook Page was found on your account. Create a Page first, then connect again.'),
            Channel::INSTAGRAM => __('No Instagram professional account linked to a Facebook Page was found. Link them first, then connect again.'),
            default => __('No account was found to connect.'),
        };
    }

    private function back(string $type, string $message)
    {
        return redirect('/settings/channels')->with('status', [
            'type' => $type,
            'message' => $message,
        ]);
    }
}
