<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * الترحيلان اللذان يضيفان `first_message` و`trigger_timeout` إلى جدول flows.
 *
 * سقط النشر عليهما في الإنتاج: الأوّل رمى خطأ صياغة (1064) لأنه كان يستعمل
 * `SHOW COLUMNS ... LIKE ?` — وMySQL لا تقبل معاملاً مربوطاً هناك. و
 * `php artisan migrate` يقف عند أوّل فشل، فالثاني لم يعمل ولم يُضَف العمود.
 * والكود كان منشوراً بالفعل، فسقط ProcessAutoReplyJob في كل المنشآت بـ
 * «Unknown column 'trigger_timeout'».
 *
 * ولم يُمسك شيء من ذلك قبل النشر لأن كل اختبارات flows تبني الجدول من ترحيل
 * الوحدة — وهو يُنشئه بالشكل النهائي أصلاً. فالترحيلان لم يُنفَّذا قطّ على
 * جدول بالشكل القديم، وهو الشكل الوحيد الموجود في الإنتاج.
 *
 * هذا الاختبار يبني الشكل القديم ثم يُشغّلهما عليه.
 */
class FlowTriggerMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const LEGACY_TRIGGERS = "enum('new_contact','keywords')";

    protected function setUp(): void
    {
        parent::setUp();
        $this->rebuildFlows(self::LEGACY_TRIGGERS, withTimeout: false);
    }

    protected function tearDown(): void
    {
        // إعادة الجدول إلى شكله الحالي كي لا يرث غيرُنا جدولاً قديماً:
        // DDL في MySQL يُغلق معاملة الاختبار ضمناً، فالتراجع لا يُنظّفه.
        $this->rebuildFlows("enum('new_contact','keywords','first_message')", withTimeout: true);

        parent::tearDown();
    }

    private function rebuildFlows(string $triggerType, bool $withTimeout): void
    {
        // flow_user_data و flow_logs تحملان foreign key إلى flows، فالحذف
        // يفشل متى كانتا موجودتين — وهو ما يحدث داخل الـ suite لا وحدنا،
        // لأن اختباراً سابقاً يُشغّل ترحيلات الوحدة. والجدول يُعاد بنفس
        // تعريف `id`، فترتبط المفاتيح به من جديد.
        Schema::disableForeignKeyConstraints();

        try {
            $this->recreate($triggerType, $withTimeout);
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }

    private function recreate(string $triggerType, bool $withTimeout): void
    {
        Schema::dropIfExists('flows');

        DB::statement(
            'CREATE TABLE `flows` ('
            . '`id` bigint unsigned NOT NULL AUTO_INCREMENT,'
            . '`uuid` char(36) NOT NULL,'
            . '`organization_id` bigint unsigned NOT NULL,'
            . '`name` varchar(255) NOT NULL,'
            . '`description` text NULL,'
            . '`trigger` ' . $triggerType . ' NULL,'
            . '`keywords` text NULL,'
            . ($withTimeout ? '`trigger_timeout` int unsigned NULL,' : '')
            . '`metadata` text NULL,'
            . "`status` enum('active','inactive') NOT NULL DEFAULT 'inactive',"
            . '`created_at` timestamp NULL,`updated_at` timestamp NULL,`deleted_at` timestamp NULL,'
            . 'PRIMARY KEY (`id`), UNIQUE KEY `flows_uuid_unique` (`uuid`)'
            . ') DEFAULT CHARSET=utf8mb4'
        );
    }

    /** تشغيل ترحيل بعينه كما يفعل artisan، بلا المرور بسجلّ الترحيلات. */
    private function runMigration(string $file): void
    {
        $migration = require base_path('database/migrations/' . $file . '.php');
        $migration->up();
    }

    private function runBothInDeployOrder(): void
    {
        $this->runMigration('2026_09_10_120000_add_first_message_trigger_to_flows_table');
        $this->runMigration('2026_09_10_130000_add_trigger_timeout_to_flows_table');
    }

    private function triggerType(): string
    {
        return (string) DB::selectOne(
            'SELECT COLUMN_TYPE FROM information_schema.COLUMNS'
            . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [DB::getTablePrefix() . 'flows', 'trigger']
        )->COLUMN_TYPE;
    }

    // ------------------------------------------------ الشكل القديم

    /** الشكل الذي نبدأ منه هو شكل الإنتاج قبل النشر. */
    public function test_the_starting_point_is_the_production_shape(): void
    {
        $this->assertStringNotContainsString('first_message', $this->triggerType());
        $this->assertFalse(Schema::hasColumn('flows', 'trigger_timeout'));
    }

    // ------------------------------------------------ النشر

    /** العطل نفسه: الترحيل الأوّل كان يرمي 1064 فيمنع الثاني. */
    public function test_both_migrations_apply_to_a_legacy_table(): void
    {
        $this->runBothInDeployOrder();

        $this->assertStringContainsString('first_message', $this->triggerType());
        $this->assertTrue(
            Schema::hasColumn('flows', 'trigger_timeout'),
            'العمود الذي كان غيابه يُسقط ProcessAutoReplyJob'
        );
    }

    /** والاستعلام الذي كان يسقط في الإنتاج يعمل بعدهما — بنصّه. */
    public function test_the_query_that_failed_in_production_runs_afterwards(): void
    {
        $this->runBothInDeployOrder();

        $rows = DB::select(
            'select * from `flows` where `organization_id` = ? and `status` = ?'
            . ' and `trigger` in (?, ?) and `trigger_timeout` is not null'
            . ' and `trigger_timeout` > 0 and `trigger_timeout` <= ? order by `id` asc limit 1',
            [30, 'active', 'first_message', 'new_contact', 11]
        );

        $this->assertSame([], $rows);
    }

    /** و`first_message` صارت قيمة مقبولة فعلاً لا في الوصف وحده. */
    public function test_the_new_trigger_value_is_accepted_by_the_column(): void
    {
        $this->runBothInDeployOrder();

        DB::table('flows')->insert([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'organization_id' => 1,
            'name' => 'ترحيب',
            'trigger' => 'first_message',
            'trigger_timeout' => 30,
            'status' => 'active',
        ]);

        $saved = DB::table('flows')->first();
        $this->assertSame('first_message', $saved->trigger);
        $this->assertSame(30, (int) $saved->trigger_timeout);
    }

    /** النشر يُعاد، والترحيل قد يكون عمل: لا يرمي في المرّة الثانية. */
    public function test_running_them_twice_is_safe(): void
    {
        $this->runBothInDeployOrder();
        $this->runBothInDeployOrder();

        $this->assertStringContainsString('first_message', $this->triggerType());
        $this->assertTrue(Schema::hasColumn('flows', 'trigger_timeout'));
    }

    /** ولا يُحذف عمود ولا تضيع بيانات صفٍّ قائم. */
    public function test_existing_rows_survive_the_migration(): void
    {
        DB::table('flows')->insert([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'organization_id' => 7,
            'name' => 'flow قديم',
            'trigger' => 'keywords',
            'keywords' => 'مرحبا',
            'status' => 'active',
        ]);

        $this->runBothInDeployOrder();

        $row = DB::table('flows')->first();
        $this->assertSame('keywords', $row->trigger);
        $this->assertSame('مرحبا', $row->keywords);
        $this->assertNull($row->trigger_timeout);
    }

    // ------------------------------------------------ حراسة على المصدر

    /** لا `SHOW ... LIKE ?` مرّة أخرى: MySQL ترفض الربط فيها. */
    public function test_no_migration_binds_a_parameter_inside_a_show_statement(): void
    {
        foreach (glob(base_path('database/migrations/*.php')) as $path) {
            $this->assertDoesNotMatchRegularExpression(
                '/\bSHOW\b.{0,200}?LIKE\s+\?/i',
                $this->codeWithoutComments($path),
                basename($path) . ': MySQL ترفض المعامل المربوط في SHOW ... LIKE (خطأ 1064)'
            );
        }
    }

    /**
     * الكود بلا تعليقات.
     *
     * التعليق الذي يشرح هذا العطل يذكر الجملة المكسورة نصّاً، فالبحث في
     * الملف كما هو يُسقط الحارسَ على شرحه لا على كوده.
     */
    private function codeWithoutComments(string $path): string
    {
        $code = '';

        foreach (token_get_all(file_get_contents($path)) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }

                $code .= $token[1];
                continue;
            }

            $code .= $token;
        }

        return $code;
    }
}
