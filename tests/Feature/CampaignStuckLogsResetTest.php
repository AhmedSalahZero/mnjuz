<?php

namespace Tests\Feature;

use App\Jobs\ProcessCampaignMessagesJob;
use App\Models\Campaign;
use App\Models\CampaignLog;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * تصفير سجلات الحملات العالقة على «ongoing» (عامل سقط في منتصف الإرسال).
 *
 * كان UPDATE بلا فهرس كل 5 ثوانٍ: يمسح campaign_logs كلّه ويقفل كل صفّ يمرّ
 * عليه حتى ينتهي، فكان إرسال الحملات وتحديث حالاتها ينتظرانه. ظهر جارياً في
 * خُمس عيّنات processlist على الإنتاج (11/11/2026).
 */
class CampaignStuckLogsResetTest extends TestCase
{
    use RefreshDatabase;

    private Campaign $campaign;
    private Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Cache::forget(ProcessCampaignMessagesJob::STUCK_CHECK_KEY);

        $user = User::factory()->create(['role' => 'user']);
        $organization = Organization::factory()->create(['created_by' => $user->id]);
        $this->contact = Contact::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $organization->id,
            'first_name' => 'عميل',
            'phone' => '+966500000301',
            'created_by' => $user->id,
        ]);
        $this->campaign = Campaign::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $organization->id,
            'name' => 'حملة',
            'status' => 'completed',
            'template_id' => 0,
            'contact_group_id' => 0,
            'metadata' => '{}',
            'created_by' => $user->id,
        ]);
    }

    private function log(string $status, int $minutesAgo): CampaignLog
    {
        $log = CampaignLog::create(['campaign_id' => $this->campaign->id, 'contact_id' => $this->contact->id, 'status' => $status]);
        DB::table('campaign_logs')->where('id', $log->id)->update(['updated_at' => now()->subMinutes($minutesAgo)]);

        return $log;
    }

    private function runJob(): array
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        (new ProcessCampaignMessagesJob())->handle();

        return $queries;
    }

    public function test_only_logs_stuck_past_the_threshold_are_failed(): void
    {
        $stuck = $this->log('ongoing', 20);
        $sending = $this->log('ongoing', 5);
        $oldSuccess = $this->log('success', 60);
        $oldPending = $this->log('pending', 60);

        $this->runJob();

        $this->assertSame('failed', $stuck->fresh()->status);
        $this->assertSame('ongoing', $sending->fresh()->status, 'still within its send window');
        $this->assertSame('success', $oldSuccess->fresh()->status);
        $this->assertSame('pending', $oldPending->fresh()->status);
    }

    /** التحديث يقفل الصفوف العالقة بمعرّفاتها، لا كل صفّ يمرّ عليه الشرط. */
    public function test_the_update_targets_stuck_rows_by_id(): void
    {
        $this->log('ongoing', 20);

        $updates = collect($this->runJob())->filter(fn ($sql) => str_starts_with($sql, 'update `campaign_logs`'));

        $this->assertCount(1, $updates);
        $this->assertStringContainsString('where `id` in (', $updates->first());
    }

    public function test_nothing_stuck_means_no_update_at_all(): void
    {
        $this->log('ongoing', 5);

        $updates = collect($this->runJob())->filter(fn ($sql) => str_starts_with($sql, 'update `campaign_logs`'));

        $this->assertCount(0, $updates);
    }

    /** الجدولة كل 5 ثوانٍ، والفحص مرّة في الدقيقة. */
    public function test_the_check_runs_at_most_once_a_minute(): void
    {
        $this->runJob();
        $late = $this->log('ongoing', 20);

        $this->runJob();
        $this->assertSame('ongoing', $late->fresh()->status, 'checked again within the minute');

        $this->travel(61)->seconds();
        $this->runJob();
        $this->assertSame('failed', $late->fresh()->status);
    }
}
