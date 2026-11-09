<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Template;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ترحيل إعادة مزامنة القوالب — إصلاح لمرّة واحدة بعد النشر.
 *
 * يُصلح ما تراكم قبل الإصلاح: قوالب عُدِّلت فلم تُحفظ نسختها، وروابط
 * موقَّعة انتهت صلاحيتها (الصورة عند Meta باقية — الإذن هو الذي مات).
 *
 * وأخطر ما فيه أنه **يُنادي شبكة من داخل ترحيل**. فما يُثبَّت هنا هو
 * حدوده: لا يعمل في الاختبارات، ولا يُفشل النشر، ولا يتجاوز حدّه الزمني.
 */
class TemplateResyncMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_09_24_090000_resync_whatsapp_template_metadata.php';

    /**
     * الحارس الأهمّ: الترحيل صامت في بيئة الاختبار.
     *
     * RefreshDatabase تُشغّل كل الترحيلات مع كل ملفّ اختبار. وبلا هذا
     * الحارس ينطلق نداء حقيقي إلى Meta في كل مرّة — يُبطئ السويت ويُفشله
     * حين لا تكون الشبكة متاحة.
     */
    public function test_the_migration_is_silent_in_the_testing_environment(): void
    {
        Http::fake();

        $migration = require base_path(self::MIGRATION);
        $migration->up();

        Http::assertNothingSent();
        $this->assertTrue(app()->environment('testing'));
    }

    /** ومصدره يحمل هذا الحارس صراحةً — حتى لا يُحذف سهواً. */
    public function test_the_environment_guard_is_present_in_the_source(): void
    {
        $source = file_get_contents(base_path(self::MIGRATION));

        $this->assertStringContainsString(
            "app()->environment('testing')",
            $source,
            'حارس بيئة الاختبار اختفى — الترحيل سينادي Meta في كل اختبار.'
        );
    }

    /**
     * ولا يُفشل النشر مهما حدث.
     *
     * إصلاح بيانات لا تغيير مخطّط: فشله يعني قوالب تنتظر الدورة الليلية،
     * لا نشراً متوقّفاً.
     */
    public function test_the_migration_never_throws(): void
    {
        $source = file_get_contents(base_path(self::MIGRATION));

        $this->assertStringContainsString('try {', $source);
        $this->assertStringContainsString('catch (\Throwable $e)', $source);
        $this->assertStringNotContainsString('throw $e', $source);
    }

    /** وله حدّ زمني — النشر لا يحتمل انتظاراً مفتوحاً. */
    public function test_the_migration_passes_a_time_budget(): void
    {
        $source = file_get_contents(base_path(self::MIGRATION));

        $this->assertStringContainsString("'--max-seconds'", $source);
        $this->assertMatchesRegularExpression('/MAX_SECONDS\s*=\s*\d+/', $source);
    }

    /** و`-v` ممرَّرة كما طُلب، فتظهر التفاصيل في مخرجات النشر. */
    public function test_the_migration_runs_the_command_verbosely(): void
    {
        $source = file_get_contents(base_path(self::MIGRATION));

        $this->assertStringContainsString("Artisan::call('templates:sync'", $source);
        $this->assertStringContainsString("'-v' => true", $source);
    }

    /* ─────────────── الحدّ الزمني في الأمر نفسه ─────────────── */

    /**
     * الحدّ الزمني يوقف المزامنة ويُبلّغ بما تبقّى.
     *
     * صفر ثانية يعني التوقّف قبل أول منشأة — فنُثبت أن الحدّ يُقرأ ويُحترم
     * بلا انتظار حقيقي في الاختبار.
     */
    public function test_the_time_budget_stops_the_sync_and_reports_what_is_left(): void
    {
        $this->connectedOrganization('waba-a');
        $this->connectedOrganization('waba-b');

        Http::fake();

        $this->artisan('templates:sync', ['--max-seconds' => 0])
            ->expectsOutputToContain('deferred')
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    /** وبلا حدّ زمني تُزامَن كل المنشآت. */
    public function test_without_a_budget_every_organization_is_synced(): void
    {
        $first = $this->connectedOrganization('waba-a');
        $second = $this->connectedOrganization('waba-b');

        Http::fake([
            'graph.facebook.com/*' => Http::response(['data' => []], 200),
        ]);

        $this->artisan('templates:sync')->assertSuccessful();

        Http::assertSentCount(2);
    }

    /** والحدّ الواسع لا يقطع شيئاً. */
    public function test_a_generous_budget_does_not_cut_the_run_short(): void
    {
        $this->connectedOrganization('waba-a');

        Http::fake([
            'graph.facebook.com/*' => Http::response(['data' => []], 200),
        ]);

        $this->artisan('templates:sync', ['--max-seconds' => 120])
            ->expectsOutputToContain('0 deferred')
            ->assertSuccessful();
    }

    private function connectedOrganization(string $wabaId): Organization
    {
        $owner = User::factory()->create(['role' => 'user']);

        return Organization::factory()->create([
            'created_by' => $owner->id,
            'metadata' => json_encode([
                'whatsapp' => [
                    'access_token' => 'token-' . $wabaId,
                    'waba_id' => $wabaId,
                ],
            ]),
        ]);
    }
}
