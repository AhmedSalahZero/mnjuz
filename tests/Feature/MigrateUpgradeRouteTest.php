<?php

namespace Tests\Feature;

use App\Http\Controllers\FrontendController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * المسار `/migrate-upgrade`.
 *
 * من سجلّ الإنتاج: «Method App\Http\Controllers\FrontendController::migrate
 * does not exist» على `GET /migrate-upgrade`.
 *
 * المسار جاء مع النسخة الأصلية من السكربت (commit الاستيراد الأول) ودالّته
 * لم تُكتب قطّ في هذا المستودع، ولا شيء في النظام يشير إليه — فكل طلب عليه
 * خطأ 500.
 *
 * والأهمّ أنه كان خارج أي مجموعة مصادقة، ومجموعة web ليس فيها auth: مفتوحٌ
 * لكل زائر. واسمه يَعِد بتشغيل الترحيلات، فلو كُتبت له دالّة يوماً لصار ذلك
 * متاحاً لمن عرف العنوان.
 */
class MigrateUpgradeRouteTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_route_is_no_longer_registered(): void
    {
        $uris = collect(Route::getRoutes())->map(fn ($route) => $route->uri())->all();

        $this->assertNotContains('migrate-upgrade', $uris);
    }

    /** والزائر يجد 404 لا خطأ خادم. */
    public function test_visiting_it_is_a_not_found(): void
    {
        $this->get('/migrate-upgrade')->assertNotFound();
    }

    /** ولا مسار آخر يشير إلى الدالّة الغائبة. */
    public function test_no_route_points_at_the_missing_method(): void
    {
        $this->assertFalse(
            method_exists(FrontendController::class, 'migrate'),
            'إن أُضيفت الدالّة فالمسار العام يجب أن يُراجَع قبل إعادته'
        );

        foreach (Route::getRoutes() as $route) {
            $action = $route->getAction('uses');

            if (is_string($action)) {
                $this->assertNotSame(
                    FrontendController::class . '@migrate',
                    $action,
                    $route->uri() . ' ما زال يشير إلى دالّة غير موجودة'
                );
            }
        }
    }

    /**
     * ولم يُحذف معه ما يُستعمل فعلاً.
     *
     * `/pages/{slug}` هي الآلية العاملة لصفحات المحتوى، وحذف مسارٍ ميّت
     * بجوارها لا يجوز أن يمسّها.
     */
    public function test_the_public_pages_route_still_exists(): void
    {
        $uris = collect(Route::getRoutes())->map(fn ($route) => $route->uri())->all();

        $this->assertContains('pages/{slug}', $uris);
        $this->assertContains('/', $uris);
    }
}
