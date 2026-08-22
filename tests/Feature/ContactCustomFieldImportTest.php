<?php

namespace Tests\Feature;

use App\Imports\ContactsImport;
use App\Models\Contact;
use App\Models\ContactField;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Imports\HeadingRowFormatter;
use ReflectionMethod;
use Tests\TestCase;

/**
 * الحقول المخصّصة في استيراد جهات الاتصال.
 *
 * عطلٌ وقع: رفع المستخدم ملفاً صحيحاً فيه أعمدة «هل العميل قام بالشراء»
 * و«الرقم القومي» مملوءة، فاستُوردت جهة الاتصال وأُهملت كل القيم العربية —
 * بلا خطأ ولا صفّ فاشل، فبدا الأمر كأنه أخطأ في الإدخال.
 *
 * السبب أن مكتبة الاستيراد تمرّر عناوين الأعمدة على مُنسّق «slug» الذي ينقل
 * غير اللاتيني حرفياً: «الرقم القومي» يصير alrkm_alkomy. وكنّا نبحث في الصفّ
 * عن «الرقم_القومي» فلا نجده أبداً. الحقل الإنجليزي وحده كان يعمل — وهو ما
 * جعل العطل يبدو عشوائياً.
 */
class ContactCustomFieldImportTest extends TestCase
{
    use RefreshDatabase;

    private const ARABIC_FIELDS = [
        'هل العميل قام بالشراء',
        'رقم الهويه مطعم صبا الفته',
        'الرقم القومي',
    ];

    private Organization $organization;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => 'user']);
        $this->organization = Organization::factory()->create(['created_by' => $this->owner->id]);
    }

    private function field(string $name, string $type = 'text', ?string $value = null): ContactField
    {
        return ContactField::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'name' => $name,
            'type' => $type,
            'value' => $value,
            'required' => 0,
        ]);
    }

    private const PURCHASE = 'هل العميل قام بالشراء';
    private const PURCHASE_OPTIONS = 'نعم قام بالشراء, لا لم يقم بالشراء';

    private function selectField(): ContactField
    {
        return $this->field(self::PURCHASE, 'select', self::PURCHASE_OPTIONS);
    }

    private function importRow(array $headersToValues): ?Contact
    {
        $import = new ContactsImport($this->organization->id, $this->owner->id);
        $model = $import->model($this->rowFromHeaders($headersToValues));
        $this->lastImport = $import;

        return $model;
    }

    private ?ContactsImport $lastImport = null;

    /** الصفّ كما تسلّمه المكتبة: مفاتيحه عناوين مرّت على المُنسّق. */
    private function rowFromHeaders(array $headersToValues): array
    {
        $headers = array_keys($headersToValues);
        $slugs = HeadingRowFormatter::format($headers);

        return array_combine($slugs, array_values($headersToValues));
    }

    /** @param array<int, string> $fieldNames */
    private function metadataFor(array $row, array $fieldNames): array
    {
        $import = new ContactsImport($this->organization->id, $this->owner->id);
        $method = new ReflectionMethod(ContactsImport::class, 'buildMetadataFromRow');
        $method->setAccessible(true);

        return $method->invoke($import, $row, $fieldNames);
    }

    // ------------------------------------------------- جوهر العطل

    public function test_arabic_custom_fields_are_imported(): void
    {
        foreach (self::ARABIC_FIELDS as $name) {
            $this->field($name);
        }

        $row = $this->rowFromHeaders([
            'phone' => '201025894987',
            'هل العميل قام بالشراء' => '1',
            'رقم الهويه مطعم صبا الفته' => '1213654789',
            'الرقم القومي' => '1111111',
        ]);

        $metadata = $this->metadataFor($row, self::ARABIC_FIELDS);

        $this->assertSame('1', $metadata['هل العميل قام بالشراء']);
        $this->assertSame('1213654789', $metadata['رقم الهويه مطعم صبا الفته']);
        $this->assertSame('1111111', $metadata['الرقم القومي']);
    }

    /**
     * المُنسّق ينقل العربية حرفياً — وهذه هي الحقيقة التي بُني عليها الإصلاح.
     * لو تغيّر سلوك المكتبة يوماً سقط هذا الاختبار ونبّهنا قبل المستخدمين.
     */
    public function test_the_library_transliterates_arabic_headings(): void
    {
        $slugs = HeadingRowFormatter::format(['الرقم القومي', 'first_name']);

        $this->assertSame('alrkm_alkomy', $slugs[0]);
        $this->assertSame('first_name', $slugs[1]);
        $this->assertContains('alrkm_alkomy', ContactsImport::fieldColumnKeys('الرقم القومي'));
    }

    /** الإنجليزي كان يعمل ويجب أن يبقى — التهجئتان متطابقتان فيه. */
    public function test_latin_custom_fields_still_work(): void
    {
        $this->field('test for Mohammed');

        $row = $this->rowFromHeaders(['test for Mohammed' => 'test for Mohammed va']);

        $this->assertSame(
            ['test for Mohammed' => 'test for Mohammed va'],
            $this->metadataFor($row, ['test for Mohammed'])
        );
    }

    /** خليط الحقول في ملف واحد: لا يُهمَل أيّ منها. */
    public function test_a_mixed_file_imports_every_field(): void
    {
        $fields = ['الرقم القومي', 'test for Mohammed'];
        foreach ($fields as $name) {
            $this->field($name);
        }

        $row = $this->rowFromHeaders([
            'phone' => '201025894987',
            'الرقم القومي' => '1111111',
            'test for Mohammed' => 'va',
        ]);

        $this->assertSame(
            ['الرقم القومي' => '1111111', 'test for Mohammed' => 'va'],
            $this->metadataFor($row, $fields)
        );
    }

    // ---------------------------------------------------- الحدود

    /** عمود غائب لا يُخترَع له مفتاح فارغ في البيانات. */
    public function test_a_missing_column_is_absent_not_empty(): void
    {
        $row = $this->rowFromHeaders(['phone' => '201025894987']);

        $this->assertSame([], $this->metadataFor($row, ['الرقم القومي']));
    }

    /** عمود موجود بقيمة فارغة يُحفظ فارغاً — الإفراغ نيّة صريحة. */
    public function test_an_empty_value_is_kept(): void
    {
        $row = $this->rowFromHeaders(['الرقم القومي' => '']);

        $this->assertSame(['الرقم القومي' => ''], $this->metadataFor($row, ['الرقم القومي']));
    }

    /** التهجئة القديمة تبقى مقبولة لمن كان ملفّه يعمل. */
    public function test_the_previous_spelling_is_still_accepted(): void
    {
        $this->assertSame(
            ['رقم العميل' => '77'],
            $this->metadataFor(['رقم_العميل' => '77'], ['رقم العميل'])
        );
    }

    /**
     * اسم بلا حروف: المُنسّق يُفرغه، والصيغة القديمة تُبقيه شُرَطاً محوّلة.
     * المهمّ ألّا يتسرّب مفتاح فارغ يُطابق كل شيء.
     */
    public function test_a_symbols_only_field_never_yields_an_empty_key(): void
    {
        $keys = ContactsImport::fieldColumnKeys('---');

        $this->assertNotContains('', $keys);
        $this->assertSame(['___'], $keys);
    }

    // ------------------------------------------ الاستيراد كاملاً

    /** من الصفّ إلى الصفّ في القاعدة: القيم تصل فعلاً. */
    public function test_the_values_reach_the_stored_contact(): void
    {
        foreach (self::ARABIC_FIELDS as $name) {
            $this->field($name);
        }

        $import = new ContactsImport($this->organization->id, $this->owner->id);
        $model = $import->model($this->rowFromHeaders([
            'first_name' => 'John',
            'last_name' => 'Doe',
            'phone' => '201025894987',
            'هل العميل قام بالشراء' => '1',
            'رقم الهويه مطعم صبا الفته' => '1213654789',
            'الرقم القومي' => '1111111',
        ]));

        $this->assertInstanceOf(Contact::class, $model);
        $model->save();

        $stored = json_decode(Contact::find($model->id)->metadata, true);

        $this->assertSame('1', $stored['هل العميل قام بالشراء']);
        $this->assertSame('1213654789', $stored['رقم الهويه مطعم صبا الفته']);
        $this->assertSame('1111111', $stored['الرقم القومي']);
    }

    // ------------------------------------------------ حقول الاختيار

    /**
     * جوهر المشكلة الثانية: قيمة خارج الخيارات كانت تُحفظ ثم تختفي — شاشة
     * التعديل لا تجد خياراً يطابقها فتبدو فارغة، وأوّل حفظ يمحوها.
     */
    public function test_a_value_outside_the_options_is_rejected_with_its_reason(): void
    {
        $this->selectField();

        $contact = $this->importRow([
            'first_name' => 'John',
            'phone' => '201025894987',
            self::PURCHASE => '1',
        ]);

        $this->assertNull($contact, 'قيمة اختيار مجهولة مرّت بصمت.');

        $failure = $this->lastImport->getFailedImports()[0]['error'] ?? '';
        $this->assertStringContainsString('1', $failure);
        $this->assertStringContainsString('نعم قام بالشراء', $failure, 'الرفض لا يذكر المسموح.');
    }

    public function test_a_listed_option_is_accepted(): void
    {
        $this->selectField();

        $contact = $this->importRow([
            'first_name' => 'John',
            'phone' => '201025894987',
            self::PURCHASE => 'نعم قام بالشراء',
        ]);

        $this->assertNotNull($contact);
        $contact->save();

        $stored = json_decode(Contact::find($contact->id)->metadata, true);
        $this->assertSame('نعم قام بالشراء', $stored[self::PURCHASE]);
    }

    /** مسافات زائدة ليست خطأً: من يكتب في إكسل يتركها بلا قصد. */
    public function test_surrounding_whitespace_still_matches_an_option(): void
    {
        $this->selectField();

        $contact = $this->importRow([
            'first_name' => 'John',
            'phone' => '201025894987',
            self::PURCHASE => '  نعم قام بالشراء  ',
        ]);

        $this->assertNotNull($contact);
        $contact->save();

        $stored = json_decode(Contact::find($contact->id)->metadata, true);
        $this->assertSame('نعم قام بالشراء', $stored[self::PURCHASE], 'القيمة لم تُردّ إلى الخيار الحقيقي.');
    }

    public function test_case_differences_still_match_an_option(): void
    {
        $this->field('Status', 'select', 'Yes, No');

        $contact = $this->importRow([
            'first_name' => 'John',
            'phone' => '201025894987',
            'Status' => 'yes',
        ]);

        $this->assertNotNull($contact);
        $contact->save();

        $this->assertSame('Yes', json_decode(Contact::find($contact->id)->metadata, true)['Status']);
    }

    /** عمود اختيار متروك فارغاً لا يمنع الصفّ: الحقل غير مطلوب. */
    public function test_an_empty_select_column_does_not_fail_the_row(): void
    {
        $this->selectField();

        $this->assertNotNull($this->importRow([
            'first_name' => 'John',
            'phone' => '201025894987',
            self::PURCHASE => '',
        ]));
    }

    /** والحقول النصّية تقبل ما يُكتب فيها كما كانت. */
    public function test_free_text_fields_are_not_constrained(): void
    {
        $this->field('الرقم القومي');

        $contact = $this->importRow([
            'first_name' => 'John',
            'phone' => '201025894987',
            'الرقم القومي' => '1111111',
        ]);

        $this->assertNotNull($contact);
    }
}
