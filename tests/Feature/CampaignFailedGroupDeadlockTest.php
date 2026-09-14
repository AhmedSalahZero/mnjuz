<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CampaignLog;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\User;
use App\Services\CampaignRetryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * نقل جهة الاتصال إلى مجموعة الفاشلين.
 *
 * كانت المعاملة تحذف عضويات العميل كلّها ثم تُدرج صفّ مجموعة الفاشلين ولو
 * كان فيها أصلاً. وحملةٌ تفشل لألف رقم تُشغّل ألف معاملة متوازية، كلّها
 * تحذف وتُدرج في نطاق المفتاح نفسه من فهرس contact_group_id — فتتقاطع
 * أقفالها ويقع Deadlock 1213. رُصد في الإنتاج على المجموعة 10049.
 */
class CampaignFailedGroupDeadlockTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;
    private Campaign $campaign;
    private int $failedGroupId;
    private string $failedGroupUuid;
    private CampaignRetryService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create(['role' => 'user']);

        $this->failedGroupUuid = (string) Str::uuid();

        $this->organization = Organization::factory()->create([
            'created_by' => $user->id,
            'metadata' => json_encode([
                'campaigns' => [
                    'enable_resend' => true,
                    'resend_intervals' => [5],
                    'move_failed_contacts_to_group' => true,
                    'failed_campaign_group' => $this->failedGroupUuid,
                ],
            ]),
        ]);

        $this->failedGroupId = DB::table('contact_groups')->insertGetId([
            'uuid' => $this->failedGroupUuid,
            'organization_id' => $this->organization->id,
            'name' => 'الفاشلون',
            'created_by' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->campaign = Campaign::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'name' => 'حملة',
            'status' => 'completed',
            'template_id' => 0,
            'contact_group_id' => $this->failedGroupId,
            'metadata' => '{}',
            'created_by' => $user->id,
        ]);

        $this->service = new CampaignRetryService();
    }

    private function group(string $name): int
    {
        return DB::table('contact_groups')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'name' => $name,
            'created_by' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function contactInGroups(array $groupIds): Contact
    {
        $contact = Contact::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'phone' => '+9665' . random_int(10000000, 99999999),
            'created_by' => 0,
        ]);

        foreach ($groupIds as $groupId) {
            DB::table('contact_contact_group')->insert([
                'contact_id' => $contact->id,
                'contact_group_id' => $groupId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $contact;
    }

    private function log(Contact $contact): CampaignLog
    {
        return CampaignLog::create([
            'campaign_id' => $this->campaign->id,
            'contact_id' => $contact->id,
            'status' => 'failed',
        ]);
    }

    private function groupsOf(Contact $contact): array
    {
        return DB::table('contact_contact_group')
            ->where('contact_id', $contact->id)
            ->pluck('contact_group_id')
            ->map(fn ($id) => (int) $id)
            ->sort()
            ->values()
            ->all();
    }

    // ------------------------------------------------- السلوك الأساسي

    /** العميل يُنقل: يخرج من مجموعاته ويدخل مجموعة الفاشلين. */
    public function test_the_contact_is_moved_to_the_failed_group(): void
    {
        $other = $this->group('عملاء');
        $contact = $this->contactInGroups([$other]);

        $this->service->moveContactToFailedGroup($this->log($contact));

        $this->assertSame([$this->failedGroupId], $this->groupsOf($contact));
    }

    public function test_a_contact_without_groups_is_added(): void
    {
        $contact = $this->contactInGroups([]);

        $this->service->moveContactToFailedGroup($this->log($contact));

        $this->assertSame([$this->failedGroupId], $this->groupsOf($contact));
    }

    // ------------------------------------------------- جذر الـ deadlock

    /**
     * العضوية القائمة لا تُمسّ.
     *
     * كان الصفّ يُحذف ويُدرج من جديد في كل مرّة، وهو ما يُنتج الكتابة
     * المتقاطعة في فهرس المجموعة. نتحقّق بمعرّف الصفّ: بقاؤه يعني أنه لم
     * يُحذف ويُعد إدراجه.
     */
    public function test_an_existing_membership_row_is_left_untouched(): void
    {
        $contact = $this->contactInGroups([$this->failedGroupId]);

        $rowId = DB::table('contact_contact_group')
            ->where('contact_id', $contact->id)
            ->value('id');

        $this->service->moveContactToFailedGroup($this->log($contact));

        $this->assertSame(
            $rowId,
            DB::table('contact_contact_group')->where('contact_id', $contact->id)->value('id'),
            'الصفّ حُذف وأُعيد إدراجه — وهو مصدر تقاطع الأقفال'
        );
    }

    /** والتكرار لا يُنتج صفوفاً مكرّرة. */
    public function test_repeating_the_move_is_idempotent(): void
    {
        $contact = $this->contactInGroups([$this->group('عملاء')]);
        $log = $this->log($contact);

        $this->service->moveContactToFailedGroup($log);
        $this->service->moveContactToFailedGroup($log);
        $this->service->moveContactToFailedGroup($log);

        $this->assertSame(
            1,
            DB::table('contact_contact_group')->where('contact_id', $contact->id)->count()
        );
    }

    /** ولا تُمسّ عضويات عميل آخر في المجموعة نفسها. */
    public function test_other_contacts_in_the_same_group_are_untouched(): void
    {
        $neighbour = $this->contactInGroups([$this->failedGroupId]);
        $contact = $this->contactInGroups([$this->group('عملاء')]);

        $before = DB::table('contact_contact_group')->where('contact_id', $neighbour->id)->value('id');

        $this->service->moveContactToFailedGroup($this->log($contact));

        $this->assertSame(
            $before,
            DB::table('contact_contact_group')->where('contact_id', $neighbour->id)->value('id')
        );
    }

    // ------------------------------------------------- الحراسة

    /** المعاملة تُعاد عند التقاطع — وهو ما توصي به MySQL في نصّ الخطأ. */
    public function test_the_transaction_is_retried_on_deadlock(): void
    {
        $source = file_get_contents(base_path('app/Services/CampaignRetryService.php'));

        $this->assertMatchesRegularExpression(
            '/DB::transaction\(function \(\) use \(\$log, \$failedGroupId\).*?\}, 5\);/s',
            $source,
            'بلا عدد محاولات، أوّل تقاطع يُسقط الوظيفة'
        );
    }

    /** والحذف مقصور على المجموعات الأخرى. */
    public function test_the_delete_is_narrowed(): void
    {
        $source = file_get_contents(base_path('app/Services/CampaignRetryService.php'));

        $this->assertStringContainsString(
            "->where('contact_group_id', '!=', \$failedGroupId)",
            $source
        );
    }

    // ------------------------------------------------- الإعدادات

    /** الميزة معطّلة ⇒ لا نقل. */
    public function test_nothing_happens_when_the_feature_is_off(): void
    {
        $this->organization->forceFill([
            'metadata' => json_encode(['campaigns' => ['move_failed_contacts_to_group' => false]]),
        ])->save();

        $other = $this->group('عملاء');
        $contact = $this->contactInGroups([$other]);

        $this->service->moveContactToFailedGroup($this->log($contact));

        $this->assertSame([$other], $this->groupsOf($contact));
    }

    /** المجموعة غير موجودة ⇒ لا يُمسّ شيء. */
    public function test_a_missing_group_leaves_the_contact_alone(): void
    {
        $this->organization->forceFill([
            'metadata' => json_encode(['campaigns' => [
                'move_failed_contacts_to_group' => true,
                'failed_campaign_group' => (string) Str::uuid(),
            ]]),
        ])->save();

        $other = $this->group('عملاء');
        $contact = $this->contactInGroups([$other]);

        $this->service->moveContactToFailedGroup($this->log($contact));

        $this->assertSame([$other], $this->groupsOf($contact));
    }
}
