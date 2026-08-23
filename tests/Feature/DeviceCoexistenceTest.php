<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserDevice;
use App\Services\UserDeviceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * الداشبورد والتطبيق يعملان معاً لحساب واحد.
 *
 * كان أيّ دخول يطرد الفئة الأخرى: دخولٌ على اللوحة يُبطل رموز الجوال، ودخولٌ
 * على التطبيق يُنهي جلسة المتصفّح. أُلغي بطلب العميل — الموظّف يحتاج اللوحة
 * على حاسبه والتطبيق على جواله في آنٍ واحد، والتأرجح بينهما مع كل دخول عائقٌ
 * لا حماية.
 *
 * وما بقي هو «جهاز واحد لكل فئة»: متصفّح ثانٍ يُخرج الأوّل، وجوال ثانٍ كذلك.
 * الاختبار يحرس الأمرين معاً — إلغاء الطرد المتبادل، وبقاء الحدّ داخل الفئة.
 */
class DeviceCoexistenceTest extends TestCase
{
    use RefreshDatabase;

    private UserDeviceService $service;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(UserDeviceService::class);
        $this->user = User::factory()->create(['role' => 'user']);
    }

    /** @return array<string, mixed> */
    private function deviceData(string $name): array
    {
        return [
            'device_name' => $name,
            'device_type' => 'phone',
            'browser' => 'Chrome',
            'platform' => 'Android',
        ];
    }

    private function issueMobileToken(): string
    {
        return $this->user->createToken('mobile')->plainTextToken;
    }

    private function tokensAlive(): int
    {
        return DB::table('personal_access_tokens')
            ->where('tokenable_id', $this->user->id)
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->count();
    }

    // ------------------------------------------------- التعايش

    /**
     * جوهر التعديل: الدخول على اللوحة لا يُبطل رموز الجوال.
     */
    public function test_signing_in_on_the_dashboard_keeps_the_mobile_session(): void
    {
        $this->issueMobileToken();
        $this->service->registerOrTouch($this->user, $this->deviceData('phone'), 'mobile');

        $this->assertSame(1, $this->tokensAlive());

        $this->service->registerOrTouch($this->user, $this->deviceData('laptop'), 'web');

        $this->assertSame(1, $this->tokensAlive(), 'رموز الجوال أُبطلت عند الدخول على اللوحة.');
    }

    /** ولا يُسجَّل سبب طرد ما دام لا طرد. */
    public function test_no_revocation_reason_is_recorded(): void
    {
        $this->issueMobileToken();
        $this->service->registerOrTouch($this->user, $this->deviceData('laptop'), 'web');

        $this->assertNull(
            DB::table('personal_access_tokens')->where('tokenable_id', $this->user->id)->value('revoked_reason')
        );
    }

    /** والدخول على التطبيق لا يُدوّر معرّف جهاز الويب فيُنهي جلسة المتصفّح. */
    public function test_signing_in_on_mobile_keeps_the_web_session(): void
    {
        $web = $this->service->registerOrTouch($this->user, $this->deviceData('laptop'), 'web');
        $identifier = $web->device_identifier;

        $this->service->registerOrTouch($this->user, $this->deviceData('phone'), 'mobile');

        $this->assertSame(
            $identifier,
            UserDevice::find($web->id)->device_identifier,
            'معرّف جهاز الويب تغيّر — جلسة المتصفّح ستُطرد عند أوّل طلب.'
        );
    }

    /** والفئتان تبقيان مسجَّلتين معاً. */
    public function test_both_categories_stay_registered(): void
    {
        $this->service->registerOrTouch($this->user, $this->deviceData('phone'), 'mobile');
        $this->service->registerOrTouch($this->user, $this->deviceData('laptop'), 'web');

        $this->assertNotNull($this->user->deviceForCategory('mobile'));
        $this->assertNotNull($this->user->deviceForCategory('web'));
    }

    // ------------------------------------- الحدّ داخل الفئة يبقى

    /**
     * ما لم يُلغَ: جهاز واحد لكل فئة. معرّف الجهاز يُجدَّد مع كل تسجيل،
     * وEnsureDeviceIsCurrent يقارنه بما في الجلسة فيُخرج السابق.
     */
    public function test_a_second_browser_still_evicts_the_first(): void
    {
        $first = $this->service->registerOrTouch($this->user, $this->deviceData('laptop'), 'web');
        $identifier = $first->device_identifier;

        $second = $this->service->registerOrTouch($this->user, $this->deviceData('desktop'), 'web');

        $this->assertSame($first->id, $second->id, 'صفّ واحد لكل فئة لا صفّان');
        $this->assertNotSame($identifier, $second->device_identifier, 'المتصفّح الأوّل لم يُطرد.');
    }

    public function test_a_second_phone_still_evicts_the_first(): void
    {
        $first = $this->service->registerOrTouch($this->user, $this->deviceData('phone-a'), 'mobile');
        $identifier = $first->device_identifier;

        $second = $this->service->registerOrTouch($this->user, $this->deviceData('phone-b'), 'mobile');

        $this->assertSame($first->id, $second->id);
        $this->assertNotSame($identifier, $second->device_identifier);
    }

    // --------------------------------------------------- الحراسة

    /** لا يعود الطرد المتبادل خِلسةً. */
    public function test_the_cross_category_eviction_is_gone(): void
    {
        $source = file_get_contents(base_path('app/Services/UserDeviceService.php'));

        $this->assertStringNotContainsString('evictOtherCategory', $source);
        $this->assertStringNotContainsString('TokenRevocation::revokeAll', $source);
    }

    /**
     * وقراءة سبب الخروج تبقى: الرمز المُبطَل لأي سبب آخر ما زال يحتاج رسالة
     * مفهومة بدل 401 عارٍ.
     */
    public function test_the_reason_reader_is_still_wired_for_other_revocations(): void
    {
        $handler = file_get_contents(base_path('app/Exceptions/Handler.php'));

        $this->assertStringContainsString('TokenRevocation::reasonForRequest', $handler);
    }
}
