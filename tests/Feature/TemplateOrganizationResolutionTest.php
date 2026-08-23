<?php

namespace Tests\Feature;

use App\Http\Controllers\ApiController;
use App\Models\Organization;
use App\Models\Template;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\TestCase;

/**
 * أي منظّمة يُبحَث فيها عن القالب عند الإرسال؟
 *
 * عطلٌ وقع على الإنتاج: صاحب الحساب يعمل في «Mnjz Chat» على الداشبورد، يضغط
 * إرسال قالب المصادقة، فيُقال له «القالب غير موجود» — والقالب موجود ومعتمَد
 * في تلك المنظّمة بالذات.
 *
 * السبب أن ترتيب اشتقاق المنظّمة كان معكوساً: يُقرأ عمود منظّمة الجوال أوّلاً
 * حتى في طلب ويب، والعمود يحمل آخر منظّمة اختارها المستخدم في التطبيق. ومن
 * يملك أكثر من منظّمة — وهو الحال الطبيعي لصاحب الحساب — يبحث النظام عن قالبه
 * في منظّمة أخرى تماماً.
 *
 * والتمييز الحاسم: مجموعة api لا تُشغّل StartSession، فالجلسة لا توجد إلّا في
 * طلب الداشبورد. وجودها إذن هو العلامة الصحيحة على «هذا طلب ويب».
 */
class TemplateOrganizationResolutionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Organization $dashboardOrg;
    private Organization $mobileOrg;

    protected function setUp(): void
    {
        parent::setUp();

        $owner = User::factory()->create(['role' => 'user']);
        $this->dashboardOrg = Organization::factory()->create(['created_by' => $owner->id, 'name' => 'Mnjz Chat']);
        $this->mobileOrg = Organization::factory()->create(['created_by' => $owner->id, 'name' => 'Waz']);

        $this->user = User::factory()->create([
            'role' => 'user',
            'current_mobile_organization_id' => $this->mobileOrg->id,
            'current_web_organization_id' => $this->mobileOrg->id,
        ]);
    }

    private function template(Organization $organization, string $name): Template
    {
        return Template::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $organization->id,
            'meta_id' => (string) random_int(100000, 999999),
            'name' => $name,
            'language' => 'ar',
            'category' => 'AUTHENTICATION',
            'status' => 'APPROVED',
            'metadata' => json_encode(['components' => []]),
            'created_by' => $this->user->id,
        ]);
    }

    /**
     * المنظّمة كما يشتقّها الكود الحقيقي.
     *
     * ننادي المتحكّم لا نسخةً من منطقه: نسخةٌ في الاختبار تبقى ناجحة مهما
     * تغيّر الأصل — اختبارٌ لا يستطيع أن يسقط.
     */
    private function resolvedOrganizationId(Request $request): int
    {
        return ApiController::resolveOrganizationId($request);
    }

    private function webRequest(int $sessionOrganizationId): Request
    {
        $request = Request::create('/chats/send-auth-template', 'POST');
        $request->setUserResolver(fn () => $this->user);
        $request->setLaravelSession(app('session.store'));
        $request->session()->put('current_organization', $sessionOrganizationId);

        return $request;
    }

    private function apiRequest(): Request
    {
        $request = Request::create('/api/v1/send-template', 'POST');
        $request->setUserResolver(fn () => $this->user);

        return $request;
    }

    // ------------------------------------------------- جوهر العطل

    /**
     * طلب الداشبورد يتبع الجلسة لا عمود الجوال. هذا هو العطل بعينه.
     */
    public function test_a_dashboard_request_uses_the_organization_on_screen(): void
    {
        $request = $this->webRequest($this->dashboardOrg->id);

        $this->assertSame($this->dashboardOrg->id, $this->resolvedOrganizationId($request));
    }

    /** والقالب يُعثَر عليه في تلك المنظّمة. */
    public function test_the_template_is_found_in_the_dashboard_organization(): void
    {
        $template = $this->template($this->dashboardOrg, 'startchat');
        $request = $this->webRequest($this->dashboardOrg->id);

        $found = Template::where('uuid', $template->uuid)
            ->where('organization_id', $this->resolvedOrganizationId($request))
            ->first();

        $this->assertNotNull($found, 'القالب موجود ومعتمَد ومع ذلك لم يُعثر عليه.');
        $this->assertSame('startchat', $found->name);
    }

    /**
     * ولا يُعثر عليه في منظّمة الجوال — وهو ما كان يقع: قالب منظّمة أخرى.
     */
    public function test_the_same_template_does_not_exist_in_the_mobile_organization(): void
    {
        $template = $this->template($this->dashboardOrg, 'startchat');

        $this->assertFalse(
            Template::where('uuid', $template->uuid)
                ->where('organization_id', $this->mobileOrg->id)
                ->exists()
        );
    }

    // -------------------------------------------------- مسار الجوال

    /**
     * طلب الجوال بلا جلسة، فيتبع عمود منظّمة الجوال كما كان — الإصلاح لا يمسّه.
     */
    public function test_a_mobile_request_still_uses_its_own_organization(): void
    {
        $request = $this->apiRequest();

        $this->assertFalse($request->hasSession(), 'مسارات api لا يجب أن تحمل جلسة.');
        $this->assertSame($this->mobileOrg->id, $this->resolvedOrganizationId($request));
    }

    /** وحين يغيب عمود الجوال يرتدّ إلى عمود الويب. */
    public function test_it_falls_back_to_the_web_column_when_the_mobile_one_is_empty(): void
    {
        $this->user->forceFill([
            'current_mobile_organization_id' => null,
            'current_web_organization_id' => $this->dashboardOrg->id,
        ])->save();

        $this->assertSame($this->dashboardOrg->id, $this->resolvedOrganizationId($this->apiRequest()));
    }

    // --------------------------------------------------- عزل المنظّمات

    /**
     * الحارس الأهمّ: الجلسة تختار المنظّمة ولا تتجاوز الملكية. قالب منظّمة
     * أخرى يبقى غير مرئي مهما قالت الجلسة — وإلّا صار الإصلاح ثغرة.
     */
    public function test_a_template_of_another_organization_stays_invisible(): void
    {
        $foreign = $this->template($this->mobileOrg, 'chat');
        $request = $this->webRequest($this->dashboardOrg->id);

        $this->assertFalse(
            Template::where('uuid', $foreign->uuid)
                ->where('organization_id', $this->resolvedOrganizationId($request))
                ->exists(),
            'قالب منظّمة أخرى ظهر — عزل المنظّمات مكسور.'
        );
    }

    /** والتبديل بين منظّمتين في الداشبورد يتبع كلٌّ قالبها. */
    public function test_switching_organizations_switches_the_visible_template(): void
    {
        $dashboard = $this->template($this->dashboardOrg, 'startchat');
        $other = $this->template($this->mobileOrg, 'chat');

        $this->assertSame(
            $dashboard->id,
            Template::where('organization_id', $this->resolvedOrganizationId($this->webRequest($this->dashboardOrg->id)))->first()->id
        );

        $this->assertSame(
            $other->id,
            Template::where('organization_id', $this->resolvedOrganizationId($this->webRequest($this->mobileOrg->id)))->first()->id
        );
    }

    // ------------------------------------------------------ الحراسة

    /** ومسار الإرسال يستعمل الاشتقاق نفسه لا نسخةً منه. */
    public function test_the_sending_path_uses_the_shared_resolver(): void
    {
        $source = file_get_contents((new ReflectionMethod(ApiController::class, 'sendTemplateMessageByUUID'))->getFileName());

        $this->assertStringContainsString(
            'self::resolveOrganizationId($request)',
            $source,
            'مسار الإرسال يشتقّ المنظّمة بنفسه — سيتفرّع سلوكه عن المُختبَر.'
        );
    }
}
