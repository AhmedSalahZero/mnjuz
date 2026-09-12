<?php

namespace Tests\Feature;

use App\Jobs\ProcessIncomingMessageJob;
use App\Jobs\SyncWazBillingJob;
use App\Models\BillingPayment;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Services\WazSyncService;
use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

/**
 * أعطال رُصدت في سجلّ الإنتاج، وإصلاحها.
 *
 * أربعة منها لم تكن أعطالاً في المنطق بل في ردّ الفعل: حالات سليمة أو
 * مستحيلة العلاج تُسجَّل أخطاءً وتُعاد محاولتها، فتملأ Sentry وتُخفي ما
 * يستحقّ الانتباه. والخامس عطل حقيقي يُضيع استيراد العميل.
 */
class ProductionErrorFixesTest extends TestCase
{
    use RefreshDatabase;

    private function source(string $path): string
    {
        return file_get_contents(base_path($path));
    }

    // ---------------------------------------------- رسالة بلا مُرسِل

    /**
     * `Incoming WhatsApp message has no "from" field`
     *
     * حمولة ناقصة لا تُصلحها إعادة المحاولة، فالاستثناء كان يُفشل الوظيفة
     * خمس مرّات ثم يرميها في failed_jobs.
     */
    public function test_a_message_without_a_sender_is_skipped_not_thrown(): void
    {
        $job = $this->source('app/Jobs/ProcessIncomingMessageJob.php');

        $this->assertStringNotContainsString(
            'throw new \RuntimeException(\'Incoming WhatsApp message has no "from" field.\')',
            $job,
            'الاستثناء يُعيد محاولة ما لا يُعالَج'
        );

        $this->assertStringContainsString('private function getOrCreateContact(): ?array', $job);
        $this->assertStringContainsString('Incoming message has no sender, skipping', $job);

        // والمنادي يتعامل مع الغياب بدل أن يفكّ مصفوفة فارغة
        $this->assertStringContainsString('if ($resolved === null)', $job);
    }

    /**
     * السلوك نفسه لا شكل الكود: الحمولة الناقصة تمرّ بلا استثناء ولا أثر.
     *
     * هذه حمولة حقيقية من سجلّ الإنتاج (منشأة 260): معرّف رسالة ونوع، بلا
     * حقل from.
     */
    public function test_the_job_survives_a_payload_without_a_sender(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $organization = Organization::factory()->create(['created_by' => $user->id]);

        $before = Contact::count();

        $job = new ProcessIncomingMessageJob(
            [
                'id' => 'wamid.HBgSTVkuODc4MTg4Mzk4NTU5MzAzFRQAEhgUM0E4NDY4QjY0OTNEQjY0RDg2QjAA',
                'type' => 'text',
                'text' => ['body' => 'رسالة بلا مُرسِل'],
            ],
            [],
            $organization->id
        );

        Log::spy();

        $job->handle();

        $this->assertSame($before, Contact::count(), 'لا تُنشأ جهة اتصال من حمولة بلا مُرسِل');
        $this->assertSame(0, \App\Models\Chat::where('organization_id', $organization->id)->count());

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message) => str_contains((string) $message, 'no sender'))
            ->atLeast()->once();
    }

    // ---------------------------------------------- مزامنة واز

    /**
     * `SyncWazBillingJob has been attempted too many times`
     *
     * الوظيفة كانت تُطلق نفسها كلّما لم تجد فاتورة مُزامَنة. وشحن الرصيد
     * دفعةٌ بلا فاتورة أبداً، فتُعاد خمس مرّات ثم تموت باستثناء.
     */
    public function test_the_waz_sync_stops_waiting_instead_of_exhausting_its_attempts(): void
    {
        $job = $this->source('app/Jobs/SyncWazBillingJob.php');

        $this->assertStringContainsString('if ($this->attempts() < 3)', $job, 'الانتظار يجب أن يكون محدوداً');
        $this->assertStringContainsString('giving up', $job, 'وبعد الحدّ ينصرف بهدوء');

        // الإطلاق ما زال موجوداً للسباق الحقيقي، لكنه داخل الحدّ
        $this->assertMatchesRegularExpression(
            '/if \(\$this->attempts\(\) < 3\) \{.*?\$this->release\(120\);.*?return;/s',
            $job,
            'الإطلاق يجب أن يقع داخل الحدّ لا خارجه'
        );
    }

    /** ولا تُسجَّل خطأً: انصراف متوقَّع لا عطل. */
    public function test_giving_up_is_logged_as_info(): void
    {
        $job = $this->source('app/Jobs/SyncWazBillingJob.php');

        $this->assertMatchesRegularExpression(
            '/Log::info\(\s*\'Waz billing sync: payment has no invoice to sync, giving up\'/',
            $job
        );
    }

    /**
     * السلوك: الوظيفة تنتظر مرّتين ثم تكفّ.
     *
     * دفعةٌ بلا فاتورة كانت تُطلق نفسها بلا حدّ فتنتهي بـ
     * MaxAttemptsExceededException. نُشغّلها بمحاولة مبكّرة ثم بمحاولة
     * متأخّرة، ونراقب الإطلاق.
     */
    public function test_the_waz_job_releases_early_and_stops_later(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $organization = Organization::factory()->create([
            'created_by' => $user->id,
            'waz_company_id' => 140,
        ]);

        $payment = BillingPayment::create([
            'organization_id' => $organization->id,
            'processor' => 'myfatoorah',
            'amount' => 350,
            'currency' => 'SAR',
            'payment_status' => 'paid',
        ]);

        $sync = Mockery::mock(WazSyncService::class);
        $sync->shouldReceive('enabled')->andReturn(true);
        $sync->shouldReceive('syncPayment')->andReturnNull();
        $sync->shouldReceive('companyId')->andReturn(140);

        // المحاولة الأولى: ما زال السباق ممكناً ⇒ تُطلق من جديد
        $early = Mockery::mock(QueueJob::class);
        $early->shouldReceive('attempts')->andReturn(1);
        $early->shouldReceive('release')->once();
        $early->shouldReceive('hasFailed')->andReturn(false);
        $early->shouldReceive('isReleased')->andReturn(false);
        $early->shouldReceive('isDeletedOrReleased')->andReturn(false);

        $job = SyncWazBillingJob::forPayment($payment->id);
        $job->setJob($early);
        $job->handle($sync);

        // المحاولة الرابعة: لا فاتورة ولن تأتي ⇒ تنصرف بلا إطلاق
        $late = Mockery::mock(QueueJob::class);
        $late->shouldReceive('attempts')->andReturn(4);
        $late->shouldReceive('release')->never();
        $late->shouldReceive('hasFailed')->andReturn(false);
        $late->shouldReceive('isReleased')->andReturn(false);
        $late->shouldReceive('isDeletedOrReleased')->andReturn(false);

        $job2 = SyncWazBillingJob::forPayment($payment->id);
        $job2->setJob($late);
        $job2->handle($sync);

        $this->assertTrue(true, 'التوقّعات على المحاكاة هي التوكيد');
    }

    // ---------------------------------------------- دفعة بلا فاتورة

    /**
     * `Payment processed without producing an invoice`
     *
     * شحن الرصيد لا يُصدر فاتورة وهذا صحيح: المبلغ يُقيَّد في
     * billing_transactions ومنه يُحتسب الرصيد. تسجيله خطأً إنذار كاذب.
     */
    public function test_a_balance_top_up_is_not_reported_as_an_error(): void
    {
        $processor = $this->source('app/Services/MyFatoorah/MyFatoorahPaymentProcessor.php');

        $this->assertStringContainsString('$isTopUp = $planId === null', $processor);
        $this->assertStringContainsString('Payment credited to account balance without an invoice', $processor);

        // والخطأ يبقى لشراء خطة بلا فاتورة — وهو العطل الحقيقي
        $this->assertStringContainsString('Payment processed without producing an invoice', $processor);
        $this->assertMatchesRegularExpression(
            '/if \(\$isTopUp\) \{.*?Log::info.*?return;.*?\}.*?Log::error\(\'Payment processed without producing an invoice\'/s',
            $processor,
            'الشحن يُسجَّل info وينصرف، وما بعده يبقى error'
        );
    }

    // ---------------------------------------------- بوّابة دفع غير مُهيّأة

    /**
     * `The selected payment method is not available`
     *
     * كان استثناءً فيرى العميل 500 بعد عودته من صفحة الدفع.
     */
    public function test_an_unsupported_gateway_redirects_instead_of_crashing(): void
    {
        $controller = $this->source('app/Http/Controllers/PaymentController.php');

        $this->assertStringContainsString('$this->paymentPlatformResolver->isSupported($processor)', $controller);
        $this->assertMatchesRegularExpression(
            '/if \(!\$this->paymentPlatformResolver->isSupported\(\$processor\)\) \{.*?return redirect\(\'\/billing\'\)/s',
            $controller,
            'الفحص يجب أن يسبق resolveService ويُعيد توجيهاً'
        );
    }

    /** السلوك: العميل يُعاد إلى الفوترة، لا يرى 500. */
    public function test_an_unsupported_gateway_returns_a_redirect_not_a_server_error(): void
    {
        $response = $this->get('/payment/a-gateway-that-does-not-exist');

        $response->assertRedirect('/billing');
        $this->assertNotSame(500, $response->status());
    }

    // ---------------------------------------------- استيراد جهات الاتصال

    /**
     * `ProcessContactsImportJob failed: There is no active transaction`
     *
     * Laravel Excel يلفّ الاستيراد كلّه في معاملة واحدة. وملفٌ يستغرق دقائق
     * تُقطع وصلته بالقاعدة في أثنائه، فيُعاد الاتصال بلا معاملة ويفشل
     * الإقفال — ويضيع الاستيراد كلّه.
     */
    public function test_the_contact_import_does_not_wrap_everything_in_one_transaction(): void
    {
        $job = $this->source('app/Jobs/ProcessContactsImportJob.php');

        $this->assertStringContainsString(
            "config(['excel.transactions.handler' => null])",
            $job
        );

        // والضبط يسبق الاستيراد لا يليه
        $this->assertLessThan(
            strpos($job, 'Excel::import('),
            strpos($job, "config(['excel.transactions.handler'"),
            'الضبط بعد الاستيراد بلا أثر'
        );
    }

    /** الإعداد العام يبقى كما هو لبقيّة الاستيرادات. */
    public function test_the_global_excel_setting_is_untouched(): void
    {
        $this->assertSame('db', config('excel.transactions.handler'));
    }
}
