<?php

namespace Tests\Feature\Channels;

use App\Models\ChannelAccount;
use App\Models\Organization;
use App\Models\User;
use App\Services\Messaging\Channel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * المهمّة المجدولة التي تُبقي ربط تيك توك حيّاً.
 *
 * بلا هذه المهمّة تنقطع القناة كل أربع وعشرين ساعة. والأسوأ: refresh token
 * يموت بعد ثلاثين يوماً من الخمول، فالحساب الذي لا تصله رسائل شهراً يفقد
 * ربطه نهائياً ويحتاج تدخّل العميل.
 */
class RefreshChannelTokensTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.tiktok.app_id' => 'tt-app',
            'services.tiktok.app_secret' => 'tt-secret',
        ]);

        $owner = User::factory()->create(['role' => 'user']);
        $this->organization = Organization::factory()->create(['created_by' => $owner->id]);
    }

    /** التوكن الذي يوشك على الانتهاء يُجدَّد. */
    public function test_a_token_close_to_expiry_is_refreshed(): void
    {
        $account = $this->tiktokAccount(now()->addMinutes(30), now()->addDays(20));

        Http::fake([
            '*/tt_user/oauth2/refresh_token/' => Http::response([
                'code' => 0,
                'data' => [
                    'access_token' => 'fresh-token',
                    'refresh_token' => 'fresh-refresh',
                    'expires_in' => 86400,
                    'refresh_token_expires_in' => 2592000,
                ],
            ], 200),
        ]);

        $this->artisan('channels:refresh-tokens')->assertSuccessful();

        $fresh = $account->fresh();
        $this->assertSame('fresh-token', $fresh->credential('access_token'));
        $this->assertTrue($fresh->isActive());
        $this->assertTrue($fresh->token_expires_at->isAfter(now()->addHours(20)));
    }

    /** والتوكن البعيد عن الانتهاء لا يُلمس — لا نداء شبكة أصلاً. */
    public function test_a_healthy_token_is_left_alone(): void
    {
        $this->tiktokAccount(now()->addHours(20), now()->addDays(20));

        Http::fake();

        $this->artisan('channels:refresh-tokens')->assertSuccessful();

        Http::assertNothingSent();
    }

    /**
     * وانتهاء refresh token يُعلّم الحساب بدل أن يطرق باباً مغلقاً — وهي
     * الحالة الوحيدة التي تحتاج تدخّل العميل.
     */
    public function test_a_dead_refresh_token_flags_the_account_without_calling_out(): void
    {
        $account = $this->tiktokAccount(now()->subHour(), now()->subDay());

        Http::fake();

        $this->artisan('channels:refresh-tokens')->assertSuccessful();

        $this->assertTrue($account->fresh()->needsReauthorization());
        Http::assertNothingSent();
    }

    /** والحساب المعطّل لا يُجدَّد. */
    public function test_a_disabled_account_is_skipped(): void
    {
        $account = $this->tiktokAccount(now()->addMinutes(10), now()->addDays(20));
        $account->forceFill(['status' => ChannelAccount::STATUS_DISABLED])->save();

        Http::fake();

        $this->artisan('channels:refresh-tokens')->assertSuccessful();

        Http::assertNothingSent();
    }

    /** وقناة بلا توكنات منتهية لا تدخل المهمّة إطلاقاً. */
    public function test_channels_without_expiring_tokens_are_untouched(): void
    {
        ChannelAccount::create([
            'organization_id' => $this->organization->id,
            'channel' => Channel::TELEGRAM,
            'external_id' => 'bot-1',
            'credentials' => ['bot_token' => 'x'],
            'status' => ChannelAccount::STATUS_ACTIVE,
            'created_by' => 0,
        ]);

        Http::fake();

        $this->artisan('channels:refresh-tokens')->assertSuccessful();

        Http::assertNothingSent();
    }

    /** وفشل حساب واحد لا يمنع تجديد البقيّة. */
    public function test_one_failing_account_does_not_stop_the_others(): void
    {
        $failing = $this->tiktokAccount(now()->addMinutes(5), now()->addDays(20), 'biz-fail');
        $working = $this->tiktokAccount(now()->addMinutes(5), now()->addDays(20), 'biz-ok');

        Http::fake([
            '*/tt_user/oauth2/refresh_token/' => Http::sequence()
                ->push(['code' => 40100, 'message' => 'Invalid refresh token'], 200)
                ->push([
                    'code' => 0,
                    'data' => [
                        'access_token' => 'ok-token',
                        'refresh_token' => 'ok-refresh',
                        'expires_in' => 86400,
                        'refresh_token_expires_in' => 2592000,
                    ],
                ], 200),
        ]);

        $this->artisan('channels:refresh-tokens')->assertSuccessful();

        $this->assertTrue($failing->fresh()->needsReauthorization());
        $this->assertSame('ok-token', $working->fresh()->credential('access_token'));
    }

    /** وخيار القناة يقصر المهمّة عليها. */
    public function test_the_channel_option_narrows_the_job(): void
    {
        $this->tiktokAccount(now()->addMinutes(5), now()->addDays(20));

        Http::fake();

        $this->artisan('channels:refresh-tokens', ['--channel' => 'telegram'])->assertSuccessful();

        Http::assertNothingSent();
    }

    /** وقناة مجهولة تُرفض بخطأ واضح. */
    public function test_an_unknown_channel_option_fails_loudly(): void
    {
        $this->artisan('channels:refresh-tokens', ['--channel' => 'myspace'])->assertFailed();
    }

    private function tiktokAccount($expiresAt, $refreshExpiresAt, string $externalId = 'biz-1'): ChannelAccount
    {
        return ChannelAccount::create([
            'organization_id' => $this->organization->id,
            'channel' => Channel::TIKTOK,
            'external_id' => $externalId,
            'credentials' => ['access_token' => 'old', 'refresh_token' => 'old-refresh'],
            'token_expires_at' => $expiresAt,
            'refresh_token_expires_at' => $refreshExpiresAt,
            'status' => ChannelAccount::STATUS_ACTIVE,
            'created_by' => 0,
        ]);
    }
}
