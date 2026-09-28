<?php

namespace Tests\Feature\Channels;

use App\Http\Controllers\User\ChannelOAuthController;
use App\Models\ChannelAccount;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use App\Services\Messaging\Channel;
use App\Services\Messaging\ChannelManager;
use App\Services\Messaging\OAuth\OAuthState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * تدفّق ربط القنوات بالتفويض، من الضغط على الزرّ إلى الحساب المحفوظ.
 *
 * التركيز على ما يُخطئ فيه التنفيذ عادةً: الحالة، والتوكن قصير الأجل،
 * والصلاحيات الممنوحة جزئياً، والصفحات المتعدّدة، والحساب المربوط بمنشأة
 * أخرى — وكلّها أعطاب صامتة لا تُكتشف إلّا بعد أيام من الإطلاق.
 */
class ChannelOAuthFlowTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;
    private User $owner;
    private ChannelOAuthController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.meta.app_id' => 'meta-app',
            'services.meta.app_secret' => 'meta-secret',
            'services.tiktok.app_id' => 'tt-app',
            'services.tiktok.app_secret' => 'tt-secret',
        ]);

        $this->owner = User::factory()->create(['role' => 'user']);
        $this->organization = Organization::factory()->create(['created_by' => $this->owner->id]);

        Team::factory()->create([
            'user_id' => $this->owner->id,
            'organization_id' => $this->organization->id,
            'role' => 'owner',
        ]);

        $this->actingAs($this->owner);
        session(['current_organization' => $this->organization->id]);

        $this->controller = new ChannelOAuthController(app(ChannelManager::class));
    }

    /* ───────────────────── الخطوة ١: التحويل ───────────────────── */

    /** رابط ماسنجر يحمل التطبيق والصلاحيات والحالة. */
    public function test_the_messenger_redirect_carries_app_scopes_and_state(): void
    {
        $response = $this->controller->redirect($this->request(), Channel::MESSENGER);

        $url = $response->getTargetUrl();
        parse_str(parse_url($url, PHP_URL_QUERY), $query);

        $this->assertStringStartsWith('https://www.facebook.com/', $url);
        $this->assertSame('meta-app', $query['client_id']);
        $this->assertStringContainsString('pages_messaging', $query['scope']);
        $this->assertStringContainsString('pages_show_list', $query['scope']);
        $this->assertNotEmpty($query['state']);
        $this->assertStringContainsString('/channels/oauth/messenger/callback', $query['redirect_uri']);
    }

    /** وإنستغرام تطلب صلاحياتها لا صلاحيات ماسنجر. */
    public function test_the_instagram_redirect_asks_for_instagram_scopes(): void
    {
        $response = $this->controller->redirect($this->request(), Channel::INSTAGRAM);

        parse_str(parse_url($response->getTargetUrl(), PHP_URL_QUERY), $query);

        $this->assertStringContainsString('instagram_manage_messages', $query['scope']);
        $this->assertStringContainsString('instagram_basic', $query['scope']);
    }

    /** وتيك توك تحمل client_key وكل الصلاحيات المطلوبة. */
    public function test_the_tiktok_redirect_carries_every_required_scope(): void
    {
        $response = $this->controller->redirect($this->request(), Channel::TIKTOK);

        $url = $response->getTargetUrl();
        parse_str(parse_url($url, PHP_URL_QUERY), $query);

        $this->assertStringStartsWith('https://www.tiktok.com/v2/auth/authorize', $url);
        $this->assertSame('tt-app', $query['client_key']);

        foreach (\App\Services\Messaging\Drivers\TikTokDriver::REQUIRED_SCOPES as $scope) {
            $this->assertStringContainsString($scope, $query['scope']);
        }
    }

    /** وتيليجرام لا تُربط بتفويض — تُردّ برسالة تقول ذلك. */
    public function test_telegram_cannot_be_connected_by_authorization(): void
    {
        $response = $this->controller->redirect($this->request(), Channel::TELEGRAM);

        $this->assertStringContainsString('/settings/channels', $response->getTargetUrl());
        $this->assertSame('error', session('status')['type']);
    }

    /** وقناة مجهولة تُردّ كذلك. */
    public function test_an_unknown_channel_is_rejected_at_redirect(): void
    {
        $response = $this->controller->redirect($this->request(), 'myspace');

        $this->assertStringContainsString('/settings/channels', $response->getTargetUrl());
        $this->assertSame('error', session('status')['type']);
    }

    /**
     * ومنصّة لم تُضبط على الخادم تُوقف المستخدم برسالة مفهومة بدل أن
     * تُرسله إلى صفحة تفويض تُخطئ.
     */
    public function test_an_unconfigured_platform_stops_before_redirecting(): void
    {
        config(['services.tiktok.app_id' => null, 'services.tiktok.app_secret' => null]);

        $response = $this->controller->redirect($this->request(), Channel::TIKTOK);

        $this->assertStringContainsString('/settings/channels', $response->getTargetUrl());
        $this->assertSame('error', session('status')['type']);
    }

    /* ───────────────────── الخطوة ٢: العودة ───────────────────── */

    /** رفض المستخدم يعود برسالة لا بخطأ. */
    public function test_a_denied_authorization_returns_a_readable_message(): void
    {
        $request = Request::create('/channels/oauth/messenger/callback', 'GET', [
            'error' => 'access_denied',
            'error_description' => 'User denied the request',
        ]);

        $this->controller->callback($request, Channel::MESSENGER);

        $this->assertSame('error', session('status')['type']);
        $this->assertSame(0, ChannelAccount::count());
    }

    /** وحالة غير صالحة تُرفض قبل أي نداء شبكة. */
    public function test_an_invalid_state_is_rejected_before_calling_the_platform(): void
    {
        Http::fake();

        $request = Request::create('/channels/oauth/messenger/callback', 'GET', [
            'code' => 'the-code',
            'state' => 'forged',
        ]);

        $this->controller->callback($request, Channel::MESSENGER);

        $this->assertSame('error', session('status')['type']);
        Http::assertNothingSent();
        $this->assertSame(0, ChannelAccount::count());
    }

    /**
     * والحالة الصادرة لقناة لا تُقبل على قناة أخرى — وإلّا أمكن تحويل
     * تفويض تيك توك إلى ربط ماسنجر.
     */
    public function test_a_state_issued_for_one_channel_is_rejected_on_another(): void
    {
        Http::fake();

        $state = OAuthState::issue(Channel::TIKTOK, (int) $this->organization->id, (int) $this->owner->id);

        $request = Request::create('/channels/oauth/messenger/callback', 'GET', [
            'code' => 'the-code',
            'state' => $state,
        ]);

        $this->controller->callback($request, Channel::MESSENGER);

        $this->assertSame('error', session('status')['type']);
        Http::assertNothingSent();
    }

    /**
     * وحالة صادرة لمنشأة أخرى تُرفض — هذا هو الهجوم الذي تمنعه الحالة:
     * ربط حساب المهاجم بمنشأة الضحية.
     */
    public function test_a_state_from_another_organization_is_rejected(): void
    {
        Http::fake();

        $otherOwner = User::factory()->create(['role' => 'user']);
        $other = Organization::factory()->create(['created_by' => $otherOwner->id]);

        $state = OAuthState::issue(Channel::MESSENGER, (int) $other->id, (int) $this->owner->id);

        $request = Request::create('/channels/oauth/messenger/callback', 'GET', [
            'code' => 'the-code',
            'state' => $state,
        ]);

        $this->controller->callback($request, Channel::MESSENGER);

        $this->assertSame('error', session('status')['type']);
        Http::assertNothingSent();
        $this->assertSame(0, ChannelAccount::count());
    }

    /** وحالة صادرة لمستخدم آخر تُرفض كذلك. */
    public function test_a_state_from_another_user_is_rejected(): void
    {
        Http::fake();

        $state = OAuthState::issue(Channel::MESSENGER, (int) $this->organization->id, 999999);

        $request = Request::create('/channels/oauth/messenger/callback', 'GET', [
            'code' => 'the-code',
            'state' => $state,
        ]);

        $this->controller->callback($request, Channel::MESSENGER);

        $this->assertSame('error', session('status')['type']);
        Http::assertNothingSent();
    }

    /** وعودة بلا code تُرفض. */
    public function test_a_callback_without_a_code_is_rejected(): void
    {
        Http::fake();

        $request = Request::create('/channels/oauth/messenger/callback', 'GET', [
            'state' => $this->validState(Channel::MESSENGER),
        ]);

        $this->controller->callback($request, Channel::MESSENGER);

        $this->assertSame('error', session('status')['type']);
        Http::assertNothingSent();
    }

    /* ───────────────────── ماسنجر ───────────────────── */

    /** صفحة واحدة تُربط فوراً بلا خطوة اختيار. */
    public function test_a_single_page_is_connected_immediately(): void
    {
        $this->fakeMeta([$this->page('page-1', 'متجر الرياض', 'page-1-token')]);

        $this->runCallback(Channel::MESSENGER);

        $account = ChannelAccount::where('channel', Channel::MESSENGER)->first();
        $this->assertNotNull($account);
        $this->assertSame('page-1', $account->external_id);
        $this->assertSame('متجر الرياض', $account->name);
        $this->assertSame('page-1-token', $account->credential('access_token'));
        $this->assertTrue($account->isActive());

        // ورمز التحقّق يُولَّد عندنا — المستخدم لا يخترعه.
        $this->assertNotEmpty($account->credential('verify_token'));

        $this->assertSame('success', session('status')['type']);
    }

    /**
     * والتوكن المحفوظ هو الطويل لا القصير.
     *
     * تخطّي التمديد يعني قناةً تتوقّف بعد ساعة بلا سبب ظاهر.
     */
    public function test_the_long_lived_user_token_is_used_to_fetch_pages(): void
    {
        $this->fakeMeta([$this->page('page-1', 'متجر', 'page-token')]);

        $this->runCallback(Channel::MESSENGER);

        // نداء /me/accounts جرى بالتوكن الطويل لا بالقصير.
        Http::assertSent(fn ($request) => str_contains($request->url(), '/me/accounts')
            && str_contains($request->url(), 'long-lived-user-token'));
    }

    /** وعدّة صفحات تُوقف التدفّق عند الاختيار ولا تُربط شيئاً بعد. */
    public function test_several_pages_pause_for_a_selection(): void
    {
        $this->fakeMeta([
            $this->page('page-1', 'متجر الرياض', 'token-1'),
            $this->page('page-2', 'متجر جدة', 'token-2'),
        ]);

        $response = $this->runCallback(Channel::MESSENGER);

        $this->assertSame(0, ChannelAccount::count());
        $this->assertStringContainsString('select=messenger', $response->getTargetUrl());

        $pending = session('channel_oauth_pending');
        $this->assertIsArray($pending);
        $this->assertCount(2, $pending['accounts']);
    }

    /** ثمّ الاختيار يربط الصفحة المطلوبة وحدها. */
    public function test_choosing_a_page_connects_only_that_page(): void
    {
        $this->fakeMeta([
            $this->page('page-1', 'متجر الرياض', 'token-1'),
            $this->page('page-2', 'متجر جدة', 'token-2'),
        ]);

        $this->runCallback(Channel::MESSENGER);

        $this->controller->select(Request::create('/settings/channels/select', 'POST', [
            'external_id' => 'page-2',
        ]));

        $this->assertSame(1, ChannelAccount::count());

        $account = ChannelAccount::first();
        $this->assertSame('page-2', $account->external_id);
        $this->assertSame('متجر جدة', $account->name);
        $this->assertSame('token-2', $account->credential('access_token'));

        // والجلسة نُظّفت — توكنات الصفحات لا تبقى فيها.
        $this->assertNull(session('channel_oauth_pending'));
    }

    /**
     * واختيار صفحة لم يفوّضها المستخدم يُرفض — وإلّا أمكن ربط صفحة لا
     * يملكها بتعديل الطلب.
     */
    public function test_choosing_a_page_that_was_not_authorized_is_rejected(): void
    {
        $this->fakeMeta([$this->page('page-1', 'متجر', 'token-1'), $this->page('page-2', 'متجر ٢', 'token-2')]);

        $this->runCallback(Channel::MESSENGER);

        $this->controller->select(Request::create('/settings/channels/select', 'POST', [
            'external_id' => 'page-999',
        ]));

        $this->assertSame(0, ChannelAccount::count());
        $this->assertSame('error', session('status')['type']);
    }

    /** واختيار بلا اختيار معلّق يُرفض. */
    public function test_selecting_without_a_pending_authorization_is_rejected(): void
    {
        $this->controller->select(Request::create('/settings/channels/select', 'POST', [
            'external_id' => 'page-1',
        ]));

        $this->assertSame(0, ChannelAccount::count());
        $this->assertSame('error', session('status')['type']);
    }

    /** والإلغاء يمسح التوكنات من الجلسة. */
    public function test_cancelling_clears_the_pending_tokens_from_the_session(): void
    {
        $this->fakeMeta([$this->page('page-1', 'أ', 't1'), $this->page('page-2', 'ب', 't2')]);
        $this->runCallback(Channel::MESSENGER);

        $this->assertNotNull(session('channel_oauth_pending'));

        $this->controller->cancelSelection(Request::create('/settings/channels/select', 'DELETE'));

        $this->assertNull(session('channel_oauth_pending'));
        $this->assertSame(0, ChannelAccount::count());
    }

    /** وحساب بلا صفحات إطلاقاً يُردّ برسالة تقول ماذا يفعل. */
    public function test_an_account_without_pages_is_told_what_to_do(): void
    {
        $this->fakeMeta([]);

        $this->controller->callback($this->callbackRequest(Channel::MESSENGER), Channel::MESSENGER);

        $this->assertSame('error', session('status')['type']);
        $this->assertStringContainsString('Page', session('status')['message']);
        $this->assertSame(0, ChannelAccount::count());
    }

    /** وفشل تبديل الـ code يصل برسالة المنصّة لا برسالة عامّة. */
    public function test_a_failed_code_exchange_surfaces_the_platform_message(): void
    {
        Http::fake([
            '*/oauth/access_token*' => Http::response([
                'error' => ['message' => 'This authorization code has expired.'],
            ], 400),
        ]);

        $this->controller->callback($this->callbackRequest(Channel::MESSENGER), Channel::MESSENGER);

        $this->assertSame('error', session('status')['type']);
        $this->assertStringContainsString('expired', session('status')['message']);
        $this->assertSame(0, ChannelAccount::count());
    }

    /* ───────────────────── إنستغرام ───────────────────── */

    /** الصفحة المرتبطة بحساب إنستغرام تُربط بمعرّف إنستغرام لا الصفحة. */
    public function test_instagram_connects_with_the_instagram_account_id(): void
    {
        $this->fakeMetaRaw([[
            'id' => 'page-1',
            'name' => 'متجر',
            'access_token' => 'page-token',
            'instagram_business_account' => [
                'id' => 'ig-555',
                'username' => 'my_store',
                'name' => 'متجري',
                'profile_picture_url' => 'https://cdn/x.jpg',
            ],
        ]]);

        $this->runCallback(Channel::INSTAGRAM);

        $account = ChannelAccount::where('channel', Channel::INSTAGRAM)->first();
        $this->assertNotNull($account);
        // المعرّف هو ما يصل في entry[].id من الـ webhook.
        $this->assertSame('ig-555', $account->external_id);
        $this->assertSame('my_store', $account->username);
        // وتوكن الصفحة هو ما يُرسل رسائل إنستغرام.
        $this->assertSame('page-token', $account->credential('access_token'));
        $this->assertSame('page-1', $account->credential('page_id'));
    }

    /**
     * والصفحة بلا حساب إنستغرام مرتبط تُسقَط من القائمة.
     *
     * عرضها يُغري المستخدم باختيارها ثم يفشل الربط بلا سبب مفهوم.
     */
    public function test_pages_without_a_linked_instagram_account_are_dropped(): void
    {
        $this->fakeMetaRaw([
            ['id' => 'page-1', 'name' => 'بلا إنستغرام', 'access_token' => 'token-1'],
            [
                'id' => 'page-2',
                'name' => 'مع إنستغرام',
                'access_token' => 'token-2',
                'instagram_business_account' => ['id' => 'ig-777', 'username' => 'store2'],
            ],
        ]);

        $this->runCallback(Channel::INSTAGRAM);

        // واحدة صالحة فقط ⇒ تُربط فوراً بلا خطوة اختيار.
        $this->assertSame(1, ChannelAccount::count());
        $this->assertSame('ig-777', ChannelAccount::first()->external_id);
    }

    /** وحساب بلا إنستغرام إطلاقاً يُردّ برسالته الخاصّة. */
    public function test_no_linked_instagram_account_gets_its_own_message(): void
    {
        $this->fakeMetaRaw([['id' => 'page-1', 'name' => 'بلا', 'access_token' => 't']]);

        $this->controller->callback($this->callbackRequest(Channel::INSTAGRAM), Channel::INSTAGRAM);

        $this->assertSame('error', session('status')['type']);
        $this->assertStringContainsString('Instagram', session('status')['message']);
    }

    /* ───────────────────── تيك توك ───────────────────── */

    /** التفويض الناجح يحفظ التوكنين ومواعيد انتهائهما. */
    public function test_tiktok_stores_both_tokens_and_their_expiry(): void
    {
        $this->fakeTikTok();

        $this->runCallback(Channel::TIKTOK);

        $account = ChannelAccount::where('channel', Channel::TIKTOK)->first();
        $this->assertNotNull($account);
        $this->assertSame('biz-open-id', $account->external_id);
        $this->assertSame('متجري', $account->name);
        $this->assertSame('my_tiktok', $account->username);
        $this->assertSame('tt-access', $account->credential('access_token'));
        $this->assertSame('tt-refresh', $account->credential('refresh_token'));

        // المواعيد أعمدةٌ لا JSON — المهمّة المجدولة تستعلم عنها بفهرس.
        $this->assertNotNull($account->token_expires_at);
        $this->assertNotNull($account->refresh_token_expires_at);
        $this->assertTrue($account->token_expires_at->isFuture());

        // ولا تبقى المدد داخل بيانات الاعتماد مكرّرة.
        $this->assertNull($account->credential('expires_in'));
    }

    /**
     * والصلاحيات الممنوحة جزئياً تُرفض.
     *
     * تيك توك تسمح للمستخدم برفض بعضها، والربط الناقص يبدو ناجحاً ثم يفشل
     * أول إرسال برسالة غامضة.
     */
    public function test_partially_granted_tiktok_scopes_are_refused(): void
    {
        Http::fake([
            '*/tt_user/oauth2/token/' => Http::response([
                'code' => 0,
                'data' => [
                    'open_id' => 'biz-open-id',
                    // ناقصة: message.list.send
                    'scope' => 'user.info.basic,user.info.username,user.info.profile,user.account.type,message.list.read,message.list.manage',
                    'access_token' => 'tt-access',
                    'refresh_token' => 'tt-refresh',
                    'expires_in' => 86400,
                    'refresh_token_expires_in' => 2592000,
                ],
            ], 200),
        ]);

        $this->controller->callback($this->callbackRequest(Channel::TIKTOK), Channel::TIKTOK);

        $this->assertSame('error', session('status')['type']);
        $this->assertStringContainsString('message.list.send', session('status')['message']);
        $this->assertSame(0, ChannelAccount::count());
    }

    /** ورفض تيك توك للـ code يصل برسالتها. */
    public function test_a_tiktok_rejection_surfaces_its_message(): void
    {
        Http::fake([
            '*/tt_user/oauth2/token/' => Http::response([
                'code' => 40001,
                'message' => 'Invalid auth code',
            ], 200),
        ]);

        $this->controller->callback($this->callbackRequest(Channel::TIKTOK), Channel::TIKTOK);

        $this->assertSame('error', session('status')['type']);
        $this->assertStringContainsString('Invalid auth code', session('status')['message']);
        $this->assertSame(0, ChannelAccount::count());
    }

    /** وفشل جلب اسم الحساب لا يمنع الربط — بيانات عرض لا أكثر. */
    public function test_a_failed_profile_fetch_still_connects(): void
    {
        Http::fake([
            '*/tt_user/oauth2/token/' => Http::response([
                'code' => 0,
                'data' => [
                    'open_id' => 'biz-open-id',
                    'scope' => implode(',', \App\Services\Messaging\Drivers\TikTokDriver::REQUIRED_SCOPES),
                    'access_token' => 'tt-access',
                    'refresh_token' => 'tt-refresh',
                    'expires_in' => 86400,
                    'refresh_token_expires_in' => 2592000,
                ],
            ], 200),
            '*/business/get/*' => Http::response(['code' => 40001, 'message' => 'nope'], 200),
            '*/business/webhook/update/' => Http::response(['code' => 0], 200),
        ]);

        $this->runCallback(Channel::TIKTOK);

        $account = ChannelAccount::where('channel', Channel::TIKTOK)->first();
        $this->assertNotNull($account);
        $this->assertNull($account->name);
    }

    /* ───────────────────── مشترك ───────────────────── */

    /** الحساب المربوط بمنشأة أخرى يُرفض ولا يُسرَق. */
    public function test_an_account_connected_elsewhere_is_refused(): void
    {
        $otherOwner = User::factory()->create(['role' => 'user']);
        $other = Organization::factory()->create(['created_by' => $otherOwner->id]);

        ChannelAccount::create([
            'organization_id' => $other->id,
            'channel' => Channel::MESSENGER,
            'external_id' => 'page-1',
            'credentials' => ['access_token' => 'theirs'],
            'status' => ChannelAccount::STATUS_ACTIVE,
            'created_by' => 0,
        ]);

        $this->fakeMeta([$this->page('page-1', 'متجر', 'mine')]);

        $this->controller->callback($this->callbackRequest(Channel::MESSENGER), Channel::MESSENGER);

        $this->assertSame('error', session('status')['type']);
        $this->assertSame(1, ChannelAccount::where('external_id', 'page-1')->count());
        // ولم يُستبدل توكن المنشأة الأخرى.
        $this->assertSame('theirs', ChannelAccount::first()->credential('access_token'));
    }

    /** وإعادة التفويض تُحدّث الحساب القائم وتُصلح حالته. */
    public function test_reauthorizing_updates_and_revives_the_account(): void
    {
        $existing = ChannelAccount::create([
            'organization_id' => $this->organization->id,
            'channel' => Channel::MESSENGER,
            'external_id' => 'page-1',
            'credentials' => ['access_token' => 'old-token'],
            'status' => ChannelAccount::STATUS_REAUTHORIZATION_REQUIRED,
            'last_error' => 'انتهى التوكن',
            'created_by' => 0,
        ]);

        $this->fakeMeta([$this->page('page-1', 'متجر', 'new-token')]);

        $this->runCallback(Channel::MESSENGER);

        $this->assertSame(1, ChannelAccount::count());

        $fresh = $existing->fresh();
        $this->assertSame('new-token', $fresh->credential('access_token'));
        $this->assertTrue($fresh->isActive());
        $this->assertNull($fresh->last_error);
    }

    /** وفشل تسجيل الـ webhook يحفظ الحساب ويُحذّر — لا يُسقط الربط. */
    public function test_a_failed_webhook_registration_warns_but_keeps_the_account(): void
    {
        Http::fake([
            '*/oauth/access_token*' => Http::response(['access_token' => 'long-lived-user-token'], 200),
            '*/me/accounts*' => Http::response(['data' => [$this->page('page-1', 'متجر', 'page-token')]], 200),
            '*/subscribed_apps' => Http::response(['error' => ['message' => 'nope']], 400),
        ]);

        $this->runCallback(Channel::MESSENGER);

        $this->assertSame(1, ChannelAccount::count());
        $this->assertSame('warning', session('status')['type']);
    }

    /** ولا يُسجَّل توكن في السجلّ عند الفشل. */
    public function test_tokens_are_never_written_to_the_log(): void
    {
        \Illuminate\Support\Facades\Log::spy();

        Http::fake([
            '*/oauth/access_token*' => Http::response(['access_token' => 'long-lived-user-token'], 200),
            '*/me/accounts*' => Http::response(null, 500),
        ]);

        $this->controller->callback($this->callbackRequest(Channel::MESSENGER), Channel::MESSENGER);

        \Illuminate\Support\Facades\Log::shouldNotHaveReceived('error', [
            \Mockery::any(),
            \Mockery::on(fn ($context) => str_contains(json_encode($context), 'long-lived-user-token')),
        ]);
    }

    /* ───────────────────── مساعدات ───────────────────── */

    private function request(): Request
    {
        return Request::create('/settings/channels/connect', 'GET');
    }

    private function validState(string $channel): string
    {
        return OAuthState::issue($channel, (int) $this->organization->id, (int) $this->owner->id);
    }

    private function callbackRequest(string $channel): Request
    {
        return Request::create("/channels/oauth/{$channel}/callback", 'GET', [
            'code' => 'the-code',
            'state' => $this->validState($channel),
        ]);
    }

    private function runCallback(string $channel)
    {
        return $this->controller->callback($this->callbackRequest($channel), $channel);
    }

    /** @return array<string, mixed> */
    private function page(string $id, string $name, string $token): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'access_token' => $token,
            'picture' => ['data' => ['url' => 'https://cdn/' . $id . '.jpg']],
        ];
    }

    private function fakeMeta(array $pages): void
    {
        $this->fakeMetaRaw($pages);
    }

    private function fakeMetaRaw(array $pages): void
    {
        Http::fake([
            '*/oauth/access_token*' => Http::response(['access_token' => 'long-lived-user-token'], 200),
            '*/me/accounts*' => Http::response(['data' => $pages], 200),
            '*/subscribed_apps' => Http::response(['success' => true], 200),
        ]);
    }

    private function fakeTikTok(): void
    {
        Http::fake([
            '*/tt_user/oauth2/token/' => Http::response([
                'code' => 0,
                'data' => [
                    'open_id' => 'biz-open-id',
                    'scope' => implode(',', \App\Services\Messaging\Drivers\TikTokDriver::REQUIRED_SCOPES),
                    'access_token' => 'tt-access',
                    'refresh_token' => 'tt-refresh',
                    'expires_in' => 86400,
                    'refresh_token_expires_in' => 2592000,
                ],
            ], 200),
            '*/business/get/*' => Http::response([
                'code' => 0,
                'data' => [
                    'username' => 'my_tiktok',
                    'display_name' => 'متجري',
                    'profile_image' => 'https://cdn/tt.jpg',
                ],
            ], 200),
            '*/business/webhook/update/' => Http::response(['code' => 0], 200),
        ]);
    }
}
