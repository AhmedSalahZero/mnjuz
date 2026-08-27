<?php

namespace Tests\Feature;

use App\Http\Requests\StoreCampaign;
use App\Models\Addon;
use App\Models\CampaignMediaHistory;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Team;
use App\Models\User;
use App\Services\CampaignMediaHistoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * اختيار ملف استُخدم سابقاً في حملة.
 *
 * العطل كما وصل من الإنتاج: عميلة تختار صورة من «الملفات المستخدمة سابقاً»،
 * فلا تظهر في المعاينة، ويُردّ عليها «This field is required.» إنجليزيةً
 * وسط واجهة عربية، وحذف الملف يُظهر «حدث خطأ ما» بلا سبب. راجعنا بياناتها
 * فوجدنا حملتيها الاثنتين أُنشئتا بالرفع — ولا مرّة عبر هذا المسار.
 *
 * ثلاث علل متراكبة: معاينة لا ترسم شيئاً أبداً (تُختبر في
 * template-media-preview.mjs)، ورسالة غير مترجَمة ولا تدلّ، ومطابقة الملف
 * بالمسار النصّي بدل معرّفه.
 */
class CampaignHistoryMediaTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['role' => 'user']);
        $this->organization = Organization::factory()->create(['created_by' => $this->user->id]);
        Team::factory()->create([
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'role' => 'owner',
            'created_by' => $this->user->id,
        ]);

        // HandleInertiaRequests يقرأ is_active من هذه الإضافة بلا احتياط من
        // null، فأي طلب HTTP يسقط على قاعدة اختبارات نظيفة قبل بلوغ المتحكّم.
        Addon::factory()->create(['name' => 'Google Authenticator']);

        // مسارات الحملات خلف check.subscription.
        $plan = SubscriptionPlan::create([
            'name' => 'Test Plan',
            'price' => 0,
            'period' => 'monthly',
            'metadata' => json_encode(['campaign_limit' => -1]),
        ]);
        Subscription::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'valid_until' => now()->addYear(),
        ]);

        session(['current_organization' => $this->organization->id]);
    }

    private function historyItem(array $attributes = []): CampaignMediaHistory
    {
        // fresh(): الصفّ المُنشأ للتوّ يحمل كائن Uuid، والقادم من المتصفح نصّ.
        return app(CampaignMediaHistoryService::class)->record(
            $this->organization->id,
            $this->user->id,
            $attributes['media_type'] ?? 'IMAGE',
            $attributes['name'] ?? 'IMG_7986.png',
            $attributes['path'] ?? 'https://mnjzchat.s3.amazonaws.com/uploads/media/sent/260/abc.png',
            'amazon',
            'image/png',
            '4085688',
        )->fresh();
    }

    /** التحقّق كما يبنيه الطلب فعلاً، على حمولة كالتي يرسلها النموذج. */
    private function validate(array $payload): \Illuminate\Contracts\Validation\Validator
    {
        $request = StoreCampaign::create('/campaigns', 'POST', $payload);
        $request->setContainer(app())->setRedirector(app('redirect'));

        return Validator::make($payload, $request->rules(), $request->messages());
    }

    private function campaignPayload(array $headerParameter): array
    {
        return [
            'name' => 'حملة',
            'template' => (string) Str::uuid(),
            'contacts' => (string) Str::uuid(),
            'time' => '2026-09-01 10:00',
            'header' => [
                'format' => 'IMAGE',
                'parameters' => [$headerParameter],
            ],
        ];
    }

    // ------------------------------------------------- البحث بالمعرّف

    /**
     * المسار النصّي كان المفتاح الوحيد، وأيّ اختلاف حرف فيه — ترميز أو
     * مسافة أو تغيّر شكل رابط التخزين — يُسقط اختياراً صحيحاً برسالة
     * «الملف لم يعد متاحاً».
     */
    public function test_a_previously_used_file_is_found_by_its_uuid(): void
    {
        $item = $this->historyItem();

        $found = app(CampaignMediaHistoryService::class)
            ->findByReferenceForOrganization($this->organization->id, $item->uuid);

        $this->assertNotNull($found);
        $this->assertSame($item->id, $found->id);
    }

    /** الصفحات المفتوحة قبل النشر ما زالت ترسل المسار. */
    public function test_the_legacy_path_reference_still_resolves(): void
    {
        $item = $this->historyItem();

        $found = app(CampaignMediaHistoryService::class)
            ->findByReferenceForOrganization($this->organization->id, $item->path);

        $this->assertNotNull($found);
        $this->assertSame($item->id, $found->id);
    }

    public function test_a_file_of_another_organization_is_never_returned(): void
    {
        $item = $this->historyItem();
        $otherOrganization = Organization::factory()->create(['created_by' => $this->user->id]);

        $this->assertNull(
            app(CampaignMediaHistoryService::class)
                ->findByReferenceForOrganization($otherOrganization->id, $item->uuid)
        );
    }

    public function test_a_deleted_file_is_not_resolved(): void
    {
        $item = $this->historyItem();
        app(CampaignMediaHistoryService::class)->deleteForOrganization($this->organization->id, $item->uuid);

        $this->assertNull(
            app(CampaignMediaHistoryService::class)
                ->findByReferenceForOrganization($this->organization->id, $item->uuid)
        );
    }

    public function test_an_empty_reference_resolves_to_nothing(): void
    {
        $this->assertNull(
            app(CampaignMediaHistoryService::class)
                ->findByReferenceForOrganization($this->organization->id, '   ')
        );
    }

    // ------------------------------------------------- قواعد التحقّق

    /**
     * القاعدة القديمة كانت `url`، والواجهة صارت ترسل uuid — فبقاؤها كان
     * سيرفض كل اختيار من السجل.
     */
    public function test_a_uuid_reference_passes_validation(): void
    {
        $item = $this->historyItem();

        $validator = $this->validate($this->campaignPayload([
            'type' => 'IMAGE',
            'selection' => 'history',
            'value' => $item->uuid,
        ]));

        $this->assertFalse($validator->errors()->has('header.parameters.0.value'), $validator->errors());
    }

    public function test_a_legacy_url_reference_still_passes_validation(): void
    {
        $item = $this->historyItem();

        $validator = $this->validate($this->campaignPayload([
            'type' => 'IMAGE',
            'selection' => 'history',
            'value' => $item->path,
        ]));

        $this->assertFalse($validator->errors()->has('header.parameters.0.value'), $validator->errors());
    }

    public function test_an_empty_header_is_still_rejected(): void
    {
        $validator = $this->validate($this->campaignPayload([
            'type' => 'IMAGE',
            'selection' => 'default',
            'value' => null,
        ]));

        $this->assertTrue($validator->errors()->has('header.parameters.0.value'));
    }

    // ------------------------------------------------- الرسالة

    /**
     * العميلة رأت «This field is required.» إنجليزيةً وسط واجهة عربية،
     * فقرأتها «الصورة غير موجودة». والرسالة الآن تُسمّي المطلوب وتذكر
     * الطريقين معاً: الرفع أو ملف سابق.
     */
    public function test_the_empty_header_message_is_arabic_and_actionable(): void
    {
        app()->setLocale('ar');

        $validator = $this->validate($this->campaignPayload([
            'type' => 'IMAGE',
            'selection' => 'default',
            'value' => null,
        ]));

        $message = $validator->errors()->first('header.parameters.0.value');

        $this->assertStringContainsString('صورة الترويسة', $message);
        $this->assertStringContainsString('سابقاً', $message, 'الرسالة يجب أن تذكر خيار الملف السابق');
        $this->assertStringNotContainsString('This field is required', $message);
    }

    public function test_the_message_names_the_right_media_kind(): void
    {
        app()->setLocale('ar');

        $payload = $this->campaignPayload(['type' => 'VIDEO', 'selection' => 'default', 'value' => null]);
        $payload['header']['format'] = 'VIDEO';

        $message = $this->validate($payload)->errors()->first('header.parameters.0.value');

        $this->assertStringContainsString('فيديو الترويسة', $message);
    }

    /** الترويسة النصّية ليست وسائط — تبقى لها الرسالة العامّة. */
    public function test_a_text_header_keeps_the_generic_message(): void
    {
        app()->setLocale('ar');

        $payload = $this->campaignPayload(['type' => 'text', 'selection' => 'static', 'value' => null]);
        $payload['header']['format'] = 'TEXT';

        $message = $this->validate($payload)->errors()->first('header.parameters.0.value');

        $this->assertSame('هذا الحقل مطلوب.', $message);
    }

    public function test_every_message_key_is_translated_in_arabic(): void
    {
        $translations = json_decode(file_get_contents(base_path('lang/ar.json')), true);

        foreach ([
            'This field is required.',
            'Choose a header image: upload a new one or pick a previously used file.',
            'Choose a header video: upload a new one or pick a previously used file.',
            'Choose a header document: upload a new one or pick a previously used file.',
            'This file is no longer in the list. Refresh the page.',
            'Selected',
            'Click to use this file',
        ] as $key) {
            $this->assertArrayHasKey($key, $translations, "المفتاح «{$key}» بلا ترجمة عربية");
            $this->assertNotSame($key, $translations[$key], "المفتاح «{$key}» غير مترجَم فعلياً");
        }
    }

    /**
     * حارس: إنشاء الحملة يجب أن يمرّ بالبحث المتسامح. العودة إلى
     * findForOrganization تُعيد المطابقة بالمسار وحده، ولا يكسر ذلك أي
     * اختبار سلوكي — يكسر الاختيار من السجل وحده.
     */
    public function test_campaign_creation_resolves_the_reference_not_the_raw_path(): void
    {
        $source = file_get_contents(base_path('app/Services/CampaignService.php'));

        $this->assertStringContainsString('findByReferenceForOrganization', $source);
        $this->assertStringNotContainsString(
            '$historyService->findForOrganization(',
            $source,
            'ما زال إنشاء الحملة يطابق بالمسار النصّي'
        );
    }

    // ------------------------------------------------- الحذف

    public function test_deleting_a_missing_file_answers_with_a_clear_message(): void
    {
        $this->actingAs($this->user)
            ->withSession(['current_organization' => $this->organization->id])
            ->deleteJson('/campaigns/media-history/' . Str::uuid())
            ->assertStatus(404)
            ->assertJsonPath('message', 'This file is no longer in the list. Refresh the page.');
    }

    public function test_deleting_an_existing_file_succeeds_once_and_then_reports_it_is_gone(): void
    {
        $item = $this->historyItem();

        $this->actingAs($this->user)
            ->withSession(['current_organization' => $this->organization->id])
            ->deleteJson('/campaigns/media-history/' . $item->uuid)
            ->assertOk()
            ->assertJsonPath('success', true);

        // الضغطة المكرّرة — وهي ما كان يُظهر «حدث خطأ ما» بلا تفسير.
        $this->actingAs($this->user)
            ->withSession(['current_organization' => $this->organization->id])
            ->deleteJson('/campaigns/media-history/' . $item->uuid)
            ->assertStatus(404);
    }

    public function test_a_file_of_another_organization_cannot_be_deleted(): void
    {
        $otherOrganization = Organization::factory()->create(['created_by' => $this->user->id]);

        $foreign = app(CampaignMediaHistoryService::class)->record(
            $otherOrganization->id,
            $this->user->id,
            'IMAGE',
            'foreign.png',
            'https://mnjzchat.s3.amazonaws.com/uploads/media/sent/999/foreign.png',
            'amazon',
            'image/png',
            '1024',
        )->fresh();

        $this->actingAs($this->user)
            ->withSession(['current_organization' => $this->organization->id])
            ->deleteJson('/campaigns/media-history/' . $foreign->uuid)
            ->assertStatus(404);

        $this->assertNotNull(CampaignMediaHistory::find($foreign->id), 'حُذف ملف منشأة أخرى');
    }
}
