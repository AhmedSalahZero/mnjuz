<?php

namespace Tests\Feature;

use App\Exports\ContactsExport;
use App\Models\Addon;
use App\Models\Contact;
use App\Models\ContactCategory;
use App\Models\Organization;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Team;
use App\Models\User;
use App\Imports\ContactsImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * التصنيفات في ملف الاستيراد والتصدير.
 *
 * كان الاستيراد يقبل الأنبوب «|» وحده، وهو ليس ما يكتبه من يُعدّ الملف بالعربية.
 * وكان عمود التصنيف موجوداً في Excel ومفقوداً من CSV — فمن يُصدّر CSV ثم يُعيد
 * استيراده يفقد تصنيفات جهات اتصاله كلّها بلا أي إنذار.
 */
class ContactCategoryImportExportTest extends TestCase
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

        Addon::factory()->create(['name' => 'Google Authenticator']);
        Setting::create(['key' => 'storage_system', 'value' => 'local']);

        $plan = SubscriptionPlan::create([
            'name' => 'Test Plan',
            'price' => 0,
            'period' => 'monthly',
            'metadata' => json_encode(['contacts_limit' => -1, 'contact_categories_enabled' => 1]),
        ]);
        Subscription::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'valid_until' => now()->addYear(),
        ]);

        session(['current_organization' => $this->organization->id]);
        $this->actingAs($this->user);
    }

    private function contact(string $name): Contact
    {
        return Contact::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'first_name' => $name,
            'phone' => '+9665' . str_pad((string) random_int(1, 99999999), 8, '0', STR_PAD_LEFT),
            'created_by' => $this->user->id,
        ]);
    }

    private function category(string $name): ContactCategory
    {
        return ContactCategory::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'name' => $name,
            'background_color' => '#22c55e',
            'text_color' => '#ffffff',
        ]);
    }

    /** أسماء التصنيفات التي يحلّها الاستيراد من قيمة عمود واحدة. */
    private function resolve(string $value): array
    {
        $import = new ContactsImport($this->organization->id, $this->user->id);
        $method = new \ReflectionMethod(ContactsImport::class, 'resolveCategoryIdsFromImport');
        $method->setAccessible(true);
        $ids = $method->invoke($import, $value, $this->organization->id);

        return ContactCategory::whereIn('id', $ids)->orderBy('id')->pluck('name')->all();
    }

    // ------------------------------------------------ الفواصل

    /**
     * @dataProvider separatorCases
     */
    public function test_category_names_split_on_every_accepted_separator(string $value, array $expected): void
    {
        $this->assertSame($expected, $this->resolve($value));
    }

    public static function separatorCases(): array
    {
        return [
            'فاصلة عربية' => ['عميل محتمل، عميل خاص', ['عميل محتمل', 'عميل خاص']],
            'فاصلة لاتينية' => ['عميل محتمل, عميل خاص', ['عميل محتمل', 'عميل خاص']],
            'أنبوب (الصيغة القديمة)' => ['عميل محتمل|عميل خاص', ['عميل محتمل', 'عميل خاص']],
            'خليط' => ['عميل محتمل، عميل خاص|عميل ملغي الاشتراك', ['عميل محتمل', 'عميل خاص', 'عميل ملغي الاشتراك']],
            'بلا مسافات' => ['عميل محتمل،عميل خاص', ['عميل محتمل', 'عميل خاص']],
            'مسافات زائدة' => ['  عميل محتمل  ،   عميل خاص  ', ['عميل محتمل', 'عميل خاص']],
            'تصنيف واحد' => ['عميل خاص', ['عميل خاص']],
        ];
    }

    /** العمود اختياري: الفراغ لا يُنشئ تصنيفاً ولا يُسقط الاستيراد. */
    public function test_an_empty_category_column_creates_nothing(): void
    {
        foreach (['', '   ', '،', '، ،', '|'] as $value) {
            $this->assertSame([], $this->resolve($value), "القيمة «{$value}» يجب ألّا تُنتج تصنيفاً");
        }

        $this->assertSame(0, ContactCategory::count());
    }

    public function test_existing_categories_are_reused_not_duplicated(): void
    {
        $existing = $this->category('عميل خاص');

        $this->resolve('عميل خاص، عميل محتمل');

        $this->assertSame(1, ContactCategory::where('name', 'عميل خاص')->count());
        $this->assertSame(2, ContactCategory::count(), 'يُنشأ الناقص وحده');
        $this->assertNotNull(ContactCategory::find($existing->id));
    }

    public function test_repeated_names_in_one_cell_collapse(): void
    {
        $this->assertSame(['عميل خاص'], $this->resolve('عميل خاص، عميل خاص'));
    }

    /** تصنيف بنفس الاسم في منشأة أخرى لا يُعاد استخدامه. */
    public function test_categories_are_created_per_organization(): void
    {
        $other = Organization::factory()->create(['created_by' => $this->user->id]);
        ContactCategory::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $other->id,
            'name' => 'عميل خاص',
            'background_color' => '#22c55e',
            'text_color' => '#ffffff',
        ]);

        $this->resolve('عميل خاص');

        $this->assertSame(1, ContactCategory::where('organization_id', $this->organization->id)->count());
        $this->assertSame(2, ContactCategory::where('name', 'عميل خاص')->count());
    }

    // ------------------------------------------------ التصدير

    /**
     * العطل الأصلي: عمود التصنيف مفقود من CSV، فالتصدير ثم الاستيراد يفقد
     * التصنيفات كلّها.
     */
    public function test_the_csv_export_carries_a_category_column(): void
    {
        $contact = $this->contact('Ahmed');
        $contact->contactCategories()->attach([
            $this->category('عميل محتمل')->id,
            $this->category('عميل خاص')->id,
        ]);

        $response = $this->get('/contacts/export?format=csv');
        $response->assertOk();
        $csv = file_get_contents($response->baseResponse->getFile()->getPathname());

        $this->assertStringContainsString('Category', $csv, 'الترويسة تفتقد عمود التصنيف');
        $this->assertStringContainsString('عميل محتمل، عميل خاص', $csv);
    }

    public function test_the_excel_export_uses_the_readable_separator(): void
    {
        $contact = $this->contact('Ahmed');
        $contact->contactCategories()->attach([
            $this->category('عميل محتمل')->id,
            $this->category('عميل خاص')->id,
        ]);

        $row = (new ContactsExport(null))->collection()->first();

        $this->assertSame('عميل محتمل، عميل خاص', $row['category']);
    }

    public function test_a_contact_without_categories_exports_an_empty_cell(): void
    {
        $this->contact('Ahmed');

        $row = (new ContactsExport(null))->collection()->first();

        $this->assertSame('', $row['category']);
    }

    /**
     * الدورة الكاملة: تصدير ثم استيراد يُعيد نفس التصنيفات — وهو ما كان
     * ينكسر مع CSV.
     */
    public function test_exported_categories_can_be_imported_back(): void
    {
        $contact = $this->contact('Ahmed');
        $contact->contactCategories()->attach([
            $this->category('عميل محتمل')->id,
            $this->category('عميل ملغي الاشتراك')->id,
        ]);

        $exported = (new ContactsExport(null))->collection()->first()['category'];

        $this->assertSame(
            ['عميل محتمل', 'عميل ملغي الاشتراك'],
            $this->resolve($exported),
            'ما يُصدَّر يجب أن يُستورَد كما هو'
        );
    }

    // ------------------------------------------------ القالب

    /**
     * القالب صار مصنّف xlsx ليحمل قوائم منسدلة لحقول الاختيار — وCSV نصّ خام
     * لا يحمل قيوداً. عمود التصنيفات ومثاله يجب أن يبقيا كما كانا.
     */
    public function test_the_downloadable_template_shows_the_category_format(): void
    {
        $response = $this->get('/contacts/template');
        $response->assertOk();

        $sheet = \PhpOffice\PhpSpreadsheet\IOFactory::load(
            $response->baseResponse->getFile()->getPathname()
        )->getSheetByName('contacts');

        $headers = array_values(array_filter($sheet->rangeToArray('A1:BZ1')[0]));
        $index = array_search('category', $headers, true);

        $this->assertNotFalse($index, 'العمود يجب أن يكون في القالب');

        $letter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index + 1);

        $this->assertSame(
            'عميل محتمل، عميل خاص',
            $sheet->getCell($letter . '2')->getValue(),
            'المثال يوضّح تعدّد التصنيفات'
        );
    }
}
