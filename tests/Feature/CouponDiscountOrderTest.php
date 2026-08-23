<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ترتيب تطبيق الكوبون، ومصدر الكوبون.
 *
 * عطلٌ وقع على الإنتاج: عميلة دفعت ٤٩٦٫٨٠ ر.س وبقيت في فترة التجربة. الدفع
 * نجح، والاشتراك لم يُفعَّل، ولا فاتورة صدرت، ولا شيء في السجلّ.
 *
 * سببان متراكبان:
 *
 *   ١. الكوبون كان يُقرأ من الجلسة. السعر يُحسب مرّتين — عند التحويل إلى
 *      البوّابة وعند العودة منها — والعودة قد تصل بلا جلسة. فيُحصَّل المخفَّض
 *      ويُطلب الكامل، ويسقط التفعيل على فارقٍ لا وجود له.
 *
 *   ٢. الخصم كان يُطبَّق على **ما تبقّى بعد طرح الرصيد** لا على السعر. فقيمة
 *      الخصم تتغيّر بتغيّر رصيد العميل، وتُصدَر الفاتورة بخصمٍ يخالف ما حُصِّل.
 *
 * والدليل على أن الترتيب الصحيح هو المقصود أصلاً: إصدار الفاتورة يحسب
 * `chargedAmount = netAmount - discount` — أي الخصم على السعر. التصحيح يوفّق
 * بين الحسابين بدل أن يُغيّر أحدهما.
 */
class CouponDiscountOrderTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;
    private SubscriptionPlan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        // الحساب يقرأ الإعداد بلا حارس null؛ بذره هنا لا تعديل الإنتاج.
        \App\Models\Setting::updateOrCreate(['key' => 'is_tax_inclusive'], ['value' => '1']);

        $owner = User::factory()->create(['role' => 'user']);
        $this->organization = Organization::factory()->create(['created_by' => $owner->id]);

        $this->plan = SubscriptionPlan::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Pro',
            'price' => 322.00,
            'period' => 'monthly',
            'status' => 'active',
            'metadata' => json_encode(['setup_fee' => 230]),
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

    private function coupon(string $code, int $percentage): Coupon
    {
        return Coupon::create([
            'code' => $code,
            'name' => $code,
            'percentage' => $percentage,
            'quantity' => 100,
            'quantity_redeemed' => 0,
            'status' => 'active',
        ]);
    }

    /** رصيد في حساب المنظّمة عبر حركة فوترة. */
    private function credit(float $amount): void
    {
        DB::table('billing_transactions')->insert([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'entity_type' => 'payment',
            'entity_id' => 0,
            'description' => 'رصيد اختبار',
            'amount' => $amount,
            'created_by' => 0,
        ]);
    }

    /** @return array<string, mixed> */
    private function details(?string $coupon = null): array
    {
        return SubscriptionService::calculateSubscriptionBillingDetails(
            (int) $this->organization->id,
            $this->plan->id,
            $coupon
        );
    }

    private function money(array $details, string $key): float
    {
        // 'coupon' كتلة لا رقم: قيمتها في discount، وغيابها يعني لا خصم.
        $value = $key === 'coupon'
            ? ($details['coupon']['discount'] ?? 0)
            : ($details[$key] ?? 0);

        return (float) str_replace(',', '', (string) $value);
    }

    // ------------------------------------------- ترتيب التطبيق

    /** بلا رصيد: الخصم على السعر كاملاً — الحالة التي كانت تعمل صدفةً. */
    public function test_without_a_balance_the_discount_applies_to_the_full_price(): void
    {
        $this->coupon('ten', 10);

        $details = $this->details('ten');

        $this->assertSame(552.00, $this->money($details, 'netAmount'));
        $this->assertSame(55.20, $this->money($details, 'coupon'), 'الخصم ليس ١٠٪ من ٥٥٢');
        $this->assertSame(496.80, $this->money($details, 'amountDue'));
    }

    /**
     * جوهر العطل: رصيدٌ جزئي لا يجوز أن يُقلّص الخصم.
     *
     * قبل التصحيح كان الخصم يُحسب على ٥٥٫٢٠ المتبقّية فيصير ٥٫٥٢ — فيبقى على
     * العميل ٤٩٫٦٨ رغم أنه سدّد ما اتُّفق عليه.
     */
    public function test_a_partial_balance_does_not_shrink_the_discount(): void
    {
        $this->coupon('ten', 10);
        $this->credit(496.80);

        $details = $this->details('ten');

        $this->assertSame(55.20, $this->money($details, 'coupon'), 'قيمة الخصم تغيّرت بتغيّر الرصيد.');
        $this->assertSame(0.0, $this->money($details, 'amountDue'), 'بقي مستحقّ رغم سداد المتّفق عليه.');
    }

    /** والخصم يُسجَّل حتى حين يُغطّي الرصيد كل شيء — الفاتورة توثّقه. */
    public function test_the_discount_is_recorded_even_when_the_balance_covers_everything(): void
    {
        $this->coupon('ten', 10);
        $this->credit(1000);

        $details = $this->details('ten');

        $this->assertSame(0.0, $this->money($details, 'amountDue'));
        $this->assertSame(55.20, $this->money($details, 'coupon'), 'خصمٌ حُصِّل ولم يُوثَّق في الفاتورة.');
    }

    /** ورصيد أكبر من السعر لا يُنتج مستحقّاً سالباً. */
    public function test_an_excess_balance_never_produces_a_negative_due(): void
    {
        $this->credit(5000);

        $this->assertSame(0.0, $this->money($this->details(), 'amountDue'));
    }

    // --------------------------------------------- مصدر الكوبون

    /**
     * الكوبون الصريح يعمل بلا جلسة — وهذا ما يجعل التفعيل بعد العودة من
     * البوّابة يرى ما رآه الدفع.
     */
    public function test_an_explicit_coupon_works_without_a_session(): void
    {
        $this->coupon('ten', 10);
        session()->forget('applied_coupon');

        $this->assertSame(496.80, $this->money($this->details('ten'), 'amountDue'));
    }

    /** وبلا كوبون صريح ولا جلسة: السعر كاملاً. */
    public function test_no_coupon_means_no_discount(): void
    {
        $this->coupon('ten', 10);

        $details = $this->details();

        $this->assertSame(0.0, $this->money($details, 'coupon'));
        $this->assertSame(552.00, $this->money($details, 'amountDue'));
    }

    /** والجلسة تبقى مقبولة لمن يشتري من اللوحة مباشرةً. */
    public function test_the_session_coupon_still_applies(): void
    {
        $this->coupon('ten', 10);
        session(['applied_coupon' => 'ten']);

        $this->assertSame(496.80, $this->money($this->details(), 'amountDue'));
    }

    /** والصريح يسبق الجلسة عند اختلافهما. */
    public function test_the_explicit_coupon_wins_over_the_session(): void
    {
        $this->coupon('ten', 10);
        $this->coupon('half', 50);
        session(['applied_coupon' => 'ten']);

        $this->assertSame(276.00, $this->money($this->details('half'), 'coupon'));
    }

    // ------------------------------------------------- الحدود

    public function test_an_inactive_coupon_is_ignored(): void
    {
        $c = $this->coupon('dead', 10);
        $c->update(['status' => 'inactive']);

        $this->assertSame(552.00, $this->money($this->details('dead'), 'amountDue'));
    }

    public function test_an_exhausted_coupon_is_ignored(): void
    {
        $c = $this->coupon('used', 10);
        $c->update(['quantity_redeemed' => $c->quantity]);

        $this->assertSame(552.00, $this->money($this->details('used'), 'amountDue'));
    }

    /** وخصم ١٠٠٪ يُنزل المستحقّ إلى صفر لا إلى سالب. */
    public function test_a_full_discount_lands_on_zero(): void
    {
        $this->coupon('all', 100);

        $details = $this->details('all');

        $this->assertSame(0.0, $this->money($details, 'amountDue'));
        $this->assertSame(552.00, $this->money($details, 'coupon'));
    }
}
