<?php

namespace Tests\Feature;

use App\Models\Addon;
use App\Models\AutoReply;
use App\Models\Contact;
use App\Models\ContactGroup;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Team;
use App\Models\User;
use App\Services\ContactPlaceholderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * متغيّر {group}، وقائمة المتغيّرات المشتركة.
 *
 * عطلان قديمان:
 *
 * 1) {group} معروض في نافذة «اختر متغيّر» منذ البداية ولا يُستبدَل أبداً —
 *    الخدمة تجلب علاقة المجموعات ثم لا تستعملها، فيصل العميل «{group}»
 *    حرفياً في رسالته.
 *
 * 2) القائمة كانت تُبنى في ثلاثة مواضع بكود مكرّر، فاختلفت: صفحة تعديل
 *    الردّ الجاهز تُسقط نسخ {url:...} للحقول المخصّصة، فيرى المستخدم
 *    متغيّرات في الإنشاء تختفي عند التعديل.
 */
class ContactGroupPlaceholderTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;
    private User $owner;
    private Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['role' => 'user']);
        $this->organization = Organization::factory()->create([
            'created_by' => $this->owner->id,
            'name' => 'منجز',
        ]);

        $this->contact = Contact::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'first_name' => 'أحمد',
            'phone' => '+201025894984',
            'created_by' => $this->owner->id,
        ]);
    }

    private function group(string $name): ContactGroup
    {
        $group = ContactGroup::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'name' => $name,
            'created_by' => $this->owner->id,
        ]);

        DB::table('contact_contact_group')->insert([
            'contact_id' => $this->contact->id,
            'contact_group_id' => $group->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $group;
    }

    private function render(string $message): string
    {
        return ContactPlaceholderService::replace(
            $this->organization->id,
            $this->contact->uuid,
            $message
        );
    }

    // ------------------------------------------------- {group}

    /** العطل نفسه: كان يصل الرمز حرفياً إلى العميل. */
    public function test_the_group_placeholder_is_substituted(): void
    {
        $this->group('عملاء مميزون');

        $this->assertSame('أهلاً بك في عملاء مميزون', $this->render('أهلاً بك في {group}'));
    }

    public function test_more_than_one_group_is_joined(): void
    {
        $this->group('الرياض');
        $this->group('جملة');

        $this->assertSame('الرياض, جملة', $this->render('{group}'));
    }

    /** بلا مجموعة: الرمز يُستبدَل بفراغ لا يبقى ظاهراً. */
    public function test_a_contact_without_groups_gets_an_empty_value(): void
    {
        $this->assertSame('مجموعتك: ', $this->render('مجموعتك: {group}'));
        $this->assertStringNotContainsString('{group}', $this->render('{group}'));
    }

    public function test_the_url_encoded_form_works(): void
    {
        $this->group('عملاء مميزون');

        $this->assertSame(
            'https://x.test?g=' . rawurlencode('عملاء مميزون'),
            $this->render('https://x.test?g={url:group}')
        );
    }

    public function test_a_latin_group_name_works(): void
    {
        $this->group('VIP');

        $this->assertSame('VIP', $this->render('{group}'));
    }

    /** مجموعات عميل آخر لا تتسرّب. */
    public function test_another_contacts_groups_are_not_used(): void
    {
        $this->group('مجموعتي');

        $other = Contact::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'first_name' => 'سارة',
            'phone' => '+201025894985',
            'created_by' => $this->owner->id,
        ]);

        $rendered = ContactPlaceholderService::replace($this->organization->id, $other->uuid, '{group}');

        $this->assertSame('', $rendered);
    }

    /** مجموعة محذوفة لا تُذكر. */
    public function test_a_deleted_group_is_ignored(): void
    {
        $this->group('باقية');
        $this->group('محذوفة')->delete();

        $this->assertSame('باقية', $this->render('{group}'));
    }

    /** ولم ينكسر شيء ممّا كان يعمل. */
    public function test_the_other_placeholders_still_work(): void
    {
        $this->group('جملة');

        $this->assertSame(
            'أحمد — منجز — جملة — +201025894984',
            $this->render('{first_name} — {organization_name} — {group} — {phone}')
        );
    }

    public function test_an_unknown_placeholder_is_still_left_alone(): void
    {
        $this->assertSame('{grouping}', $this->render('{grouping}'));
    }

    // ------------------------------------------------- القائمة المشتركة

    private function customField(string $name): void
    {
        DB::table('contact_fields')->insert([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'name' => $name,
            'type' => 'text',
            'required' => 0,
            'position' => 1,
        ]);
    }

    /**
     * كل رمز في القائمة يُستبدَل فعلاً — لعميلٍ بيانته كاملة.
     *
     * هذا ما كشف عطل {group}: كان الوحيد المعروض ولا مقابل له في الخدمة.
     */
    public function test_every_offered_variable_is_actually_substituted(): void
    {
        $this->group('جملة');
        $this->customField('رقم الطلب');

        $this->contact->forceFill([
            'last_name' => 'صلاح',
            'email' => 'a@b.test',
            'address' => json_encode([
                'street' => 'شارع الملك',
                'city' => 'الرياض',
                'state' => 'الرياض',
                'zip' => '11564',
                'country' => 'السعودية',
            ]),
            'metadata' => json_encode(['رقم الطلب' => '551']),
        ])->save();

        foreach (ContactPlaceholderService::optionsForOrganization($this->organization->id) as $option) {
            $token = $option['value'];

            $this->assertStringNotContainsString(
                $token,
                $this->render($token),
                $token . ' معروض في القائمة ولا يُستبدَل'
            );
        }
    }

    /**
     * الحقل الفارغ يختفي، والرمز المجهول يبقى.
     *
     * تفصيله في ContactPlaceholderEmptyValueTest؛ وهنا نُثبّت أن {group}
     * يسلك سلوك بقيّة الحقول لا سلوكاً خاصّاً به.
     */
    public function test_an_empty_field_disappears_and_an_unknown_token_stays(): void
    {
        $this->assertSame('', $this->render('{email}'));
        $this->assertSame('', $this->render('{group}'));
        $this->assertSame('{grouping}', $this->render('{grouping}'));
    }

    /** وقائمة الويب والتطبيق مصدرها واحد لا ثلاثة. */
    public function test_the_list_is_built_in_one_place_only(): void
    {
        foreach ([
            'app/Http/Controllers/User/SettingController.php',
            'app/Http/Controllers/User/CannedReplyController.php',
            'app/Http/Controllers/Api/MobileSettingController.php',
        ] as $path) {
            $source = file_get_contents(base_path($path));

            $this->assertStringContainsString(
                'ContactPlaceholderService::optionsForOrganization(',
                $source,
                $path . ': يجب أن يقرأ القائمة من المصدر المشترك'
            );
            $this->assertStringNotContainsString(
                "config('formats.placeholders')",
                $source,
                $path . ': نسخة ثانية من بناء القائمة — وهي ما أنتج اختلاف الإنشاء عن التعديل'
            );
        }
    }

    /** الاختبار الحقيقي للعطل الثاني: الصفحتان تُعطيان القائمة نفسها. */
    public function test_the_create_and_edit_pages_offer_the_same_variables(): void
    {
        $this->customField('رقم الطلب');
        $this->webContext();

        $reply = AutoReply::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'name' => 'ترحيب',
            'trigger' => 'مرحبا',
            'match_criteria' => 'contains',
            'metadata' => json_encode(['type' => 'text', 'data' => ['text' => 'أهلاً']]),
            'created_by' => $this->owner->id,
            'created_at' => now(),
        ]);

        $create = $this->inertiaProps('/automation/basic/create');
        $edit = $this->inertiaProps('/automation/basic/' . $reply->uuid . '/edit');

        $this->assertSame($create['placeholders'], $edit['placeholders']);
        $this->assertContains(
            '{url:رقم_الطلب}',
            array_column($edit['placeholders'], 'value'),
            'صفحة التعديل كانت تُسقط نسخ url للحقول المخصّصة'
        );
    }

    /** والموضع الثالث: إعدادات أوقات العمل تُعطي القائمة نفسها. */
    public function test_the_working_hours_page_offers_the_same_variables(): void
    {
        $this->customField('رقم الطلب');
        $this->webContext();

        $props = $this->inertiaProps('/settings/working-hours');

        $this->assertSame(
            ContactPlaceholderService::optionsForOrganization($this->organization->id),
            $props['placeholders']
        );
        $this->assertContains('{url:رقم_الطلب}', array_column($props['placeholders'], 'value'));
        $this->assertContains('{group}', array_column($props['placeholders'], 'value'));
    }

    /** ما تحتاجه مسارات الويب قبل أن تصل المتحكّم. */
    private function webContext(): void
    {
        Team::factory()->create([
            'user_id' => $this->owner->id,
            'organization_id' => $this->organization->id,
            'role' => 'owner',
            'created_by' => $this->owner->id,
        ]);

        Addon::factory()->create(['name' => 'Google Authenticator']);
        Addon::factory()->create(['name' => 'Working Hours', 'status' => 1, 'is_active' => 1]);

        $plan = SubscriptionPlan::create([
            'name' => 'Test Plan',
            'price' => 0,
            'period' => 'monthly',
            'metadata' => json_encode([
                'message_limit' => -1,
                'addons' => ['Working Hours' => true],
            ]),
        ]);

        Subscription::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'valid_until' => now()->addYear(),
        ]);

        $this->actingAs($this->owner);
        session(['current_organization' => $this->organization->id]);
    }

    /** @return array<string, mixed> */
    private function inertiaProps(string $url): array
    {
        $response = $this->get($url);
        $response->assertOk();

        preg_match('/data-page="([^"]+)"/', $response->getContent(), $matches);
        $this->assertNotEmpty($matches, 'الصفحة لا تحمل بيانات Inertia: ' . $url);

        return json_decode(html_entity_decode($matches[1], ENT_QUOTES), true)['props'];
    }
}
