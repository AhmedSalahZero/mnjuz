<?php

namespace Tests\Feature\Billing;

use App\Models\BillingTransaction;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Organization;
use App\Models\PaymentGateway;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Team;
use App\Models\User;
use App\Services\Billing\CouponRedemptionService;
use App\Services\MyFatoorah\MyFatoorahPaymentProcessor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\FlowBuilder\FlowBuilderTestCase;
use Tests\Support\FakePaymentGateway;

/**
 * كوبون «إضافة دفعة»: التاجر يكتب 1000 وكوبوناً 10% فيدفع 900، ويزيد رصيده
 * 1000 — الخصم سطرٌ منفصل يُضاف بعد تأكيد الدفع، مرّة واحدة، على كل بوّابة.
 * والكوبون مرّة لكل منشأة، وعدده يُحسب، ونطاقه (باقات / شحن) يحدّده الأدمن.
 */
class TopupCouponTest extends FlowBuilderTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        FakePaymentGateway::$calls = [];
        config(['services.myfatoorah.class' => FakePaymentGateway::class]);
        Setting::updateOrCreate(['key' => 'is_tax_inclusive'], ['value' => '1']);
        Setting::updateOrCreate(['key' => 'currency'], ['value' => 'SAR']);
    }

    private function coupon(string $code = 'EID10', float $percentage = 10, string $appliesTo = 'topup', array $attributes = []): Coupon
    {
        return Coupon::create(array_merge([
            'code' => $code,
            'name' => $code,
            'percentage' => $percentage,
            'quantity' => 100,
            'quantity_redeemed' => 0,
            'status' => 'active',
            'applies_to' => $appliesTo,
        ], $attributes));
    }

    private function asOwner(): static
    {
        return $this->actingAs($this->owner, 'user')
            ->withSession(['current_organization' => $this->organization->id]);
    }

    private function quote(string $code, $amount = 1000)
    {
        return $this->asOwner()->postJson('/billing/coupon/quote', ['amount' => $amount, 'coupon' => $code]);
    }

    private function balance(?int $organizationId = null): float
    {
        return (float) BillingTransaction::where('organization_id', $organizationId ?? $this->organization->id)->sum('amount');
    }

    private function reserve(Coupon $coupon, float $amount = 1000, ?int $organizationId = null): CouponRedemption
    {
        $service = app(CouponRedemptionService::class);

        return $service->reserveTopup($coupon, $organizationId ?? $this->organization->id, $this->owner->id, $service->quoteTopup($coupon, $amount));
    }

    /** الدفع المؤكَّد من MyFatoorah بالمسار الحقيقي (السياق كما تبنيه بعد التحقّق). */
    private function myFatoorahPaid(float $amount, ?string $couponRef, string $paymentId = 'PAY-1', ?int $organizationId = null): void
    {
        app(MyFatoorahPaymentProcessor::class)->processVerifiedPayment([
            'payment_id' => $paymentId,
            'gateway_invoice_id' => 'INV-' . $paymentId,
            'amount' => $amount,
            'currency' => 'SAR',
            'payment_method' => 'mada',
            'payment_status' => 'paid',
            'organization_id' => $organizationId ?? $this->organization->id,
            'user_id' => $this->owner->id,
            'plan_id' => null,
            'coupon_code' => null,
            'topup_coupon_ref' => $couponRef,
        ]);
    }

    // ───────────────────── المعاينة ─────────────────────

    public function test_a_10_percent_coupon_on_1000_charges_900_and_credits_100(): void
    {
        $this->coupon();

        $this->quote('EID10')->assertOk()->assertJson(['amount' => 1000, 'discount' => 100, 'charge' => 900, 'code' => 'EID10']);
    }

    public function test_a_coupon_that_covers_everything_is_refused(): void
    {
        $this->coupon('FREE', 100);

        $this->quote('FREE')->assertStatus(422)->assertJsonValidationErrors(['coupon']);
    }

    public function test_the_scope_decides_where_a_coupon_works(): void
    {
        $this->coupon('PLANS', 10, 'plans');
        $this->coupon('BOTH', 10, 'both');

        $this->quote('PLANS')->assertStatus(422)->assertJsonValidationErrors(['coupon']);
        $this->quote('BOTH')->assertOk();
    }

    /** كوبون الشحن وحده لا يُطبَّق في صفحة الباقات، و«الاثنان» يُطبَّق. */
    public function test_a_topup_only_coupon_is_refused_on_the_plan_page(): void
    {
        $plan = SubscriptionPlan::create(['uuid' => (string) Str::uuid(), 'name' => 'Pro', 'price' => 300, 'period' => 'monthly', 'status' => 'active', 'metadata' => '{}']);
        $this->coupon('TOPUP', 10, 'topup');
        $this->coupon('BOTH', 10, 'both');

        $this->asOwner()->post('/subscription/coupon/apply/' . $plan->id, ['coupon' => 'TOPUP'])->assertSessionHasErrors('coupon');
        $this->asOwner()->post('/subscription/coupon/apply/' . $plan->id, ['coupon' => 'BOTH'])->assertSessionHasNoErrors();
    }

    public function test_a_used_up_coupon_is_refused(): void
    {
        $this->coupon('DONE', 10, 'topup', ['quantity' => 5, 'quantity_redeemed' => 5]);

        $this->quote('DONE')->assertStatus(422)->assertJsonValidationErrors(['coupon']);
    }

    public function test_an_organization_uses_a_coupon_once_and_another_still_can(): void
    {
        $coupon = $this->coupon();
        app(CouponRedemptionService::class)->settleTopup((string) $this->reserve($coupon)->uuid, $this->organization->id, null, 900);

        $this->quote('EID10')->assertStatus(422)->assertJsonValidationErrors(['coupon']);

        $otherOwner = User::factory()->create(['role' => 'user']);
        $other = $this->makeOrganization($otherOwner);
        $this->actingAs($otherOwner, 'user')
            ->withSession(['current_organization' => $other->id])
            ->postJson('/billing/coupon/quote', ['amount' => 1000, 'coupon' => 'EID10'])
            ->assertOk();
    }

    // ───────────────────── /pay ─────────────────────

    public function test_pay_sends_the_discounted_amount_and_the_reference_to_the_gateway(): void
    {
        $this->coupon();

        $this->asOwner()->postJson('/pay', ['amount' => 1000, 'method' => 'myfatoorah', 'coupon' => 'EID10', 'redirect_json' => true])
            ->assertOk()
            ->assertJson(['success' => true]);

        $call = FakePaymentGateway::$calls[0];
        $this->assertEquals(900, $call['amount']);
        $this->assertNull($call['plan_id']);

        $redemption = CouponRedemption::first();
        $this->assertSame((string) $redemption->uuid, $call['context']['coupon_ref']);
        $this->assertSame(CouponRedemption::STATUS_PENDING, $redemption->status);
        $this->assertEquals(100, (float) $redemption->discount_amount);
    }

    public function test_pay_without_a_coupon_behaves_as_before(): void
    {
        $this->asOwner()->postJson('/pay', ['amount' => 750, 'method' => 'myfatoorah', 'redirect_json' => true])->assertOk();

        $this->assertEquals(750, FakePaymentGateway::$calls[0]['amount']);
        $this->assertSame([], FakePaymentGateway::$calls[0]['context']);
        $this->assertSame(0, CouponRedemption::count());
    }

    public function test_pay_refuses_a_bad_coupon_and_a_negative_amount(): void
    {
        $this->asOwner()->postJson('/pay', ['amount' => 1000, 'method' => 'myfatoorah', 'coupon' => 'NOPE', 'redirect_json' => true])
            ->assertStatus(422)->assertJsonValidationErrors(['coupon']);

        $this->asOwner()->postJson('/pay', ['amount' => -50, 'method' => 'myfatoorah', 'redirect_json' => true])
            ->assertStatus(422)->assertJsonValidationErrors(['amount']);

        $this->assertSame([], FakePaymentGateway::$calls);
    }

    // ───────────────────── التسوية ─────────────────────

    public function test_a_confirmed_myfatoorah_payment_adds_the_full_amount_to_the_balance(): void
    {
        $coupon = $this->coupon();
        $redemption = $this->reserve($coupon);

        $this->myFatoorahPaid(900, (string) $redemption->uuid);

        $this->assertEquals(1000, $this->balance());
        $this->assertSame(CouponRedemption::STATUS_REDEEMED, $redemption->fresh()->status);
        $this->assertSame(1, (int) $coupon->fresh()->quantity_redeemed);
        $this->assertSame(1, BillingTransaction::where('entity_type', 'credit')->where('description', 'like', 'Coupon discount:%')->count());
    }

    /** العودة من البوّابة والـ webhook معاً: خصمٌ واحد. */
    public function test_settling_twice_adds_the_discount_once(): void
    {
        $coupon = $this->coupon();
        $redemption = $this->reserve($coupon);
        $service = app(CouponRedemptionService::class);

        $service->settleTopup((string) $redemption->uuid, $this->organization->id, 1, 900);
        $service->settleTopup((string) $redemption->uuid, $this->organization->id, 2, 900);

        $this->assertEquals(100, $this->balance());
        $this->assertSame(1, (int) $coupon->fresh()->quantity_redeemed);
    }

    public function test_another_organizations_reference_is_ignored(): void
    {
        $coupon = $this->coupon();
        $redemption = $this->reserve($coupon);
        $other = $this->makeOrganization(User::factory()->create(['role' => 'user']));

        app(CouponRedemptionService::class)->settleTopup((string) $redemption->uuid, $other->id, 1, 900);

        $this->assertEquals(0, $this->balance($other->id));
        $this->assertSame(CouponRedemption::STATUS_PENDING, $redemption->fresh()->status);
    }

    public function test_a_different_paid_amount_gets_no_discount(): void
    {
        $coupon = $this->coupon();
        $redemption = $this->reserve($coupon);

        $this->myFatoorahPaid(500, (string) $redemption->uuid);

        $this->assertEquals(500, $this->balance());
        $this->assertSame(CouponRedemption::STATUS_PENDING, $redemption->fresh()->status);
        $this->assertSame(0, (int) $coupon->fresh()->quantity_redeemed);
    }

    /** اشتراك منتهٍ: يُجدَّد من 900 المدفوعة + 100 الخصم. */
    public function test_an_expired_subscription_renews_from_the_full_balance(): void
    {
        $plan = SubscriptionPlan::create(['uuid' => (string) Str::uuid(), 'name' => 'Pro', 'price' => 1000, 'period' => 'monthly', 'status' => 'active', 'metadata' => '{}']);
        Subscription::where('organization_id', $this->organization->id)->update([
            'plan_id' => $plan->id,
            'status' => 'active',
            'valid_until' => now()->subDay(),
        ]);
        \App\Support\RequestLookupCache::forget('subscription:');

        $coupon = $this->coupon();
        $redemption = $this->reserve($coupon);

        $this->myFatoorahPaid(900, (string) $redemption->uuid);

        $this->assertTrue(Subscription::where('organization_id', $this->organization->id)->first()->valid_until > now());
        $this->assertSame(1, \App\Models\BillingInvoice::where('organization_id', $this->organization->id)->count());
    }

    public function test_a_stripe_webhook_settles_the_coupon(): void
    {
        PaymentGateway::create(['name' => 'Stripe', 'metadata' => json_encode(['secret_key' => 'sk_test_x', 'webhook_secret' => 'whsec_test'])]);
        $coupon = $this->coupon();
        $redemption = $this->reserve($coupon);

        $payload = json_encode([
            'id' => 'evt_1',
            'object' => 'event',
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'object' => 'checkout.session',
                'mode' => 'payment',
                'payment_intent' => 'pi_1',
                'customer' => 'cus_1',
                'metadata' => [
                    'organization_id' => (string) $this->organization->id,
                    'user_id' => (string) $this->owner->id,
                    'amount' => '900',
                    'plan_id' => null,
                    'coupon_ref' => (string) $redemption->uuid,
                ],
            ]],
        ]);
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp . '.' . $payload, 'whsec_test');

        $request = Request::create('/webhook/stripe', 'POST', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        (new \App\Services\StripeService())->handleWebhook($request);

        $this->assertEquals(1000, $this->balance());
        $this->assertSame(CouponRedemption::STATUS_REDEEMED, $redemption->fresh()->status);
    }

    public function test_a_razorpay_webhook_settles_the_coupon(): void
    {
        Setting::updateOrCreate(['key' => 'razorpay_webhook_secret'], ['value' => 'rzp_secret']);
        $coupon = $this->coupon();
        $redemption = $this->reserve($coupon);

        $payload = json_encode(['event' => 'payment.authorized', 'payload' => ['payment' => ['entity' => [
            'id' => 'pay_rzp_1',
            'amount' => 90000,
            'notes' => [
                'organization_id' => $this->organization->id,
                'user_id' => $this->owner->id,
                'plan_id' => null,
                'coupon_ref' => (string) $redemption->uuid,
            ],
        ]]]]);

        $request = Request::create('/webhook/razorpay', 'POST', [], [], [], [
            'HTTP_X_RAZORPAY_SIGNATURE' => hash_hmac('sha256', $payload, 'rzp_secret'),
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        (new \Modules\Razorpay\Controllers\ProcessPayment())->handleWebhook($request);

        $this->assertEquals(1000, $this->balance());
        $this->assertSame(CouponRedemption::STATUS_REDEEMED, $redemption->fresh()->status);
    }

    public function test_a_flutterwave_payment_settles_the_coupon(): void
    {
        PaymentGateway::create(['name' => 'Flutterwave', 'metadata' => json_encode(['public_key' => 'pk', 'secret_key' => 'sk'])]);
        $coupon = $this->coupon();
        $redemption = $this->reserve($coupon);
        $organizationId = $this->organization->id;
        $userId = $this->owner->id;
        $ref = (string) $redemption->uuid;

        // التحقّق من Flutterwave (شبكة) مستبدَل، وما بعده المسار الحقيقي.
        $service = new class($organizationId, $userId, $ref) extends \App\Services\FlutterwaveService {
            public function __construct(private int $org, private int $user, private string $ref)
            {
                parent::__construct();
            }

            public function verifyTransaction($transactionId)
            {
                return (object) ['success' => true, 'data' => (object) [
                    'status' => 'success',
                    'data' => (object) [
                        'flw_ref' => 'FLW-1',
                        'amount' => 900,
                        'meta' => (object) ['organization_id' => $this->org, 'user_id' => $this->user, 'coupon_ref' => $this->ref],
                    ],
                ]];
            }
        };

        $service->updateSubscription('tx-1');

        $this->assertEquals(1000, $this->balance());
        $this->assertSame(CouponRedemption::STATUS_REDEEMED, $redemption->fresh()->status);
    }
}
