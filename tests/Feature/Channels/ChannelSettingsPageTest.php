<?php

namespace Tests\Feature\Channels;

use App\Models\Addon;
use App\Models\ChannelAccount;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Team;
use App\Models\User;
use App\Services\Messaging\Channel;
use App\Services\Messaging\OAuth\OAuthState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * صفحة القنوات بالبطاقات، وصفحة إدارة كل قناة.
 *
 * كانت القنوات غير واتساب تبويبات تحتها نموذج الربط. صارت بطاقات بالشكل
 * الذي لواتساب والمحادثة المباشرة، و«إدارة» تفتح صفحة القناة. هنا عبر HTTP
 * لأن المسار الجديد وقيده ومنع الموظّف هي ما يُختبر.
 */
class ChannelSettingsPageTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        config([
            'services.meta.app_id' => 'meta-app',
            'services.meta.app_secret' => 'meta-secret',
            'services.tiktok.app_id' => 'tt-app',
            'services.tiktok.app_secret' => 'tt-secret',
        ]);

        // الـ layout المشترك يقرأه في كل صفحة Inertia.
        Addon::factory()->create(['name' => 'Google Authenticator']);

        $this->owner = User::factory()->create(['role' => 'user']);
        $this->organization = Organization::factory()->create(['created_by' => $this->owner->id]);

        $plan = SubscriptionPlan::create([
            'name' => 'Plan',
            'price' => 0,
            'period' => 'monthly',
            'metadata' => json_encode(['addons' => []]),
        ]);
        Subscription::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'valid_until' => now()->addYear(),
        ]);

        Team::factory()->create([
            'user_id' => $this->owner->id,
            'organization_id' => $this->organization->id,
            'role' => 'owner',
            'status' => 'active',
        ]);

        $this->actingAs($this->owner);
        session(['current_organization' => $this->organization->id]);
    }

    /** بطاقة لكل قناة تُربط من هنا، وحالة كلٍّ منها تُبنى من حسابها. */
    public function test_the_list_has_a_card_for_every_channel_with_its_status(): void
    {
        $this->account(Channel::TELEGRAM, '777001', ['username' => 'support_bot']);
        $this->account(Channel::TIKTOK, 'biz-1', ['status' => ChannelAccount::STATUS_REAUTHORIZATION_REQUIRED]);

        $this->get('/settings/channels')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('User/Settings/Channels')
                ->has('channels', count(Channel::connectable()))
                ->where('whatsappConnected', false)
                ->has('webChat')
                // بدونها كان رابط «إعدادات الأتمتة» يختفي من الشريط الجانبي.
                ->has('modules')
            );

        $cards = collect($this->props('/settings/channels')['channels'])->keyBy('channel');

        $this->assertSame('support_bot', $cards[Channel::TELEGRAM]['account']['username']);
        $this->assertTrue($cards[Channel::TELEGRAM]['account']['is_active']);
        $this->assertTrue($cards[Channel::TIKTOK]['account']['needs_reauthorization']);
        $this->assertNull($cards[Channel::MESSENGER]['account']);
    }

    /** وصفحة القناة تُفتح لكل قناة تُربط من هنا. */
    public function test_every_connectable_channel_has_its_own_page(): void
    {
        foreach (Channel::connectable() as $channel) {
            $this->get('/settings/channels/' . $channel)
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('User/Settings/Channel')
                    ->where('card.channel', $channel)
                    ->has('card.fields')
                    ->has('card.capabilities')
                    ->has('modules')
                    ->where('pendingSelection', null)
                );
        }
    }

    /**
     * واتساب والمحادثة المباشرة لهما صفحتاهما، وقناة مجهولة لا صفحة لها.
     * القيد على المسار يمنع أن يلتقط مساراً ثابتاً يُعرَّف بعده.
     */
    public function test_whatsapp_webchat_and_unknown_channels_have_no_generic_page(): void
    {
        // 405 لا 404: DELETE /settings/channels/{uuid} يطابق العنوان بفعل آخر.
        // المهمّ أن لا صفحة تُفتح.
        foreach (['whatsapp', 'webchat', 'myspace'] as $channel) {
            $status = $this->get('/settings/channels/' . $channel)->getStatusCode();
            $this->assertContains($status, [404, 405], "{$channel} opened a page ({$status})");
        }
    }

    /** الموظّف لا يدير القنوات — لا القائمة ولا صفحة القناة. */
    public function test_an_agent_cannot_open_the_channel_pages(): void
    {
        Team::where('user_id', $this->owner->id)->update(['role' => 'agent']);

        $this->get('/settings/channels')->assertForbidden();
        $this->get('/settings/channels/telegram')->assertForbidden();
    }

    /** بيانات الاعتماد لا تصل صفحة القناة في أي حال. */
    public function test_the_channel_page_never_exposes_credentials(): void
    {
        $this->account(Channel::TELEGRAM, '777001', ['credentials' => ['bot_token' => 'super-secret-token']]);

        $response = $this->get('/settings/channels/telegram')->assertOk();

        $this->assertStringNotContainsString('super-secret-token', $response->getContent());
    }

    /**
     * التفويض الذي أعاد عدّة صفحات يعود إلى صفحة القناة نفسها، والاختيار
     * يظهر هناك.
     */
    public function test_a_multi_page_authorization_returns_to_the_channel_page(): void
    {
        $this->fakeMetaPages(2);

        $response = $this->get('/channels/oauth/messenger/callback?' . http_build_query([
            'code' => 'the-code',
            'state' => OAuthState::issue(Channel::MESSENGER, (int) $this->organization->id, (int) $this->owner->id),
        ]));

        $response->assertRedirect('/settings/channels/messenger?select=messenger');

        $this->get('/settings/channels/messenger')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('pendingSelection.channel', Channel::MESSENGER)
                ->has('pendingSelection.accounts', 2)
            );

        // وصفحة قناة أخرى لا تعرضه.
        $this->get('/settings/channels/instagram')
            ->assertInertia(fn (Assert $page) => $page->where('pendingSelection', null));
    }

    /**
     * والقائمة تُفتح عادةً مع اختيار معلّق — لا تحويل يحبس المستخدم في صفحة
     * قناة لم يعد يريدها.
     */
    public function test_the_list_still_opens_while_a_selection_is_pending(): void
    {
        session(['channel_oauth_pending' => [
            'channel' => Channel::MESSENGER,
            'organization_id' => $this->organization->id,
            'accounts' => [],
        ]]);

        $this->get('/settings/channels')->assertOk();
    }

    /** واختيار معلّق لمنشأة أخرى لا يظهر هنا ولا يحوّل. */
    public function test_a_pending_selection_of_another_organization_is_not_shown(): void
    {
        $other = Organization::factory()->create(['created_by' => $this->owner->id]);

        session(['channel_oauth_pending' => [
            'channel' => Channel::MESSENGER,
            'organization_id' => $other->id,
            'accounts' => [['external_id' => 'p1', 'name' => 'x', 'credentials' => ['access_token' => 't']]],
        ]]);

        $this->get('/settings/channels')->assertOk();
        $this->get('/settings/channels/messenger')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('pendingSelection', null));
    }

    /** الإلغاء والاختيار يعودان إلى صفحة القناة. */
    public function test_cancel_and_select_return_to_the_channel_page(): void
    {
        $this->fakeMetaPages(2);
        $this->get('/channels/oauth/messenger/callback?' . http_build_query([
            'code' => 'the-code',
            'state' => OAuthState::issue(Channel::MESSENGER, (int) $this->organization->id, (int) $this->owner->id),
        ]));

        $this->delete('/settings/channels/select')->assertRedirect('/settings/channels/messenger');
        $this->assertNull(session('channel_oauth_pending'));

        $this->get('/channels/oauth/messenger/callback?' . http_build_query([
            'code' => 'the-code',
            'state' => OAuthState::issue(Channel::MESSENGER, (int) $this->organization->id, (int) $this->owner->id),
        ]));

        $this->post('/settings/channels/select', ['external_id' => 'page-2'])
            ->assertRedirect('/settings/channels/messenger');

        $this->assertSame('page-2', ChannelAccount::where('channel', Channel::MESSENGER)->value('external_id'));
    }

    /** وتيليجرام (تُربط بلصق توكن) تُردّ من مسار التفويض إلى صفحتها برسالة. */
    public function test_telegram_authorization_attempt_returns_to_its_page(): void
    {
        $this->get('/settings/channels/connect/telegram')->assertRedirect('/settings/channels/telegram');
        $this->assertSame('error', session('status')['type']);
    }

    /* ───────────────────────── مساعدات ───────────────────────── */

    private function account(string $channel, string $externalId, array $overrides = []): ChannelAccount
    {
        return ChannelAccount::create($overrides + [
            'organization_id' => $this->organization->id,
            'channel' => $channel,
            'external_id' => $externalId,
            'credentials' => ['bot_token' => 'x'],
            'status' => ChannelAccount::STATUS_ACTIVE,
            'created_by' => $this->owner->id,
        ]);
    }

    private function props(string $url): array
    {
        return $this->get($url)->viewData('page')['props'];
    }

    private function fakeMetaPages(int $count): void
    {
        $pages = [];
        for ($i = 1; $i <= $count; $i++) {
            $pages[] = [
                'id' => 'page-' . $i,
                'name' => 'متجر ' . $i,
                'access_token' => 'token-' . $i,
                'picture' => ['data' => ['url' => 'https://cdn/page-' . $i . '.jpg']],
            ];
        }

        Http::fake([
            '*/oauth/access_token*' => Http::response(['access_token' => 'long-lived-user-token'], 200),
            '*/me/accounts*' => Http::response(['data' => $pages], 200),
            '*/subscribed_apps' => Http::response(['success' => true], 200),
        ]);
    }
}
