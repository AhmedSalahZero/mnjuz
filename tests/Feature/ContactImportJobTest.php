<?php

namespace Tests\Feature;

use App\Jobs\ProcessContactsImportJob;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Transactions\TransactionManager;
use Tests\TestCase;

/**
 * استيراد جهات الاتصال من ملف.
 *
 * العطل الأوّل: Laravel Excel يلفّ الاستيراد كلّه في معاملة واحدة، فملفٌ
 * يستغرق دقائق تُقطع وصلته بالقاعدة في أثنائه ويفشل الإقفال بـ
 * «There is no active transaction» — ويضيع الاستيراد كلّه.
 *
 * والعطل الثاني وقع في إصلاح الأوّل: عُطِّلت المعاملة بالقيمة null
 * النَّوعية، والمدير يحلّ سائقه بالاسم — فرمى «Unable to resolve NULL
 * driver» وسقط كل استيراد من أوّله. الصواب السلسلة 'null'.
 *
 * ولذلك يُشغَّل هنا استيرادٌ حقيقي من ملف حقيقي: حراسة النصّ وحدها لا تكشف
 * فرقاً بين null و'null'.
 */
class ContactImportJobTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;
    private User $user;
    private string $relativePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['role' => 'user']);
        $this->organization = Organization::factory()->create(['created_by' => $this->user->id]);
        Setting::create(['key' => 'storage_system', 'value' => 'local']);

        $this->relativePath = 'contact-imports/test-' . uniqid() . '.csv';
    }

    protected function tearDown(): void
    {
        Storage::disk('local')->delete($this->relativePath);

        parent::tearDown();
    }

    private function writeCsv(string $contents): void
    {
        Storage::disk('local')->put($this->relativePath, $contents);
    }

    private function runImport(): void
    {
        (new ProcessContactsImportJob(
            $this->organization->id,
            $this->user->id,
            $this->relativePath
        ))->handle();
    }

    // ------------------------------------------------- المسار الحقيقي

    /** استيراد كامل من ملف: الصفوف تصل القاعدة. */
    public function test_it_imports_contacts_from_a_file(): void
    {
        $this->writeCsv("first_name,phone\nسارة,+966500000011\nخالد,+966500000012\n");

        $this->runImport();

        $this->assertSame(
            2,
            Contact::where('organization_id', $this->organization->id)->count(),
            'لم يصل شيء إلى القاعدة — راجع سائق المعاملة'
        );
        $this->assertNotNull(
            Contact::where('organization_id', $this->organization->id)
                ->where('phone', '+966500000011')->first()
        );
    }

    /**
     * السائق يُحلّ فعلاً بعد ضبط الوظيفة له.
     *
     * هذا التوكيد هو ما يميّز null النَّوعية من السلسلة 'null': الأولى ترمي
     * استثناءً عند أوّل محاولة حلّ.
     */
    public function test_the_transaction_driver_resolves_after_the_job_sets_it(): void
    {
        $this->writeCsv("first_name,phone\nسارة,+966500000013\n");

        $this->runImport();

        $driver = app(TransactionManager::class)->driver();

        $this->assertInstanceOf(
            \Maatwebsite\Excel\Transactions\NullTransactionHandler::class,
            $driver,
            'المعاملة الواحدة ما زالت تلفّ الاستيراد'
        );
    }

    /** والحالة تُعلَن مكتملة لا فاشلة. */
    public function test_a_successful_import_reports_complete(): void
    {
        $this->writeCsv("first_name,phone\nسارة,+966500000014\n");

        $this->runImport();

        $status = \App\Services\ContactImportService::getStatus(
            $this->organization->id,
            $this->user->id
        );

        $this->assertSame('complete', $status['state'] ?? null, 'الاستيراد أُعلن فاشلاً');
    }

    /** الملف المؤقّت يُحذف بعد الانتهاء مهما كانت النتيجة. */
    public function test_the_uploaded_file_is_removed(): void
    {
        $this->writeCsv("first_name,phone\nسارة,+966500000015\n");

        $this->runImport();

        $this->assertFalse(Storage::disk('local')->exists($this->relativePath));
    }

    /** وملف مفقود يُعلَن فشلاً بلا استثناء يُسقط الوظيفة. */
    public function test_a_missing_file_fails_gracefully(): void
    {
        $this->runImport();

        $status = \App\Services\ContactImportService::getStatus(
            $this->organization->id,
            $this->user->id
        );

        $this->assertSame('failed', $status['state'] ?? null);
    }

    // ------------------------------------------------- الحراسة

    /** القيمة المعطِّلة سلسلة لا null — الفرق بينهما سقوط الاستيراد كلّه. */
    public function test_the_handler_is_disabled_with_the_string_not_null(): void
    {
        $source = file_get_contents(base_path('app/Jobs/ProcessContactsImportJob.php'));

        $this->assertStringContainsString(
            "config(['excel.transactions.handler' => 'null'])",
            $source
        );
        $this->assertStringNotContainsString(
            "config(['excel.transactions.handler' => null])",
            $source
        );
    }

    /** والإعداد العام يبقى كما هو لبقيّة الاستيرادات. */
    public function test_the_global_setting_is_untouched(): void
    {
        $this->assertSame('db', config('excel.transactions.handler'));
    }
}
