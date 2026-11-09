<?php

namespace Tests\Feature\Channels;

use App\Http\Controllers\User\ChannelAccountController;
use App\Models\ChannelAccount;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use App\Services\Messaging\Channel;
use App\Services\Messaging\ChannelManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ربط حسابات المنصّات وفصلها.
 *
 * القاعدة التي تُثبَّت هنا: لا يُحفظ حساب ببيانات لم تقبلها المنصّة. الحساب
 * المحفوظ خطأً يبدو مربوطاً في الواجهة ولا يستقبل شيئاً — عطب صامت يكتشفه
 * العميل بعد أيام.
 *
 * نُنادي المتحكّم مباشرةً لا عبر HTTP: مسار الإعدادات محروس باشتراك فعّال
 * وتحقّق بريد وصلاحيات، وبناء ذلك كلّه يختبر الـ middleware لا منطق الربط.
 */
class ChannelSettingsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;
    private User $owner;
    private ChannelAccountController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['role' => 'user']);
        $this->organization = Organization::factory()->create(['created_by' => $this->owner->id]);

        Team::factory()->create([
            'user_id' => $this->owner->id,
            'organization_id' => $this->organization->id,
            'role' => 'owner',
        ]);

        $this->actingAs($this->owner);
        session(['current_organization' => $this->organization->id]);

        $this->controller = new ChannelAccountController(app(ChannelManager::class));
    }

    /** الربط الناجح يحفظ الحساب ويُسجّل الـ webhook. */
    public function test_connecting_telegram_saves_the_account_and_registers_the_webhook(): void
    {
        Http::fake([
            '*/getMe' => Http::response([
                'ok' => true,
                'result' => ['id' => 777001, 'first_name' => 'بوت الدعم', 'username' => 'support_bot'],
            ], 200),
            '*/setWebhook' => Http::response(['ok' => true, 'result' => true], 200),
        ]);

        $this->connect(Channel::TELEGRAM, ['bot_token' => '777001:AAF-token']);

        $account = ChannelAccount::where('channel', Channel::TELEGRAM)->first();
        $this->assertNotNull($account);
        $this->assertSame('777001', $account->external_id);
        $this->assertSame('بوت الدعم', $account->name);
        $this->assertSame('support_bot', $account->username);
        $this->assertTrue($account->isActive());
        $this->assertSame('777001:AAF-token', $account->credential('bot_token'));

        Http::assertSent(fn ($request) => str_contains($request->url(), '/setWebhook'));
    }

    /**
     * والتوكن الذي ترفضه المنصّة لا يُحفظ إطلاقاً.
     *
     * هذا جوهر الاختبار: الحفظ ثم اكتشاف الخطأ لاحقاً يعني عميلاً يظنّ
     * قناته تعمل بينما لا شيء يصل.
     */
    public function test_a_rejected_token_is_never_saved(): void
    {
        Http::fake([
            '*/getMe' => Http::response(['ok' => false, 'description' => 'Unauthorized'], 401),
        ]);

        $this->connect(Channel::TELEGRAM, ['bot_token' => 'wrong-token']);

        $this->assertSame(0, ChannelAccount::count());
    }

    /** والحقول الناقصة تُرفض قبل أي نداء شبكة. */
    public function test_missing_credentials_are_refused_without_calling_the_platform(): void
    {
        Http::fake();

        $this->connect(Channel::TELEGRAM, ['bot_token' => '   ']);

        $this->assertSame(0, ChannelAccount::count());
        Http::assertNothingSent();
    }

    /** وقناة مجهولة تُرفض بالتحقّق لا بخطأ خام. */
    public function test_an_unknown_channel_is_rejected_by_validation(): void
    {
        $this->expectException(ValidationException::class);

        $this->connect('myspace', ['token' => 'x']);
    }

    /** وواتساب لا تُربط من هنا — لها مسارها الخاصّ الذي لم نلمسه. */
    public function test_whatsapp_cannot_be_connected_from_this_page(): void
    {
        $this->expectException(ValidationException::class);

        $this->connect(Channel::WHATSAPP, ['access_token' => 'x']);
    }

    /** الحساب المربوط بمنشأة أخرى يُرفض. */
    public function test_an_account_already_connected_elsewhere_is_refused(): void
    {
        $otherOwner = User::factory()->create(['role' => 'user']);
        $other = Organization::factory()->create(['created_by' => $otherOwner->id]);

        ChannelAccount::create([
            'organization_id' => $other->id,
            'channel' => Channel::TELEGRAM,
            'external_id' => '777001',
            'credentials' => ['bot_token' => 'taken'],
            'status' => ChannelAccount::STATUS_ACTIVE,
            'created_by' => 0,
        ]);

        Http::fake([
            '*/getMe' => Http::response([
                'ok' => true,
                'result' => ['id' => 777001, 'first_name' => 'بوت'],
            ], 200),
        ]);

        $this->connect(Channel::TELEGRAM, ['bot_token' => '777001:AAF']);

        $this->assertSame(1, ChannelAccount::where('external_id', '777001')->count());
        $this->assertSame(0, ChannelAccount::where('organization_id', $this->organization->id)->count());
    }

    /** إعادة الربط تُحدّث الحساب القائم ولا تُنشئ ثانياً. */
    public function test_reconnecting_updates_the_existing_account(): void
    {
        $existing = ChannelAccount::create([
            'organization_id' => $this->organization->id,
            'channel' => Channel::TELEGRAM,
            'external_id' => '777001',
            'credentials' => ['bot_token' => 'old'],
            'status' => ChannelAccount::STATUS_REAUTHORIZATION_REQUIRED,
            'last_error' => 'انتهى',
            'created_by' => 0,
        ]);

        Http::fake([
            '*/getMe' => Http::response(['ok' => true, 'result' => ['id' => 777001, 'first_name' => 'بوت']], 200),
            '*/setWebhook' => Http::response(['ok' => true], 200),
        ]);

        $this->connect(Channel::TELEGRAM, ['bot_token' => 'new-token']);

        $this->assertSame(1, ChannelAccount::count());

        $fresh = $existing->fresh();
        $this->assertSame('new-token', $fresh->credential('bot_token'));
        $this->assertTrue($fresh->isActive());
        $this->assertNull($fresh->last_error);
    }

    /** الفصل حذف ناعم — المحادثات السابقة تبقى. */
    public function test_disconnecting_soft_deletes_and_keeps_history(): void
    {
        $account = ChannelAccount::create([
            'organization_id' => $this->organization->id,
            'channel' => Channel::TELEGRAM,
            'external_id' => '777001',
            'credentials' => ['bot_token' => 'x'],
            'status' => ChannelAccount::STATUS_ACTIVE,
            'created_by' => 0,
        ]);

        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $this->controller->destroy(Request::create('/settings/channels', 'DELETE'), $account->uuid);

        $this->assertSoftDeleted('channel_accounts', ['id' => $account->id]);
    }

    /** وحساب منشأة أخرى لا يُفصَل من هنا. */
    public function test_another_organizations_account_cannot_be_disconnected(): void
    {
        $otherOwner = User::factory()->create(['role' => 'user']);
        $other = Organization::factory()->create(['created_by' => $otherOwner->id]);

        $account = ChannelAccount::create([
            'organization_id' => $other->id,
            'channel' => Channel::TELEGRAM,
            'external_id' => '999',
            'credentials' => ['bot_token' => 'x'],
            'status' => ChannelAccount::STATUS_ACTIVE,
            'created_by' => 0,
        ]);

        $this->controller->destroy(Request::create('/settings/channels', 'DELETE'), $account->uuid);

        $this->assertNotSoftDeleted('channel_accounts', ['id' => $account->id]);
    }

    /**
     * وبعد الفصل يمكن ربط نفس الحساب من جديد — العمود المُولَّد يُخرج
     * الصفّ المحذوف من الفهرس الفريد.
     */
    public function test_the_same_account_can_be_reconnected_after_disconnecting(): void
    {
        $account = ChannelAccount::create([
            'organization_id' => $this->organization->id,
            'channel' => Channel::TELEGRAM,
            'external_id' => '777001',
            'credentials' => ['bot_token' => 'x'],
            'status' => ChannelAccount::STATUS_ACTIVE,
            'created_by' => 0,
        ]);

        $account->delete();

        Http::fake([
            '*/getMe' => Http::response(['ok' => true, 'result' => ['id' => 777001, 'first_name' => 'بوت']], 200),
            '*/setWebhook' => Http::response(['ok' => true], 200),
        ]);

        $this->connect(Channel::TELEGRAM, ['bot_token' => 'again']);

        $this->assertSame(
            1,
            ChannelAccount::where('organization_id', $this->organization->id)->whereNull('deleted_at')->count()
        );
    }

    /** الصفحة تُبنى ببطاقة لكل قناة غير واتساب، ومعها حقولها وقيودها. */
    public function test_the_page_describes_every_non_whatsapp_channel(): void
    {
        $response = $this->controller->index(Request::create('/settings/channels', 'GET'));

        $props = $response->toResponse(request())->getOriginalContent()->getData()['page']['props'];

        // كل قناة تُربط بحساب خارجي. المحادثة المباشرة لها صفحتها الخاصّة.
        $this->assertCount(count(Channel::connectable()), $props['channels']);
        $this->assertNull(collect($props['channels'])->firstWhere('channel', Channel::WEBCHAT));

        $telegram = collect($props['channels'])->firstWhere('channel', Channel::TELEGRAM);
        $this->assertNotNull($telegram);
        $this->assertNull($telegram['account']);
        $this->assertSame('bot_token', $telegram['fields'][0]['key']);
        $this->assertNotEmpty($telegram['notes']);
        // تيليجرام بلا نافذة — وهو ما يُعرض للمستخدم قبل أن يعد عميله.
        $this->assertNull($telegram['capabilities']['window_hours']);

        $tiktok = collect($props['channels'])->firstWhere('channel', Channel::TIKTOK);
        $this->assertSame(48, $tiktok['capabilities']['window_hours']);
    }

    /** وبيانات الاعتماد لا تصل الواجهة في أي حال. */
    public function test_the_page_never_exposes_credentials(): void
    {
        ChannelAccount::create([
            'organization_id' => $this->organization->id,
            'channel' => Channel::TELEGRAM,
            'external_id' => '777001',
            'credentials' => ['bot_token' => 'super-secret-token'],
            'status' => ChannelAccount::STATUS_ACTIVE,
            'created_by' => 0,
        ]);

        $response = $this->controller->index(Request::create('/settings/channels', 'GET'));
        $props = $response->toResponse(request())->getOriginalContent()->getData()['page']['props'];

        $this->assertStringNotContainsString('super-secret-token', json_encode($props));
    }

    private function connect(string $channel, array $credentials): void
    {
        $request = Request::create('/settings/channels', 'POST', [
            'channel' => $channel,
            'credentials' => $credentials,
        ]);

        $this->controller->store($request);
    }
}
