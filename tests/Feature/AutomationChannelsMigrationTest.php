<?php

namespace Tests\Feature;

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ترحيل قنوات الأتمتة على قاعدةٍ بشكل الإنتاج قبل النشر: الردود والـ flows
 * القائمة تُعبّأ بواتساب والمحادثة المباشرة — ما كانت تعمل عليه — فلا يبدأ
 * بوتٌ كُتب لواتساب يجيب على تيليجرام حين تُشغَّل أتمتته.
 */
class AutomationChannelsMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = '2026_11_10_100000_add_channels_to_auto_replies_and_flows_tables';
    private const ORGANIZATION = 990001;

    protected function setUp(): void
    {
        parent::setUp();

        if (!Schema::hasTable('flows')) {
            $this->artisan('migrate', ['--path' => 'modules/FlowBuilder/Database/Migrations']);
            $this->app[ConsoleKernel::class]->setArtisan(null);
        }

        // شكل الإنتاج: الجدولان بلا العمود. DDL يُغلق معاملة الاختبار ضمناً،
        // فالصفوف تُحذف يدوياً في tearDown.
        foreach (['auto_replies', 'flows'] as $table) {
            if (Schema::hasColumn($table, 'channels')) {
                Schema::table($table, fn ($t) => $t->dropColumn('channels'));
            }
        }
    }

    protected function tearDown(): void
    {
        $this->migrate();
        DB::table('auto_replies')->where('organization_id', self::ORGANIZATION)->delete();
        DB::table('flows')->where('organization_id', self::ORGANIZATION)->delete();

        parent::tearDown();
    }

    public function test_existing_replies_and_flows_keep_the_channels_they_ran_on(): void
    {
        $reply = $this->insertReply();
        $flow = $this->insertFlow();

        $this->migrate();

        $this->assertSame(['whatsapp', 'webchat'], json_decode(DB::table('auto_replies')->where('uuid', $reply)->value('channels'), true));
        $this->assertSame(['whatsapp', 'webchat'], json_decode(DB::table('flows')->where('uuid', $flow)->value('channels'), true));
    }

    /** النشر يُعاد: المرّة الثانية لا ترمي ولا تمسّ ما اختاره التاجر بعد الأولى. */
    public function test_running_it_twice_is_safe(): void
    {
        $reply = $this->insertReply();
        $this->migrate();

        DB::table('auto_replies')->where('uuid', $reply)->update(['channels' => '["telegram"]']);
        $this->migrate();

        $this->assertSame(['telegram'], json_decode(DB::table('auto_replies')->where('uuid', $reply)->value('channels'), true));
    }

    private function migrate(): void
    {
        (require base_path('database/migrations/' . self::MIGRATION . '.php'))->up();
    }

    private function insertReply(): string
    {
        $uuid = (string) Str::uuid();

        DB::table('auto_replies')->insert([
            'uuid' => $uuid,
            'organization_id' => self::ORGANIZATION,
            'name' => 'ترحيب',
            'trigger' => 'مرحبا',
            'match_criteria' => 'contains',
            'metadata' => json_encode(['type' => 'text', 'data' => ['text' => 'أهلاً']]),
            'created_by' => 1,
        ]);

        return $uuid;
    }

    private function insertFlow(): string
    {
        $uuid = (string) Str::uuid();

        DB::table('flows')->insert([
            'uuid' => $uuid,
            'organization_id' => self::ORGANIZATION,
            'name' => 'ترحيب',
            'trigger' => 'first_message',
            'status' => 'active',
        ]);

        return $uuid;
    }
}
