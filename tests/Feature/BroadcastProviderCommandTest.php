<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\Broadcasting\BroadcastProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * أمر عرض مزوّد البثّ والتبديل بينهما.
 *
 * الأمر هو ما سيُستعمل وقت العطل — حين لا وقت لقراءة جدول الإعدادات يدوياً.
 * فخطؤه الأخطر ليس أن يسقط، بل أن يعلن حالة غير صحيحة أو يقبل تبديلاً إلى
 * وجهة لا تعمل: البثّ المعطوب لا يُسقط شيئاً، بل تُحفظ الرسائل ولا تصل
 * لحظياً — عطلٌ يبدو بطئاً فلا يُلاحَظ.
 *
 * التحقّق الحيّ (probe) يبثّ إلى خادم حقيقي، فكل اختبار هنا يمرّر --no-test
 * إلّا ما قصد اختبار الرفض قبل بلوغ التحقّق.
 */
class BroadcastProviderCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        BroadcastProvider::forget();
    }

    private function set(string $key, string $value): void
    {
        Setting::updateOrCreate(['key' => $key], ['value' => $value]);
        BroadcastProvider::forget();
    }

    /** إعداد Reverb مكتمل — بدونه يرفض الأمر التبديل وهو محقّ. */
    private function completeReverb(): void
    {
        $this->set('reverb_app_key', 'zsgyjtc10xgndtlt5mdj');
        $this->set('reverb_app_secret', 'a-secret');
        $this->set('reverb_app_id', '221796');
        $this->set('reverb_host', 'reverb.mnjz.net');
    }

    private function completePusher(): void
    {
        $this->set('pusher_app_key', 'cloud-key');
        $this->set('pusher_app_secret', 'cloud-secret');
        $this->set('pusher_app_id', '999');
        $this->set('pusher_app_cluster', 'us2');
    }

    private function stored(): ?string
    {
        return Setting::where('key', BroadcastProvider::SETTING_KEY)->value('value');
    }

    // -------------------------------------------------------- العرض

    public function test_it_names_the_active_provider(): void
    {
        $this->completeReverb();
        $this->set(BroadcastProvider::SETTING_KEY, BroadcastProvider::REVERB);

        $this->artisan('broadcast:provider')
            ->expectsOutputToContain('REVERB')
            ->assertExitCode(0);
    }

    /** العرض يذكر المزوّدين معاً — المقارنة هي الغرض. */
    public function test_it_shows_both_destinations(): void
    {
        $this->completeReverb();

        $this->artisan('broadcast:provider')
            ->expectsOutputToContain('reverb.mnjz.net')
            ->expectsOutputToContain('pusher.com')
            ->assertExitCode(0);
    }

    /**
     * غياب الصفّ يعني «افتراضي» لا «مُختار». الفرق يهمّ: من يقرأ pusher دون
     * هذا التنبيه يظنّ أن أحداً ضبطها، فيبحث عن العطل في المكان الخطأ.
     */
    public function test_it_flags_a_missing_setting_row_as_a_default_not_a_choice(): void
    {
        // الترحيل يزرع الصفّ، فحذفه هو ما يُنشئ الحالة المقصودة: بيئة رُقّيت
        // بلا ترحيل، أو صفّ حُذف يدوياً.
        Setting::where('key', BroadcastProvider::SETTING_KEY)->delete();
        BroadcastProvider::forget();

        $this->artisan('broadcast:provider')
            ->expectsOutputToContain('لا يوجد صفّ broadcast_provider')
            ->assertExitCode(0);
    }

    /** السرّ لا يُعرض، والمفتاح مقنّع. */
    public function test_it_never_prints_a_secret(): void
    {
        $this->completeReverb();
        $this->set('reverb_app_secret', 'must-not-appear');

        $this->artisan('broadcast:provider')
            ->doesntExpectOutputToContain('must-not-appear')
            ->doesntExpectOutputToContain('zsgyjtc10xgndtlt5mdj')
            ->assertExitCode(0);
    }

    /** الإعداد الناقص يُعلَن في العرض لا عند المحاولة فقط. */
    public function test_it_marks_an_incomplete_provider(): void
    {
        $this->artisan('broadcast:provider')
            ->expectsOutputToContain('ينقصه')
            ->assertExitCode(0);
    }

    // ------------------------------------------------------ التبديل

    public function test_it_switches_and_persists(): void
    {
        $this->completeReverb();

        $this->artisan('broadcast:provider', ['provider' => 'reverb', '--no-test' => true])
            ->assertExitCode(0);

        $this->assertSame('reverb', $this->stored());
        BroadcastProvider::forget();
        $this->assertSame('reverb', BroadcastProvider::active());
    }

    /** والرجوع كذلك — قيمة الميزة كلّها في أن العودة رخيصة. */
    public function test_it_switches_back(): void
    {
        $this->completeReverb();
        $this->completePusher();
        $this->set(BroadcastProvider::SETTING_KEY, BroadcastProvider::REVERB);

        $this->artisan('broadcast:provider', ['provider' => 'pusher', '--no-test' => true])
            ->assertExitCode(0);

        $this->assertSame('pusher', $this->stored());
    }

    /**
     * وجهة ناقصة المفاتيح تقبل التبديل ثم تفشل صامتة. الرفض هنا هو الحارس.
     */
    public function test_it_refuses_a_provider_with_missing_keys(): void
    {
        $this->set(BroadcastProvider::SETTING_KEY, BroadcastProvider::PUSHER);

        $this->artisan('broadcast:provider', ['provider' => 'reverb'])
            ->expectsOutputToContain('ينقصه')
            ->assertExitCode(1);

        $this->assertSame('pusher', $this->stored());
    }

    /** الرفض يذكر ما ينقص بالضبط، وإلّا فهو حائط لا إرشاد. */
    public function test_the_refusal_names_the_missing_pieces(): void
    {
        $this->set('reverb_app_key', 'only-the-key');

        $this->artisan('broadcast:provider', ['provider' => 'reverb'])
            ->expectsOutputToContain('ينقصه السرّ، المعرّف، العنوان')
            ->assertExitCode(1);
    }

    public function test_force_overrides_the_completeness_check(): void
    {
        $this->artisan('broadcast:provider', ['provider' => 'reverb', '--force' => true, '--no-test' => true])
            ->assertExitCode(0);

        $this->assertSame('reverb', $this->stored());
    }

    public function test_an_unknown_provider_is_rejected(): void
    {
        $this->artisan('broadcast:provider', ['provider' => 'ably'])
            ->expectsOutputToContain('غير معروف')
            ->assertExitCode(1);

        $this->assertSame('pusher', $this->stored());
    }

    public function test_switching_to_the_current_provider_is_a_no_op(): void
    {
        $this->completePusher();
        $this->set(BroadcastProvider::SETTING_KEY, BroadcastProvider::PUSHER);

        $this->artisan('broadcast:provider', ['provider' => 'pusher'])
            ->expectsOutputToContain('أصلاً')
            ->assertExitCode(0);

        $this->assertSame('pusher', $this->stored());
    }

    /**
     * عامل الطابور عملية طويلة العمر تحتفظ بأول قراءة، فيبقى على المزوّد
     * القديم حتى يُعاد تشغيله. التبديل بلا هذا التنبيه نصفيّ: الداشبورد ينتقل
     * والرسائل الخلفية لا.
     */
    public function test_it_reminds_to_restart_the_queue(): void
    {
        $this->completeReverb();

        $this->artisan('broadcast:provider', ['provider' => 'reverb', '--no-test' => true])
            ->expectsOutputToContain('queue:restart')
            ->assertExitCode(0);
    }

    /**
     * التحقّق الحيّ يفشل ← لا تبديل. هذا هو الحارس الأهمّ: التبديل إلى وجهة لا
     * تستجيب يترك النظام يعمل ظاهرياً ولا يصل شيء لحظياً.
     *
     * ‎.invalid نطاق محجوز لا يُحلّ أبداً (RFC 2606)، فالفشل فوري وبلا شبكة.
     */
    public function test_a_failing_probe_blocks_the_switch(): void
    {
        $this->completeReverb();
        $this->set('reverb_host', 'unreachable.invalid');

        $this->artisan('broadcast:provider', ['provider' => 'reverb'])
            ->expectsOutputToContain('رفض البثّ')
            ->assertExitCode(1);

        $this->assertSame('pusher', $this->stored());
    }

    /** ورسالة الفشل بلا الرابط الموقّع — auth_signature لا مكان له في سجلّ. */
    public function test_the_probe_error_hides_the_signed_url(): void
    {
        $this->completeReverb();
        $this->set('reverb_host', 'unreachable.invalid');

        $this->artisan('broadcast:provider', ['provider' => 'reverb'])
            ->doesntExpectOutputToContain('auth_signature')
            ->assertExitCode(1);
    }

    // ------------------------------------------------------- الحراسة

    /**
     * قراءة وجهة غير الفعّالة لا تلمس الجدول. النسخة الأولى كانت تبدّل الصفّ
     * مؤقتاً لتقرأ، وأي انقطاع بين الكتابتين يترك النظام مبدَّلاً بلا قصد.
     */
    public function test_reading_the_other_provider_does_not_touch_the_setting(): void
    {
        $this->completeReverb();
        $this->set(BroadcastProvider::SETTING_KEY, BroadcastProvider::PUSHER);

        $reverb = BroadcastProvider::connectionFor(BroadcastProvider::REVERB);

        $this->assertSame('reverb.mnjz.net', $reverb['options']['host']);
        $this->assertSame('pusher', $this->stored());
        $this->assertSame('pusher', BroadcastProvider::active());
    }
}
