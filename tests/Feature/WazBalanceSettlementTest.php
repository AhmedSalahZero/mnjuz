<?php

namespace Tests\Feature;

use App\Models\BillingInvoice;
use App\Models\BillingPayment;
use App\Models\Organization;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\WazBusinessService;
use App\Services\WazSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * تسوية فاتورة واز حين لا تقابلها دفعة مرتبطة.
 *
 * ما جرى في الإنتاج: عميل يدفع تحويلاً بنكياً فيُدخله الدعم يدوياً، أو يشحن
 * رصيده فيُجدَّد الاشتراك منه لاحقاً. في الحالتين تصدر الفاتورة وتُرحَّل إلى
 * واز — «انعكست الفاتورة» — ثم تبقى «غير مدفوعة» أبداً، لأن syncPayment
 * يشترط دفعةً مرتبطة بالفاتورة ولا دفعة مرتبطة هنا. فحصنا واز: ثلاث فواتير
 * من إنشائنا سُوّيت بيد المحاسب بعد أيام (بلا ملاحظة ولا معرّف عملية)،
 * بينما كل فاتورة قابلتها دفعة بوّابة سُدّدت آلياً في ثانيتها.
 */
class WazBalanceSettlementTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['role' => 'user']);
        $this->organization = Organization::factory()->create([
            'created_by' => $this->user->id,
            'waz_company_id' => 500,
        ]);
    }

    private function plan(): SubscriptionPlan
    {
        return SubscriptionPlan::create([
            'name' => 'Test Plan',
            'price' => 280,
            'period' => 'monthly',
            'metadata' => json_encode([]),
        ]);
    }

    private function invoice(array $attributes = []): BillingInvoice
    {
        return BillingInvoice::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'plan_id' => $this->plan()->id,
            'subtotal' => 280,
            'setup_fee' => 0,
            'tax' => 42,
            'tax_type' => 'exclusive',
            'total' => 322,
            'waz_invoice_id' => 900,
        ], $attributes));
    }

    /** WazBusinessService مُستبدَل: الاختبار يفحص قرارنا لا شبكة المنصة. */
    private function fakeWaz(): Mockery\MockInterface
    {
        $fake = Mockery::mock(WazBusinessService::class);
        $fake->shouldReceive('isConfigured')->andReturn(true);
        $this->app->instance(WazBusinessService::class, $fake);

        return $fake;
    }

    // ------------------------------------------- التسوية من الرصيد

    /**
     * الحالة التي كانت تُترك للمحاسب: فاتورة مُرحَّلة بلا دفعة مرتبطة.
     */
    public function test_an_invoice_with_no_linked_payment_is_settled_from_the_balance(): void
    {
        $invoice = $this->invoice();
        $waz = $this->fakeWaz();

        $waz->shouldReceive('addPayment')
            ->once()
            ->withArgs(function (array $data) use ($invoice) {
                return $data['invoice_id'] === 900
                    && (float) $data['amount'] === 322.00
                    && $data['payment_method'] === 'Account Balance'
                    && $data['transaction_id'] === 'mnjz-balance-' . $invoice->id;
            })
            ->andReturn(['id' => 1]);

        app(WazSyncService::class)->settleFromBalance($invoice);

        $this->addToAssertionCount(1);
    }

    /**
     * الحارس الذي يمنع تحصيل المبلغ مرّتين: وجود دفعة مرتبطة يعني أن مسارها
     * هو من يسجّلها.
     */
    public function test_an_invoice_that_has_a_linked_payment_is_left_to_its_own_path(): void
    {
        $invoice = $this->invoice();

        BillingPayment::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'processor' => 'myfatoorah',
            'amount' => 322,
            'invoice_id' => $invoice->id,
            'transaction_id' => 'gw-1',
        ]);

        $waz = $this->fakeWaz();
        $waz->shouldNotReceive('addPayment');

        app(WazSyncService::class)->settleFromBalance($invoice);

        $this->addToAssertionCount(1);
    }

    public function test_an_invoice_that_never_reached_waz_is_skipped(): void
    {
        $invoice = $this->invoice(['waz_invoice_id' => null]);

        $waz = $this->fakeWaz();
        $waz->shouldNotReceive('addPayment');

        app(WazSyncService::class)->settleFromBalance($invoice);

        $this->addToAssertionCount(1);
    }

    public function test_a_zero_invoice_is_not_settled(): void
    {
        $invoice = $this->invoice(['subtotal' => 0, 'total' => 0, 'tax' => 0]);

        $waz = $this->fakeWaz();
        $waz->shouldNotReceive('addPayment');

        app(WazSyncService::class)->settleFromBalance($invoice);

        $this->addToAssertionCount(1);
    }

    /** فاتورتان في واز (تأسيس واشتراك) تقتسمان المبلغ بقدر استحقاقهما. */
    public function test_the_settlement_is_split_between_setup_and_plan_invoices(): void
    {
        $invoice = $this->invoice([
            'setup_fee' => 230,
            'total' => 552,
            'waz_invoice_id' => 900,
            'waz_setup_invoice_id' => 899,
        ]);

        $waz = $this->fakeWaz();
        $recorded = [];

        $waz->shouldReceive('addPayment')->twice()
            ->andReturnUsing(function (array $data) use (&$recorded) {
                $recorded[] = $data;

                return ['id' => count($recorded)];
            });

        app(WazSyncService::class)->settleFromBalance($invoice);

        $this->assertSame(899, $recorded[0]['invoice_id']);
        $this->assertSame(230.0, round((float) $recorded[0]['amount'], 2));
        $this->assertStringEndsWith('-setup', $recorded[0]['transaction_id']);

        $this->assertSame(900, $recorded[1]['invoice_id']);
        $this->assertSame(322.0, round((float) $recorded[1]['amount'], 2));
        $this->assertSame('mnjz-balance-' . $invoice->id, $recorded[1]['transaction_id']);
    }

    // ------------------------------------------- الدفعة بلا معرّف عملية

    /**
     * الدفعة اليدوية بلا معرّف عملية، وفحص التكرار في المنصة مشروط بوجوده.
     * فنُولّد معرّفاً ثابتاً — وهو نفسه معرّف تسوية الرصيد، فلا يجتمع
     * التسجيلان على فاتورة واحدة.
     */
    public function test_a_payment_without_a_gateway_reference_uses_the_stable_balance_reference(): void
    {
        $invoice = $this->invoice();

        $payment = BillingPayment::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'processor' => 'bank',
            'amount' => 322,
            'invoice_id' => $invoice->id,
        ]);

        $waz = $this->fakeWaz();
        $waz->shouldReceive('addPayment')
            ->once()
            ->withArgs(fn (array $data) => $data['transaction_id'] === 'mnjz-balance-' . $invoice->id
                && $data['payment_method'] === 'bank')
            ->andReturn(['id' => 1]);

        app(WazSyncService::class)->syncPayment($payment);

        $this->assertNotNull($payment->fresh()->waz_synced_at);
    }

    /** معرّف البوابة يبقى كما هو — هو مفتاح المطابقة في المحاسبة. */
    public function test_a_gateway_payment_keeps_its_own_transaction_id(): void
    {
        $invoice = $this->invoice();

        $payment = BillingPayment::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'processor' => 'myfatoorah',
            'payment_method' => 'VISA/MASTER',
            'amount' => 322,
            'invoice_id' => $invoice->id,
            'transaction_id' => '0808897814918729850285',
        ]);

        $waz = $this->fakeWaz();
        $waz->shouldReceive('addPayment')
            ->once()
            ->withArgs(fn (array $data) => $data['transaction_id'] === '0808897814918729850285'
                && $data['payment_method'] === 'VISA/MASTER')
            ->andReturn(['id' => 1]);

        app(WazSyncService::class)->syncPayment($payment);

        $this->assertNotNull($payment->fresh()->waz_synced_at);
    }

    public function test_a_payment_with_no_invoice_is_still_skipped(): void
    {
        $payment = BillingPayment::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'processor' => 'bank',
            'amount' => 322,
        ]);

        $waz = $this->fakeWaz();
        $waz->shouldNotReceive('addPayment');

        app(WazSyncService::class)->syncPayment($payment);

        $this->assertNull($payment->fresh()->waz_synced_at);
    }



    // ------------------------------------------- المبلغ مقابل المستحقّ

    /**
     * دفعةٌ أكبر من فاتورتها — وقعت في الإنتاج على المنشأة 240: دفع 530
     * لفاتورة 322 فبقي الفرق رصيداً له. كنّا نُرسل 530 على فاتورة 322،
     * فترفضها المنصة «المبلغ يتجاوز رصيد الفاتورة» وتبقى الفاتورة غير
     * مدفوعة عند المحاسب رغم أن العميل دفع.
     */
    public function test_an_overpayment_is_capped_at_what_the_invoice_is_due(): void
    {
        $invoice = $this->invoice();

        $payment = BillingPayment::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'processor' => 'myfatoorah',
            'payment_method' => 'mada',
            'amount' => 530,
            'invoice_id' => $invoice->id,
            'transaction_id' => '0808904397628794742185',
        ]);

        $waz = $this->fakeWaz();
        $waz->shouldReceive('addPayment')
            ->once()
            ->withArgs(fn (array $data) => (float) $data['amount'] === 322.00 && $data['invoice_id'] === 900)
            ->andReturn(['id' => 1]);

        app(WazSyncService::class)->syncPayment($payment);

        $this->assertNotNull($payment->fresh()->waz_synced_at);
    }

    /**
     * دفعةٌ أصغر من فاتورتها: الباقي جاء من رصيد سابق، فيُسجَّل هو أيضاً
     * وإلا بقيت الفاتورة «مدفوعة جزئياً» وهي مسدَّدة عندنا بالكامل.
     */
    public function test_a_partial_payment_is_completed_from_the_balance(): void
    {
        $invoice = $this->invoice();

        $payment = BillingPayment::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'processor' => 'myfatoorah',
            'payment_method' => 'Apple Pay',
            'amount' => 300,
            'invoice_id' => $invoice->id,
            'transaction_id' => 'gw-300',
        ]);

        $waz = $this->fakeWaz();
        $recorded = [];
        $waz->shouldReceive('addPayment')->twice()
            ->andReturnUsing(function (array $data) use (&$recorded) {
                $recorded[] = $data;

                return ['id' => count($recorded)];
            });

        app(WazSyncService::class)->syncPayment($payment);

        $this->assertSame(300.0, round((float) $recorded[0]['amount'], 2), 'الدفعة تُسجَّل بقيمتها');
        $this->assertSame('gw-300', $recorded[0]['transaction_id']);
        $this->assertSame(22.0, round((float) $recorded[1]['amount'], 2), 'والباقي من الرصيد');
        $this->assertSame('Account Balance', $recorded[1]['payment_method']);
        $this->assertSame('mnjz-balance-' . $invoice->id, $recorded[1]['transaction_id']);
    }

    public function test_an_exact_payment_records_once(): void
    {
        $invoice = $this->invoice();

        $payment = BillingPayment::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'processor' => 'myfatoorah',
            'amount' => 322,
            'invoice_id' => $invoice->id,
            'transaction_id' => 'gw-exact',
        ]);

        $waz = $this->fakeWaz();
        $waz->shouldReceive('addPayment')->once()->andReturn(['id' => 1]);

        app(WazSyncService::class)->syncPayment($payment);

        $this->addToAssertionCount(1);
    }

    /**
     * فاتورة سوّاها المحاسب بيده: المنصة ترفض أي دفعة عليها. الغاية محقَّقة،
     * فلا يصحّ أن نُعيد المحاولة خمس مرّات ونملأ سجلّ الأخطاء.
     */
    public function test_an_already_settled_invoice_is_not_retried(): void
    {
        $invoice = $this->invoice();

        $payment = BillingPayment::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'processor' => 'bank',
            'amount' => 322,
            'invoice_id' => $invoice->id,
        ]);

        $waz = $this->fakeWaz();
        $waz->shouldReceive('addPayment')->once()->andThrow(
            new \App\Exceptions\WazBusinessException('The payment amount cannot exceed the invoice remaining balance.')
        );

        app(WazSyncService::class)->syncPayment($payment);

        $this->assertNotNull(
            $payment->fresh()->waz_synced_at,
            'الدفعة يجب أن تُعدّ مزامَنة: الفاتورة مسدَّدة فعلاً'
        );
    }

    /** أي رفض آخر يبقى خطأً يُعاد معه المحاولة. */
    public function test_other_rejections_still_fail_loudly(): void
    {
        $invoice = $this->invoice();

        $payment = BillingPayment::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'processor' => 'bank',
            'amount' => 322,
            'invoice_id' => $invoice->id,
        ]);

        $waz = $this->fakeWaz();
        $waz->shouldReceive('addPayment')->once()->andThrow(
            new \App\Exceptions\WazBusinessException('Invoice not found.')
        );

        $this->expectException(\App\Exceptions\WazBusinessException::class);

        app(WazSyncService::class)->syncPayment($payment);
    }

    // ------------------------------------------- الدفع اليدوي

    /**
     * الدفع اليدوي من لوحة الإدارة: التحويل البنكي يُدخله الدعم، فيُفعّل
     * الرصيدُ الاشتراكَ وتصدر فاتورة — وكانت تُهمَل، فتبقى الدفعة بلا
     * invoice_id، وشرطُ syncPayment هو وجوده. هذا الاختبار يحرس الربط.
     */
    public function test_a_manual_admin_payment_is_linked_to_the_invoice_it_funded(): void
    {
        $this->seedBillingSettings();

        $plan = SubscriptionPlan::create([
            'name' => 'Manual Plan',
            'price' => 100,
            'period' => 'monthly',
            'metadata' => json_encode([]),
        ]);

        \App\Models\Subscription::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'valid_until' => now()->subDay(),
        ]);

        $this->actingAs($this->user);

        $request = \Illuminate\Http\Request::create('/admin/billing', 'POST', [
            'uuid' => $this->organization->uuid,
            'type' => 'payment',
            'method' => 'bank',
            'amount' => 1000,
        ]);

        app(\App\Services\BillingService::class)->store($request);

        $payment = BillingPayment::where('organization_id', $this->organization->id)->firstOrFail();
        $invoice = BillingInvoice::where('organization_id', $this->organization->id)->firstOrFail();

        $this->assertSame(
            (int) $invoice->id,
            (int) $payment->invoice_id,
            'الدفعة اليدوية لم تُربط بالفاتورة التي موّلتها — فلن تصل واز أبداً'
        );
    }

    /** لا فاتورة تصدر ⇒ لا ربط، ولا خطأ. */
    public function test_a_manual_payment_without_an_invoice_stays_unlinked(): void
    {
        $this->seedBillingSettings();

        \App\Models\Subscription::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'plan_id' => $this->plan()->id,
            'status' => 'active',
            'valid_until' => now()->addMonth(),
        ]);

        $this->actingAs($this->user);

        $request = \Illuminate\Http\Request::create('/admin/billing', 'POST', [
            'uuid' => $this->organization->uuid,
            'type' => 'payment',
            'method' => 'bank',
            'amount' => 1000,
        ]);

        app(\App\Services\BillingService::class)->store($request);

        $payment = BillingPayment::where('organization_id', $this->organization->id)->firstOrFail();

        $this->assertNull($payment->invoice_id);
    }

    /** الإعدادات التي يقرؤها حساب الفاتورة. */
    private function seedBillingSettings(): void
    {
        foreach (['is_tax_inclusive' => '0'] as $key => $value) {
            \App\Models\Setting::updateOrCreate(['key' => $key], ['value' => $value]);
        }
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
