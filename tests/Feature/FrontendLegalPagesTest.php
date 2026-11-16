<?php

namespace Tests\Feature;

use App\Models\Addon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * سجلّ الإنتاج (2026-11-08):
 *   Method App\Http\Controllers\FrontendController::privacy does not exist.
 *
 * routes/web.php يُسجّل /privacy و/terms-of-service منذ أول إيداع بلا دالّة
 * خلفهما، فكان كل طلب (ومنه زاحف Meta الذي يفحص رابط سياسة الخصوصية) يردّ
 * 500. المحتوى صفحتان في جدول pages تُعرضان على /pages/{slug}.
 */
class FrontendLegalPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // HandleInertiaRequests يقرأ هذه الإضافة بلا احتياط من null.
        Addon::factory()->create(['name' => 'Google Authenticator']);
    }

    private function page(string $name, string $content): int
    {
        return DB::table('pages')->insertGetId([
            'name' => $name,
            'content' => $content,
        ]);
    }

    public function test_privacy_shows_the_privacy_policy_page(): void
    {
        $id = $this->page('Privacy Policy', '<p>privacy body</p>');
        $this->page('Terms of Service', '<p>terms body</p>');

        $this->get('/privacy')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Frontend/Dynamic')
                ->where('page.data.id', $id)
                ->where('page.data.content', '<p>privacy body</p>')
            );
    }

    public function test_terms_of_service_shows_the_terms_page(): void
    {
        $this->page('Privacy Policy', '<p>privacy body</p>');
        $id = $this->page('Terms of Service', '<p>terms body</p>');

        $this->get('/terms-of-service')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Frontend/Dynamic')
                ->where('page.data.id', $id)
                ->where('page.data.content', '<p>terms body</p>')
            );
    }

    /** صفحة حُذفت أو غُيّر اسمها: 404 لا 500. */
    public function test_a_missing_page_is_not_found_not_a_server_error(): void
    {
        $this->get('/privacy')->assertNotFound();
        $this->get('/terms-of-service')->assertNotFound();
    }

    /** المسار العام للصفحات كما كان. */
    public function test_the_pages_route_is_unchanged(): void
    {
        $id = $this->page('Privacy Policy', '<p>privacy body</p>');

        $this->get('/pages/privacy-policy')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Frontend/Dynamic')
                ->where('page.data.id', $id)
            );
    }

    /** /process-campaign كان يشير إلى دالّة غير موجودة، فحُذف. */
    public function test_the_dead_process_campaign_route_is_gone(): void
    {
        $this->get('/process-campaign')->assertNotFound();
    }
}
