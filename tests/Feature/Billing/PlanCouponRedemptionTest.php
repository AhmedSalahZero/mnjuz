<?php

namespace Tests\Feature\Billing;

use App\Http\Requests\StoreCoupon;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Organization;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\MyFatoorah\MyFatoorahPaymentProcessor;
use App\Services\MyFatoorahService;
use App\Services\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * كوبونات الباقات بعد عدّ الاستخدام: مرّة لكل منشأة، والعدد يزيد مع كل
 * فاتورة بكوبون (كان quantity_redeemed لا يزيد في أيّ مسار حيّ). ونطاق
 * الكوبون، وإصلاحا MyFatoorah: الـ webhook يرى كوبون الشراء، والشحن لا يحمل
 * كوبون صفحة الباقات.
 */
class PlanCouponRedemptionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;
    private SubscriptionPlan $plan;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::updateOrCreate(['key' => 'is_tax_inclusive'], ['value' => '1']);
        Setting::updateOrCreate(['key' => 'currency'], ['value' => 'SAR']);

        $this->owner = User::factory()->create(['role' => 'user']);
        $this->organization = Organization::factory()->create(['created_by' => $this->owner->id]);

        $this->plan = SubscriptionPlan::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Pro',
            'price' => 300.00,
            'period' => 'monthly',
            'status' => 'active',
            'metadata' => '{}',
        ]);

        Subscription::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'status' => 'trial',
            'start_date' => now()->subDay(),
            'valid_until' => now()->addDays(2),
        ]);

        session()->forget('applied_coupon');
    }

    private function coupon(string $code, string $appliesTo = 'plans'): Coupon
    {
        return Coupon::create([
            'code' => $code,
            'name' => $code,
            'percentage' => 20,
            'quantity' => 10,
            'quantity_redeemed' => 0,
            'status' => 'active',
            'applies_to' => $appliesTo,
        ]);
    }

    private function details(?string $coupon): array
    {
        return SubscriptionService::calculateSubscriptionBillingDetails($this->organization->id, $this->plan->id, $coupon);
    }

    public function test_an_invoice_with_a_coupon_records_the_redemption_and_counts_it(): void
    {
        $coupon = $this->coupon('SAVE20');

        $invoice = SubscriptionService::createBillingInvoice($this->details('SAVE20'), $this->organization->id, $this->plan->id, $this->owner->id);

        $this->assertSame($coupon->id, (int) $invoice->coupon_id);
        $this->assertSame(1, (int) $coupon->fresh()->quantity_redeemed);

        $redemption = CouponRedemption::first();
        $this->assertSame(CouponRedemption::CONTEXT_PLAN, $redemption->context);
        $this->assertSame(CouponRedemption::STATUS_REDEEMED, $redemption->status);
        $this->assertSame($invoice->id, (int) $redemption->billing_invoice_id);
    }

    /** مرّة لكل منشأة: الاستعمال الثاني لا يخصم. */
    public function test_the_same_organization_cannot_use_the_coupon_again(): void
    {
        $this->coupon('SAVE20');
        SubscriptionService::createBillingInvoice($this->details('SAVE20'), $this->organization->id, $this->plan->id, $this->owner->id);

        $this->assertArrayNotHasKey('id', $this->details('SAVE20')['coupon'] ?? []);
    }

    /** كوبون «إضافة دفعة» وحدها لا يخصم الباقة، و«الاثنان» يخصم. */
    public function test_a_topup_only_coupon_does_not_discount_a_plan(): void
    {
        $this->coupon('TOPUP', 'topup');
        $this->coupon('BOTH', 'both');

        $this->assertEmpty($this->details('TOPUP')['coupon'] ?? null);
        $this->assertNotEmpty($this->details('BOTH')['coupon'] ?? null);
    }

    /** الكوبونات القائمة قبل الحقل للباقات وحدها. */
    public function test_existing_coupons_default_to_plans(): void
    {
        $coupon = Coupon::create(['code' => 'OLD', 'name' => 'OLD', 'percentage' => 10, 'quantity' => 5, 'status' => 'active']);

        $this->assertSame('plans', $coupon->fresh()->applies_to);
    }

    /** نسبة سالبة ترفع السعر، وأكثر من 100 لا معنى لها. */
    public function test_the_admin_form_bounds_the_percentage_and_the_scope(): void
    {
        $request = new StoreCoupon();
        $request->setMethod('POST');
        $rules = $request->rules();

        foreach ([-10, 0, 150] as $bad) {
            $this->assertTrue(Validator::make(['name' => 'x', 'code' => 'C' . $bad, 'percentage' => $bad, 'quantity' => 1], $rules)->fails(), (string) $bad);
        }

        $this->assertFalse(Validator::make(['name' => 'x', 'code' => 'OK', 'percentage' => 15, 'quantity' => 1, 'applies_to' => 'both'], $rules)->fails());
        $this->assertTrue(Validator::make(['name' => 'x', 'code' => 'OK2', 'percentage' => 15, 'quantity' => 1, 'applies_to' => 'everything'], $rules)->fails());
    }

    /**
     * الـ webhook بلا جلسة: السياق المُرجَع من MyFatoorah يحمل كوبون الشراء.
     * كان يُقرأ ثم يسقط من المصفوفة المُرجَعة، فيُحسب السعر كاملاً ويسقط التفعيل.
     */
    public function test_the_myfatoorah_context_keeps_the_purchase_coupon(): void
    {
        $service = new MyFatoorahService();
        $method = new \ReflectionMethod($service, 'buildContextFromStatusResponse');
        $method->setAccessible(true);

        $context = $method->invoke($service, (object) [
            'InvoiceId' => 77,
            'InvoiceStatus' => 'Paid',
            'CustomerReference' => 'org_user_' . $this->plan->id,
            'UserDefinedField' => json_encode([
                'organization_id' => $this->organization->id,
                'user_id' => $this->owner->id,
                'plan_id' => (string) $this->plan->id,
                'requested_amount' => 240,
                'requested_currency' => 'SAR',
                'coupon_code' => 'SAVE20',
                'topup_coupon_ref' => null,
            ]),
            'InvoiceTransactions' => [(object) ['PaymentId' => 'P1', 'PaymentGateway' => 'mada']],
        ], 'P1');

        $this->assertSame('SAVE20', $context['coupon_code']);
        $this->assertArrayHasKey('topup_coupon_ref', $context);
    }

    /** شحن الرصيد لا يحمل كوبون صفحة الباقات، وشراء الباقة يحمله. */
    public function test_a_myfatoorah_top_up_no_longer_carries_the_plan_page_coupon(): void
    {
        session(['applied_coupon' => 'SAVE20']);
        $processor = app(MyFatoorahPaymentProcessor::class);

        $topup = json_decode($processor->buildSendPaymentPayload(900, $this->organization->id, $this->owner->id, null, ['coupon_ref' => 'ref-1'])['UserDefinedField'], true);
        $this->assertNull($topup['coupon_code']);
        $this->assertSame('ref-1', $topup['topup_coupon_ref']);

        $purchase = json_decode($processor->buildSendPaymentPayload(240, $this->organization->id, $this->owner->id, $this->plan->id)['UserDefinedField'], true);
        $this->assertSame('SAVE20', $purchase['coupon_code']);
    }
}
