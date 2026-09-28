<?php

namespace Tests\Feature;

use App\Jobs\ProcessTemplateStatusJob;
use App\Models\Organization;
use App\Models\Template;
use App\Models\User;
use App\Services\TemplateSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * «الصورة القديمة» — العطب الذي شكا منه العملاء أسابيع.
 *
 * القصة: عندنا نسختان من القالب — واحدة عند Meta وواحدة عندنا نعرضها.
 * وعند تعديل صورة الترويسة كنّا نُحدّث نسخة Meta وننسى نسختنا، فتبقى
 * الصورة القديمة ظاهرة في كل شاشة إلى الأبد.
 *
 * والسطر الذي يحفظها كان مكتوباً ومعلَّقاً منذ أول commit في السكربت
 * الأصلي:
 *
 *     //$template->metadata = json_encode($requestData);
 *
 * ومعه عطبان آخران: webhook الاعتماد كان يحفظ الحالة وحدها، ولا مهمّة
 * مجدولة تُجدّد روابط الصور التي تنتهي صلاحيتها بعد نحو ثلاثين يوماً —
 * فوجدنا ١٣٥ من ٢٤٤ قالباً على الإنتاج روابط صورها ميّتة تردّ 403.
 */
class TemplateImageSyncTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;
    private User $owner;

    private const OLD_IMAGE = 'https://scontent.whatsapp.net/v/OLD.png?oe=6ADAED3F';
    private const NEW_IMAGE = 'https://scontent.whatsapp.net/v/NEW.png?oe=6C000000';

    protected function setUp(): void
    {
        parent::setUp();

        config(['graph.api_version' => 'v21.0']);

        $this->owner = User::factory()->create(['role' => 'user']);
        $this->organization = Organization::factory()->create([
            'created_by' => $this->owner->id,
            'metadata' => json_encode([
                'whatsapp' => [
                    'access_token' => 'org-token',
                    'waba_id' => 'waba-1',
                    'phone_number_id' => 'phone-1',
                ],
            ]),
        ]);
    }

    /* ───────────────── العطب ١: التعديل لا يحفظ الصورة ───────────────── */

    /**
     * جلب قالب واحد يستبدل الصورة القديمة بالجديدة.
     *
     * هذا هو الإصلاح الجوهري: بعد تعديل ينجح عند Meta، نسأل Meta عن
     * القالب ونحفظ ردّها.
     */
    public function test_refreshing_one_template_replaces_the_old_image(): void
    {
        $template = $this->template(self::OLD_IMAGE);

        Http::fake([
            'graph.facebook.com/*' => Http::response($this->metaTemplate(self::NEW_IMAGE), 200),
        ]);

        $this->assertTrue(app(TemplateSyncService::class)->refreshOne($template));

        $this->assertSame(self::NEW_IMAGE, $this->storedImage($template->fresh()));
        $this->assertStringNotContainsString('OLD.png', (string) $template->fresh()->metadata);
    }

    /** ونطلب من Meta القالب بمعرّفه هو، ومعه components التي تحمل الصورة. */
    public function test_the_request_asks_meta_for_the_components(): void
    {
        $template = $this->template(self::OLD_IMAGE);

        Http::fake([
            'graph.facebook.com/*' => Http::response($this->metaTemplate(self::NEW_IMAGE), 200),
        ]);

        app(TemplateSyncService::class)->refreshOne($template);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'meta-1')
            && str_contains($request->url(), 'components')
            && $request->hasHeader('Authorization', 'Bearer org-token'));
    }

    /**
     * وفشل النداء لا يمسح ما عندنا.
     *
     * الأهمّ من التحديث ألّا نُتلف: قالب بلا metadata يُسقط شاشة الحملات.
     */
    public function test_a_failed_request_leaves_the_stored_template_untouched(): void
    {
        $template = $this->template(self::OLD_IMAGE);
        $before = $template->metadata;

        Http::fake(['graph.facebook.com/*' => Http::response(null, 500)]);

        $this->assertFalse(app(TemplateSyncService::class)->refreshOne($template));
        $this->assertSame($before, $template->fresh()->metadata);
    }

    /** وردّ بلا معرّف لا يُحفظ — ردّ خطأ من Meta ليس قالباً. */
    public function test_a_response_without_an_id_is_not_stored(): void
    {
        $template = $this->template(self::OLD_IMAGE);
        $before = $template->metadata;

        Http::fake([
            'graph.facebook.com/*' => Http::response(['error' => ['message' => 'nope']], 200),
        ]);

        $this->assertFalse(app(TemplateSyncService::class)->refreshOne($template));
        $this->assertSame($before, $template->fresh()->metadata);
    }

    /** ومنشأة غير مربوطة بواتساب لا نُناديها أصلاً. */
    public function test_an_unconnected_organization_makes_no_request(): void
    {
        $this->organization->update(['metadata' => json_encode([])]);
        $template = $this->template(self::OLD_IMAGE);

        Http::fake();

        $this->assertFalse(app(TemplateSyncService::class)->refreshOne($template));
        Http::assertNothingSent();
    }

    /** وقالب بلا meta_id كذلك. */
    public function test_a_template_without_a_meta_id_makes_no_request(): void
    {
        $template = $this->template(self::OLD_IMAGE);
        $template->update(['meta_id' => '']);

        Http::fake();

        $this->assertFalse(app(TemplateSyncService::class)->refreshOne($template->fresh()));
        Http::assertNothingSent();
    }

    /**
     * والمكوّن الذي حذفه المستخدم يختفي فعلاً.
     *
     * نستبدل metadata ولا ندمجه: الدمج يُبقي ترويسةً أُزيلت فتعود في
     * المعاينة — عطب معاكس للأوّل.
     */
    public function test_a_removed_component_disappears_instead_of_lingering(): void
    {
        $template = $this->template(self::OLD_IMAGE);

        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'id' => 'meta-1',
                'name' => 'promo',
                'status' => 'APPROVED',
                'category' => 'MARKETING',
                'language' => 'ar',
                'components' => [['type' => 'BODY', 'text' => 'بلا ترويسة']],
            ], 200),
        ]);

        app(TemplateSyncService::class)->refreshOne($template);

        $this->assertNull($this->storedImage($template->fresh()));
        $this->assertStringNotContainsString('OLD.png', (string) $template->fresh()->metadata);
    }

    /** والحالة والتصنيف يتبعان Meta كذلك. */
    public function test_status_and_category_follow_meta(): void
    {
        $template = $this->template(self::OLD_IMAGE);
        $template->update(['status' => 'PENDING', 'category' => 'UTILITY']);

        Http::fake([
            'graph.facebook.com/*' => Http::response($this->metaTemplate(self::NEW_IMAGE), 200),
        ]);

        app(TemplateSyncService::class)->refreshOne($template->fresh());

        $this->assertSame('APPROVED', $template->fresh()->status);
        $this->assertSame('MARKETING', $template->fresh()->category);
    }

    /* ───────────────── العطب ٢: webhook يحفظ الحالة وحدها ───────────────── */

    /**
     * إشعار الاعتماد يجلب المحتوى لا الحالة فقط.
     *
     * القالب المعدَّل يعود PENDING ثم APPROVED، وهذا الحدث هو إشعارنا
     * الوحيد بأن شيئاً تغيّر — ومنه التعديل من لوحة Meta مباشرة.
     */
    public function test_the_approval_webhook_pulls_the_new_image(): void
    {
        $template = $this->template(self::OLD_IMAGE);
        $template->update(['status' => 'PENDING']);

        Http::fake([
            'graph.facebook.com/*' => Http::response($this->metaTemplate(self::NEW_IMAGE), 200),
        ]);

        (new ProcessTemplateStatusJob(
            ['message_template_id' => 'meta-1', 'event' => 'APPROVED'],
            $this->organization->id
        ))->handle();

        $fresh = $template->fresh();
        $this->assertSame('APPROVED', $fresh->status);
        $this->assertSame(self::NEW_IMAGE, $this->storedImage($fresh));
    }

    /** وفشل الجلب لا يمنع تحديث الحالة — وهي ما كان يعمل أصلاً. */
    public function test_the_status_is_still_saved_when_the_refresh_fails(): void
    {
        $template = $this->template(self::OLD_IMAGE);
        $template->update(['status' => 'PENDING']);

        Http::fake(['graph.facebook.com/*' => Http::response(null, 500)]);

        (new ProcessTemplateStatusJob(
            ['message_template_id' => 'meta-1', 'event' => 'APPROVED'],
            $this->organization->id
        ))->handle();

        $this->assertSame('APPROVED', $template->fresh()->status);
    }

    /* ───────────────── العطب ٣: الروابط تنتهي ولا مزامنة ───────────────── */

    /**
     * المزامنة تُجدّد روابط كل القوالب.
     *
     * رابط CDN ينتهي بعد نحو ثلاثين يوماً ثم يردّ 403 — قِسنا ١٣٥ من ٢٤٤
     * قالباً ميّتاً على الإنتاج. الجلب يُعيد رابطاً موقَّعاً جديداً.
     */
    public function test_the_scheduled_sync_refreshes_expiring_image_urls(): void
    {
        $first = $this->template(self::OLD_IMAGE, 'meta-1', 'promo_one');
        $second = $this->template(self::OLD_IMAGE, 'meta-2', 'promo_two');

        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'data' => [
                    $this->metaTemplate(self::NEW_IMAGE, 'meta-1', 'promo_one'),
                    $this->metaTemplate(self::NEW_IMAGE, 'meta-2', 'promo_two'),
                ],
            ], 200),
        ]);

        $this->artisan('templates:sync')->assertSuccessful();

        $this->assertSame(self::NEW_IMAGE, $this->storedImage($first->fresh()));
        $this->assertSame(self::NEW_IMAGE, $this->storedImage($second->fresh()));
    }

    /** وقالب موجود عند Meta ولا عندنا يُنشأ. */
    public function test_a_template_that_exists_only_at_meta_is_created(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'data' => [$this->metaTemplate(self::NEW_IMAGE, 'meta-new', 'brand_new')],
            ], 200),
        ]);

        $this->artisan('templates:sync')->assertSuccessful();

        $created = Template::where('meta_id', 'meta-new')->first();
        $this->assertNotNull($created);
        $this->assertSame('brand_new', $created->name);
        $this->assertSame((int) $this->organization->id, (int) $created->organization_id);
    }

    /** والصفحات تُتابَع حتى آخرها. */
    public function test_pagination_is_followed(): void
    {
        $first = $this->template(self::OLD_IMAGE, 'meta-1', 'one');
        $second = $this->template(self::OLD_IMAGE, 'meta-2', 'two');

        Http::fakeSequence()
            ->push([
                'data' => [$this->metaTemplate(self::NEW_IMAGE, 'meta-1', 'one')],
                'paging' => ['next' => 'https://graph.facebook.com/v21.0/waba-1/message_templates?after=abc'],
            ], 200)
            ->push(['data' => [$this->metaTemplate(self::NEW_IMAGE, 'meta-2', 'two')]], 200);

        $this->artisan('templates:sync')->assertSuccessful();

        $this->assertSame(self::NEW_IMAGE, $this->storedImage($first->fresh()));
        $this->assertSame(self::NEW_IMAGE, $this->storedImage($second->fresh()));
    }

    /** ومنشأة غير مربوطة تُتخطّى بلا نداء. */
    public function test_unconnected_organizations_are_skipped(): void
    {
        $this->organization->update(['metadata' => json_encode([])]);

        Http::fake();

        $this->artisan('templates:sync')->assertSuccessful();
        Http::assertNothingSent();
    }

    /**
     * ومنشأة فاشلة لا توقف البقيّة.
     *
     * توكن منتهٍ عند عميل لا يجوز أن يمنع تجديد روابط بقيّة العملاء.
     */
    public function test_one_failing_organization_does_not_stop_the_others(): void
    {
        $otherOwner = User::factory()->create(['role' => 'user']);
        $other = Organization::factory()->create([
            'created_by' => $otherOwner->id,
            'metadata' => json_encode([
                'whatsapp' => ['access_token' => 'bad', 'waba_id' => 'waba-2'],
            ]),
        ]);

        $mine = $this->template(self::OLD_IMAGE);
        $theirs = $this->template(self::OLD_IMAGE, 'meta-9', 'theirs', (int) $other->id);

        Http::fake([
            '*waba-2*' => Http::response(null, 401),
            '*waba-1*' => Http::response([
                'data' => [$this->metaTemplate(self::NEW_IMAGE)],
            ], 200),
        ]);

        $this->artisan('templates:sync')->assertSuccessful();

        $this->assertSame(self::NEW_IMAGE, $this->storedImage($mine->fresh()));
        $this->assertSame(self::OLD_IMAGE, $this->storedImage($theirs->fresh()));
    }

    /** وخيار المنشأة يقصر المزامنة عليها. */
    public function test_the_organization_option_narrows_the_sync(): void
    {
        Http::fake();

        $this->artisan('templates:sync', ['--organization' => 999999])->assertSuccessful();

        Http::assertNothingSent();
    }

    /** والقالب المحذوف عندنا والموجود عند Meta يعود. */
    public function test_a_template_deleted_locally_but_present_at_meta_is_restored(): void
    {
        $template = $this->template(self::OLD_IMAGE);
        $template->delete();

        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'data' => [$this->metaTemplate(self::NEW_IMAGE)],
            ], 200),
        ]);

        $this->artisan('templates:sync')->assertSuccessful();

        $restored = Template::withTrashed()->find($template->id);
        $this->assertNull($restored->deleted_at);
        $this->assertSame(self::NEW_IMAGE, $this->storedImage($restored));
    }

    /**
     * الصورة باقية والرابط وحده يتغيّر.
     *
     * الفرق الذي التبس على الجميع: صورة القالب عند Meta **لا تنتهي** —
     * الذي ينتهي هو إذن الوصول الموقَّع الذي نخزّنه.
     *
     * أثبتناه على الإنتاج (قالب 639): رابطنا ميّت يردّ 403، وسألنا Meta
     * فأعطت رابطاً يردّ 200 — ومعرّف الملف في الاثنين واحد. هنا نُثبّت
     * السلوك: بعد المزامنة يتغيّر التوقيع ويبقى معرّف الملف كما هو.
     */
    public function test_the_signature_changes_while_the_image_file_stays_the_same(): void
    {
        $fileId = '534427910_957055977019128_3826413779269922618';
        $expired = "https://scontent.whatsapp.net/v/t61.29466-34/{$fileId}.jpg?oe=6ADAED3F&_nc_sid=OLD";
        $fresh = "https://scontent.whatsapp.net/v/t61.29466-34/{$fileId}.jpg?oe=6C000000&_nc_sid=NEW";

        $template = $this->template($expired);

        Http::fake([
            'graph.facebook.com/*' => Http::response($this->metaTemplate($fresh), 200),
        ]);

        app(TemplateSyncService::class)->refreshOne($template);

        $stored = $this->storedImage($template->fresh());

        // الرابط تغيّر…
        $this->assertNotSame($expired, $stored);
        $this->assertStringContainsString('oe=6C000000', $stored);

        // …ومعرّف الملف هو هو: نفس الصورة بإذن جديد، لا صورة أخرى.
        $this->assertStringContainsString($fileId, $stored);
    }

    /* ───────────── قالب واحد فاسد لا يُسقط المزامنة ───────────── */

    /**
     * العطب الذي وقع عند أول تشغيل حقيقي.
     *
     * منشأة فيها قالب اسمه رابط خرائط جوجل مُجرَّداً (نحو ٤٠٠ حرف) تجاوز
     * عمود `name` (كان varchar(128)) فرمى:
     *
     *     SQLSTATE[22001] 1406 Data too long for column 'name'
     *
     * والاستثناء خرج من الحلقة ومن الأمر ومن الترحيل — **فتوقّفت مزامنة كل
     * المنشآت الباقية بسبب صفّ واحد**.
     */
    public function test_one_unstorable_template_does_not_stop_the_others(): void
    {
        // اسم أطول ممّا يتّسع له العمود مهما وُسِّع — يضمن فشل الحفظ.
        $tooLong = str_repeat('x', 2000);

        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'data' => [
                    $this->metaTemplate(self::NEW_IMAGE, 'meta-bad', $tooLong),
                    $this->metaTemplate(self::NEW_IMAGE, 'meta-good', 'سليم'),
                ],
            ], 200),
        ]);

        $result = app(TemplateSyncService::class)->refreshOrganization((int) $this->organization->id);

        // الفاسد تُخطّي، والسليم حُفظ.
        $this->assertSame(1, $result['failed']);
        $this->assertSame(1, $result['created']);
        $this->assertNull($result['error']);

        $this->assertNotNull(Template::where('meta_id', 'meta-good')->first());
        $this->assertNull(Template::where('meta_id', 'meta-bad')->first());
    }

    /** والأمر كلّه لا يسقط، ويُبلّغ بما تُخطّي. */
    public function test_the_command_survives_an_unstorable_template_and_reports_it(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'data' => [$this->metaTemplate(self::NEW_IMAGE, 'meta-bad', str_repeat('x', 2000))],
            ], 200),
        ]);

        $this->artisan('templates:sync')
            ->expectsOutputToContain('قالباً لتعذّر حفظه')
            ->assertSuccessful();
    }

    /**
     * وتحديث قالب واحد لا يرمي — توثيقه يَعِد بذلك.
     *
     * تُستدعى بعد تعديل نجح فعلاً عند Meta، فرميها يُوهم المستخدم أن
     * التعديل لم يُحفظ وقد حُفظ.
     */
    public function test_refresh_one_returns_false_instead_of_throwing(): void
    {
        $template = $this->template(self::OLD_IMAGE);
        $before = $template->metadata;

        // نُفشل الحفظ بعمود تكتبه store() فعلاً. الاسم لا يُكتب إلّا عند
        // الإنشاء، فالتصنيف (varchar 128) هو ما يصلح للاختبار هنا.
        $payload = $this->metaTemplate(self::NEW_IMAGE);
        $payload['category'] = str_repeat('x', 2000);

        Http::fake(['graph.facebook.com/*' => Http::response($payload, 200)]);

        // لا استثناء — قيمة false.
        $this->assertFalse(app(TemplateSyncService::class)->refreshOne($template));
        $this->assertSame($before, $template->fresh()->metadata);
    }

    /** وعمود الاسم يتّسع لما تسمح به Meta (٥١٢ حرفاً). */
    public function test_the_name_column_matches_metas_limit(): void
    {
        $length = \DB::selectOne(
            "SELECT CHARACTER_MAXIMUM_LENGTH AS len FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = 'templates' AND column_name = 'name'"
        )->len;

        $this->assertGreaterThanOrEqual(
            512,
            (int) $length,
            'عمود templates.name أضيق من حدّ Meta — أسماء طويلة سترمي 1406.'
        );
    }

    /** واسم بطول ٥٠٠ حرف يُحفظ فعلاً — وكان يفشل قبل التوسيع. */
    public function test_a_long_but_valid_name_is_stored(): void
    {
        $longName = str_repeat('a', 500);

        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'data' => [$this->metaTemplate(self::NEW_IMAGE, 'meta-long', $longName)],
            ], 200),
        ]);

        $result = app(TemplateSyncService::class)->refreshOrganization((int) $this->organization->id);

        $this->assertSame(0, $result['failed']);
        $this->assertSame($longName, Template::where('meta_id', 'meta-long')->first()->name);
    }

    /* ───────── زرّ المزامنة في الصفحة (مسار الإنتاج الذي انهار) ───────── */

    /**
     * الخطأ الذي وصل Sentry من الإنتاج.
     *
     *     GET /templates/sync
     *     QueryException 1406 Data too long for column 'name'
     *     WhatsappService::syncTemplates line 1706
     *
     * كانت نسختان من المزامنة: أصلحنا الجديدة وبقيت القديمة تنهار —
     * و`catch` القديمة تلتقط أخطاء الشبكة وحدها، فوصل خطأ قاعدة البيانات
     * صفحةً بيضاء للمستخدم.
     *
     * الآن يمرّ زرّ المزامنة على الخدمة المحصورة.
     */
    public function test_the_sync_button_survives_an_unstorable_template(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'data' => [
                    $this->metaTemplate(self::NEW_IMAGE, 'meta-bad', str_repeat('x', 2000)),
                    $this->metaTemplate(self::NEW_IMAGE, 'meta-good', 'سليم'),
                ],
            ], 200),
        ]);

        $service = new \App\Services\TemplateService((int) $this->organization->id);
        $response = $service->getTemplates(
            \Illuminate\Http\Request::create('/templates/sync', 'GET'),
            'sync'
        );

        // لا استثناء، ولا صفحة بيضاء.
        $this->assertSame(200, $response->getStatusCode());

        $payload = json_decode($response->getContent(), true);
        $this->assertTrue($payload['success']);
        $this->assertSame(1, $payload['created']);
        $this->assertSame(1, $payload['skipped']);

        $this->assertNotNull(Template::where('meta_id', 'meta-good')->first());
    }

    /** ولا يمرّ على المسار القديم بعد اليوم. */
    public function test_the_sync_button_no_longer_calls_the_legacy_path(): void
    {
        $source = file_get_contents(app_path('Services/TemplateService.php'));

        $this->assertStringNotContainsString(
            '$this->whatsappService->syncTemplates()',
            $source,
            'زرّ المزامنة عاد إلى WhatsappService::syncTemplates — سينهار على قالب لا يُحفظ.'
        );
        $this->assertStringContainsString('TemplateSyncService::class', $source);
    }

    /**
     * والمسار القديم نفسه محصور كذلك — ما زال يُنادى من الإعدادات.
     */
    public function test_the_legacy_sync_guards_each_template(): void
    {
        $source = file_get_contents(app_path('Services/WhatsappService.php'));

        $syncBody = substr($source, strpos($source, 'function syncTemplates()'));

        $this->assertStringContainsString(
            'syncTemplates skipped one template',
            $syncBody,
            'المزامنة القديمة بلا حصر لكل قالب — قالب واحد سيُسقط الباقي.'
        );
    }

    /* ───────────────── حارس انحدار ───────────────── */

    /**
     * السطر المعلَّق لا يعود.
     *
     * هذا هو العطب الأصلي: `//$template->metadata = json_encode($requestData);`
     * فلو أعاده أحد ظنّاً أنه الإصلاح، يفشل هذا الاختبار ويقول لماذا.
     */
    public function test_the_update_path_still_refreshes_from_meta(): void
    {
        $source = file_get_contents(app_path('Services/WhatsappService.php'));

        // السطر المعلَّق في موضعه الأصلي: بداية سطر ثمّ // مباشرةً.
        // الشرح داخل التعليقات يذكره بين علامتي `…` فلا يُطابق هذا النمط.
        $this->assertSame(
            0,
            preg_match('/^\s*\/\/\$template->metadata\s*=/m', $source),
            'عاد السطر المعلَّق — تعديل صورة القالب لن يظهر من جديد.'
        );

        $this->assertStringContainsString(
            'TemplateSyncService::class)->refreshOne($template)',
            $source,
            'مسار تعديل القالب لم يعد يجلب النسخة الجديدة من Meta.'
        );
    }

    /* ───────────────────────── مساعدات ───────────────────────── */

    private function template(
        string $imageUrl,
        string $metaId = 'meta-1',
        string $name = 'promo',
        ?int $organizationId = null
    ): Template {
        $template = new Template();
        $template->organization_id = $organizationId ?? $this->organization->id;
        $template->meta_id = $metaId;
        $template->name = $name;
        $template->category = 'MARKETING';
        $template->language = 'ar';
        $template->status = 'APPROVED';
        $template->metadata = json_encode($this->metaTemplate($imageUrl, $metaId, $name));
        $template->created_by = $this->owner->id;
        $template->created_at = now();
        $template->updated_at = now();
        $template->save();

        return $template;
    }

    /** قالب بشكل ردّ Meta. */
    private function metaTemplate(string $imageUrl, string $metaId = 'meta-1', string $name = 'promo'): array
    {
        return [
            'id' => $metaId,
            'name' => $name,
            'status' => 'APPROVED',
            'category' => 'MARKETING',
            'language' => 'ar',
            'components' => [
                [
                    'type' => 'HEADER',
                    'format' => 'IMAGE',
                    'example' => ['header_handle' => [$imageUrl]],
                ],
                ['type' => 'BODY', 'text' => 'مرحبا'],
            ],
        ];
    }

    /** رابط صورة الترويسة المخزَّن، أو null. */
    private function storedImage(Template $template): ?string
    {
        $metadata = json_decode((string) $template->metadata, true);

        foreach ($metadata['components'] ?? [] as $component) {
            if (($component['type'] ?? '') === 'HEADER') {
                return $component['example']['header_handle'][0] ?? null;
            }
        }

        return null;
    }
}
