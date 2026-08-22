<?php

namespace Tests\Feature;

use App\Models\Addon;
use App\Models\ContactField;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\TestCase;

/**
 * قالب استيراد جهات الاتصال: القائمة المنسدلة لحقول الاختيار.
 *
 * كان القالب CSV، وCSV نصّ خام لا يحمل قيوداً. فكتب المستخدم «1» في عمود
 * «هل العميل قام بالشراء» وخياراته «نعم قام بالشراء / لا لم يقم بالشراء»:
 * حُفظت القيمة، ثم بدت شاشة التعديل فارغة لأن لا خيار يطابقها — قيمة موجودة
 * وغير مرئية، يمحوها أوّل حفظ. وهذا أسوأ من قيمة مرفوضة.
 *
 * القالب الآن xlsx يحمل تحقّقاً حقيقياً، ومثالُه قيمةٌ صالحة تُرفع كما هي.
 */
class ContactTemplateDropdownTest extends TestCase
{
    use RefreshDatabase;

    private const PURCHASE = 'هل العميل قام بالشراء';
    private const OPTIONS = ['نعم قام بالشراء', 'لا لم يقم بالشراء'];

    private User $user;
    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        // HandleInertiaRequests يقرأ is_active بلا حارس null على مسارات الويب.
        Addon::create([
            'uuid' => (string) Str::uuid(),
            'category' => 'security',
            'name' => 'Google Authenticator',
            'logo' => 'ga.png',
            'status' => 1,
            'is_active' => 0,
        ]);

        $owner = User::factory()->create(['role' => 'user']);
        $this->organization = Organization::factory()->create(['created_by' => $owner->id]);
        $this->user = User::factory()->create(['role' => 'user']);

        Team::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'user_id' => $this->user->id,
            'role' => 'owner',
            'status' => 'active',
            'created_by' => $owner->id,
        ]);
    }

    private function field(string $name, string $type = 'input', ?string $value = null): void
    {
        ContactField::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'name' => $name,
            'type' => $type,
            'value' => $value,
            'required' => 0,
        ]);
    }

    /**
     * بناء القالب وفتحه كمصنّف.
     *
     * نستدعي المتحكّم مباشرةً لا عبر المسار: المسار خلف سلسلة حرّاس (التحقّق من
     * البريد والاشتراك والدور) اختبارُها هنا يقيس الحرّاس لا القالب.
     */
    private function workbook(): \PhpOffice\PhpSpreadsheet\Spreadsheet
    {
        $this->actingAs($this->user);
        session(['current_organization' => $this->organization->id]);

        $response = app(\App\Http\Controllers\User\ContactController::class)->downloadTemplate();

        $this->assertStringContainsString(
            'contacts_template.xlsx',
            (string) $response->headers->get('content-disposition')
        );

        $source = $response->getFile()->getPathname();
        $path = tempnam(sys_get_temp_dir(), 'tpl_') . '.xlsx';
        copy($source, $path);

        return IOFactory::load($path);
    }

    private function sheet(): Worksheet
    {
        return $this->workbook()->getSheetByName('contacts');
    }

    /** @return array<int, string> */
    private function headers(Worksheet $sheet): array
    {
        return array_values(array_filter($sheet->rangeToArray('A1:BZ1')[0], fn ($v) => $v !== null && $v !== ''));
    }

    private function columnLetter(Worksheet $sheet, string $header): string
    {
        $index = array_search($header, $this->headers($sheet), true);
        $this->assertNotFalse($index, "العمود «{$header}» غائب عن القالب.");

        return \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index + 1);
    }

    // ------------------------------------------------------- الشكل

    public function test_the_template_is_a_workbook_not_a_flat_csv(): void
    {
        // CSV نصّ خام لا يحمل قيوداً — وهو ما سمح بالقيمة التي تختفي.
        $this->assertNotNull($this->workbook()->getSheetByName('contacts'));
    }

    public function test_the_standard_columns_are_present(): void
    {
        $headers = $this->headers($this->sheet());

        foreach (['first_name', 'last_name', 'phone', 'email', 'group_name', 'category'] as $column) {
            $this->assertContains($column, $headers);
        }
    }

    public function test_custom_fields_appear_as_columns(): void
    {
        $this->field('الرقم القومي');
        $this->field(self::PURCHASE, 'select', implode(', ', self::OPTIONS));

        $headers = $this->headers($this->sheet());

        $this->assertContains('الرقم القومي', $headers);
        $this->assertContains(self::PURCHASE, $headers);
    }

    // -------------------------------------------- القائمة المنسدلة

    public function test_a_select_column_carries_a_dropdown(): void
    {
        $this->field(self::PURCHASE, 'select', implode(', ', self::OPTIONS));

        $sheet = $this->sheet();
        $letter = $this->columnLetter($sheet, self::PURCHASE);

        $validation = $sheet->getCell($letter . '2')->getDataValidation();

        $this->assertSame(DataValidation::TYPE_LIST, $validation->getType());
        $this->assertTrue($validation->getShowDropDown());
    }

    /**
     * الخيارات في ورقة مخفية لا في صيغة مضمّنة: المضمّنة محدودة بـ255 حرفاً
     * وتنكسر عند الفاصلة، والخيارات العربية تتجاوزها سريعاً.
     */
    public function test_the_options_live_in_a_hidden_sheet(): void
    {
        $this->field(self::PURCHASE, 'select', implode(', ', self::OPTIONS));

        $workbook = $this->workbook();
        $options = $workbook->getSheetByName('options');

        $this->assertNotNull($options, 'ورقة الخيارات غائبة.');
        $this->assertSame(Worksheet::SHEETSTATE_HIDDEN, $options->getSheetState());
        $this->assertSame(self::OPTIONS[0], $options->getCell('A1')->getValue());
        $this->assertSame(self::OPTIONS[1], $options->getCell('A2')->getValue());

        $letter = $this->columnLetter($workbook->getSheetByName('contacts'), self::PURCHASE);
        $formula = $workbook->getSheetByName('contacts')->getCell($letter . '2')->getDataValidation()->getFormula1();

        $this->assertStringContainsString('options', $formula);
    }

    /** التحقّق يمتدّ إلى صفوف كثيرة لا إلى صفّ المثال وحده. */
    public function test_the_dropdown_covers_many_rows(): void
    {
        $this->field(self::PURCHASE, 'select', implode(', ', self::OPTIONS));

        $sheet = $this->sheet();
        $letter = $this->columnLetter($sheet, self::PURCHASE);

        $this->assertSame(
            DataValidation::TYPE_LIST,
            $sheet->getCell($letter . '100')->getDataValidation()->getType()
        );
    }

    /** والحقل النصّي لا يُقيَّد. */
    public function test_a_text_column_has_no_dropdown(): void
    {
        $this->field('الرقم القومي');

        $sheet = $this->sheet();
        $letter = $this->columnLetter($sheet, 'الرقم القومي');

        $this->assertNotSame(
            DataValidation::TYPE_LIST,
            $sheet->getCell($letter . '2')->getDataValidation()->getType()
        );
    }

    // -------------------------------------------------- صفّ المثال

    /**
     * المثال يجب أن يُرفع كما هو. «Sample …» في عمود اختيار قيمةٌ ليست بين
     * خياراته — أي أن القالب نفسه كان يُعلّم المستخدم كتابة قيمة تختفي.
     */
    public function test_the_sample_row_uses_a_real_option(): void
    {
        $this->field(self::PURCHASE, 'select', implode(', ', self::OPTIONS));

        $sheet = $this->sheet();
        $letter = $this->columnLetter($sheet, self::PURCHASE);

        $this->assertContains($sheet->getCell($letter . '2')->getValue(), self::OPTIONS);
    }

    /** أمّا الحقل النصّي فمثالُه توضيحيّ كما كان. */
    public function test_a_text_field_keeps_its_illustrative_sample(): void
    {
        $this->field('الرقم القومي');

        $sheet = $this->sheet();
        $letter = $this->columnLetter($sheet, 'الرقم القومي');

        $this->assertStringContainsString('الرقم القومي', (string) $sheet->getCell($letter . '2')->getValue());
    }

    /** حقل اختيار بلا خيارات لا يُنتج قائمة فارغة تمنع كل إدخال. */
    public function test_a_select_field_without_options_gets_no_dropdown(): void
    {
        $this->field('بلا خيارات', 'select', '');

        $sheet = $this->sheet();
        $letter = $this->columnLetter($sheet, 'بلا خيارات');

        $this->assertNotSame(
            DataValidation::TYPE_LIST,
            $sheet->getCell($letter . '2')->getDataValidation()->getType()
        );
    }
}
