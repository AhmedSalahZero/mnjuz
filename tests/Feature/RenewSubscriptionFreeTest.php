<?php

namespace Tests\Feature;

use App\Models\BillingInvoice;
use App\Models\BillingPayment;
use App\Models\BillingTransaction;
use App\Models\Organization;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\SubscriptionService;
use App\Services\WazBusinessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * التجديد بلا مقابل: يفتح الاشتراك دون أن يُنشئ مالاً في أي اتجاه.
 *
 * الخطر ليس في التاريخ بل في ما يبنى عليه: حساب الترقية يردّ جزء آخر فاتورة
 * رصيداً بحسب المدة المتبقية، فتمديد مجاني بلا فاتورة صفرية يصير رصيداً حقيقياً.
 */
class RenewSubscriptionFreeTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;
    private SubscriptionPlan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::updateOrCreate(['key' => 'is_tax_inclusive'], ['value' => '1']);

        $this->travelTo('2026-11-07 12:00:00');

        $owner = User::factory()->create(['role' => 'user']);
        $this->organization = Organization::factory()->create([
            'created_by' => $owner->id,
            'waz_company_id' => 99,
            'metadata' => json_encode(['timezone' => 'Asia/Riyadh']),
        ]);

        $this->plan = SubscriptionPlan::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Business Pro',
            'price' => 1300.00,
            'period' => 'monthly',
            'status' => 'active',
            'metadata' => json_encode(['setup_fee' => 230]),
        ]);
    }

    private function subscription(array $attributes = []): void
    {
        Subscription::create($attributes + [
            'organization_id' => $this->organization->id,
            'plan_id' => $this->plan->id,
            'status' => 'active',
            'start_date' => '2025-10-28 17:18:42',
            'valid_until' => '2026-10-28 17:18:42',
        ]);
    }

    /** إدراج مباشر كي لا يُطلق مراقب مزامنة واز. */
    private function paidInvoice(float $total): void
    {
        DB::table('billing_invoices')->insert([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'plan_id' => $this->plan->id,
            'subtotal' => $total,
            'tax_type' => 'exclusive',
            'total' => $total,
        ]);
    }

    private function renew(array $options = [])
    {
        return $this->artisan('subscriptions:renew-free', ['organization' => $this->organization->id] + $options);
    }

    private function row(): object
    {
        return DB::table('subscriptions')->where('organization_id', $this->organization->id)->first();
    }

    private function details(): array
    {
        return SubscriptionService::calculateSubscriptionBillingDetails($this->organization->id, $this->plan->id);
    }

    public function test_an_expired_subscription_reopens_for_one_plan_period(): void
    {
        $this->subscription();

        $this->renew()->assertSuccessful();

        $row = $this->row();
        $this->assertSame('2026-11-07 12:00:00', $row->start_date);
        $this->assertSame('2026-12-07 12:00:00', $row->valid_until);
        $this->assertSame('active', $row->status);
        $this->assertTrue(SubscriptionService::isSubscriptionActive((string) $this->organization->id));
    }

    public function test_a_yearly_plan_defaults_to_twelve_months(): void
    {
        $this->plan->update(['period' => 'yearly']);
        $this->subscription();

        $this->renew()->assertSuccessful();

        $this->assertSame('2027-11-07 12:00:00', $this->row()->valid_until);
    }

    public function test_months_option_overrides_the_plan_period(): void
    {
        $this->subscription();

        $this->renew(['--months' => 12])->assertSuccessful();

        $this->assertSame('2027-11-07 12:00:00', $this->row()->valid_until);
    }

    public function test_no_money_moves(): void
    {
        $this->subscription();
        $this->paidInvoice(12660);

        $this->renew()->assertSuccessful();

        $this->assertSame(0, BillingPayment::count());
        $this->assertSame(0, BillingTransaction::where('organization_id', $this->organization->id)->count());
    }

    public function test_free_period_does_not_become_credit_on_upgrade(): void
    {
        $this->subscription();
        $this->paidInvoice(12660);

        $this->renew(['--months' => 12])->assertSuccessful();

        $details = $this->details();
        $this->assertSame('0.00', $details['credit']['new']);
        $this->assertSame('1,300.00', $details['amountDue']);
    }

    public function test_zero_invoice_issues_nothing_on_waz(): void
    {
        $this->subscription();
        $this->paidInvoice(12660);
        config(['waz.token' => 'token', 'waz.base_url' => 'https://waz.test']);

        $this->mock(WazBusinessService::class, function ($mock) {
            $mock->shouldIgnoreMissing();
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldNotReceive('createInvoice');
        });

        $this->renew()->assertSuccessful();

        $this->assertSame(2, BillingInvoice::where('organization_id', $this->organization->id)->count());
    }

    public function test_never_paid_organization_keeps_its_setup_fee(): void
    {
        $this->subscription(['status' => 'trial']);

        $this->renew()->assertSuccessful();

        $this->assertSame('active', $this->row()->status);
        $this->assertSame(0, BillingInvoice::where('organization_id', $this->organization->id)->count());
        $this->assertTrue($this->details()['isFirstSubscription']);
    }

    public function test_a_running_subscription_keeps_its_remaining_days(): void
    {
        $this->subscription([
            'start_date' => '2026-11-01 10:00:00',
            'valid_until' => '2026-12-01 10:00:00',
        ]);

        $this->renew()->assertSuccessful();

        // الساعة 10:00 كما هي: توقيت المنشأة (+3) لا يصل إلى القيمة المخزَّنة.
        $row = $this->row();
        $this->assertSame('2026-11-01 10:00:00', $row->start_date);
        $this->assertSame('2027-01-01 10:00:00', $row->valid_until);
    }

    public function test_dry_run_changes_nothing(): void
    {
        $this->subscription();
        $this->paidInvoice(12660);

        $this->renew(['--dry-run' => true])->assertSuccessful();

        $this->assertSame('2026-10-28 17:18:42', $this->row()->valid_until);
        $this->assertSame(1, BillingInvoice::where('organization_id', $this->organization->id)->count());
    }

    public function test_organization_without_a_plan_is_refused(): void
    {
        $this->subscription(['plan_id' => null]);

        $this->renew()->assertFailed();

        $this->assertSame('2026-10-28 17:18:42', $this->row()->valid_until);
    }

    public function test_unknown_organization_is_refused(): void
    {
        $this->artisan('subscriptions:renew-free', ['organization' => 999999])->assertFailed();
    }

    public function test_invalid_months_are_refused(): void
    {
        $this->subscription();

        $this->renew(['--months' => 0])->assertFailed();
        $this->renew(['--months' => 'abc'])->assertFailed();

        $this->assertSame('2026-10-28 17:18:42', $this->row()->valid_until);
    }
}
