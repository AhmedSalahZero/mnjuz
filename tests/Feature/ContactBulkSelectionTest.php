<?php

namespace Tests\Feature;

use App\Exports\ContactsExport;
use App\Models\Addon;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Team;
use App\Models\User;
use App\Services\ContactService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * التحديد الشامل لجهات الاتصال، وأثره على الحذف والتصدير.
 *
 * القائمة تُرقَّم عشرةً في الصفحة، و«حدّد الكل» كان يُعلّم المعروض وحده فيقول
 * «10 محددة» مهما بلغ العدد. أكبر منشأة لديها 63,361 جهة اتصال، فتمرير
 * المعرّفات كلّها غير وارد — تُمرَّر النيّة مع المرشّح ويحلّها الخادم.
 */
class ContactBulkSelectionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['role' => 'user']);
        $this->organization = Organization::factory()->create(['created_by' => $this->user->id]);
        Team::factory()->create([
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'role' => 'owner',
            'created_by' => $this->user->id,
        ]);

        // HandleInertiaRequests يقرأ is_active من هذه الإضافة بلا احتياط من
        // null، فأي طلب HTTP يسقط على قاعدة اختبارات نظيفة قبل بلوغ المتحكّم.
        Addon::factory()->create(['name' => 'Google Authenticator']);

        // مسارات /contacts خلف check.subscription، فبلا اشتراك سارٍ تُعيد 302
        // قبل أن يُنفَّذ المتحكّم.
        $plan = SubscriptionPlan::create([
            'name' => 'Test Plan',
            'price' => 0,
            'period' => 'monthly',
            'metadata' => json_encode(['contacts_limit' => -1]),
        ]);
        Subscription::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'valid_until' => now()->addYear(),
        ]);

        session(['current_organization' => $this->organization->id]);
        $this->actingAs($this->user);
    }

    private function contact(string $firstName, string $phone, ?Organization $organization = null): Contact
    {
        return Contact::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => ($organization ?? $this->organization)->id,
            'first_name' => $firstName,
            'phone' => $phone,
            'created_by' => $this->user->id,
        ]);
    }

    /** @return \Illuminate\Support\Collection<int, Contact> */
    private function manyContacts(int $count): \Illuminate\Support\Collection
    {
        return collect(range(1, $count))->map(
            fn ($i) => $this->contact("Contact {$i}", '+9665' . str_pad((string) $i, 8, '0', STR_PAD_LEFT))
        );
    }

    /**
     * معرّفات نصّية.
     *
     * HasUuid يترك في السمة كائن Ramsey\Uuid لا نصّاً قبل إعادة القراءة من
     * القاعدة، وhttp_build_query تُسقط الكائنات صامتةً — فتخرج سلسلة استعلام
     * بلا معرّفات ويبدو أن التصدير يتجاهل التحديد.
     *
     * @param  \Illuminate\Support\Collection<int, Contact>  $contacts
     * @return array<int, string>
     */
    private function uuidsOf(\Illuminate\Support\Collection $contacts): array
    {
        return $contacts->map(fn (Contact $contact) => (string) $contact->uuid)->all();
    }

    /** ردّ التصدير ملف ثنائي (BinaryFileResponse) لا دفق. */
    private function csvBody($response): string
    {
        return file_get_contents($response->baseResponse->getFile()->getPathname());
    }

    private function liveCount(): int
    {
        return Contact::where('organization_id', $this->organization->id)->whereNull('deleted_at')->count();
    }

    // ------------------------------------------------- الحذف الشامل

    /** جوهر الطلب: التحديد يتجاوز الصفحة العاشرة إلى كل ما يطابق. */
    public function test_select_all_deletes_every_matching_contact_not_just_the_page(): void
    {
        $this->manyContacts(25);

        $this->deleteJson('/contacts', ['select_all' => true]);

        $this->assertSame(0, $this->liveCount(), '25 جهة اتصال في ثلاث صفحات — تُحذف كلّها');
    }

    public function test_select_all_respects_the_active_search(): void
    {
        $this->contact('Ahmed', '+966500000001');
        $this->contact('Ahmed', '+966500000002');
        $kept = $this->contact('Sara', '+966500000003');

        $this->deleteJson('/contacts', ['select_all' => true, 'search' => 'Ahmed']);

        $this->assertSame(1, $this->liveCount(), 'ما لا يطابق البحث لا يُمسّ');
        $this->assertNotNull(Contact::whereNull('deleted_at')->find($kept->id));
    }

    /**
     * إلغاء تحديد ثلاثة من ثلاثة وستّين ألفاً يُمرَّر ثلاثة معرّفات لا كلّها —
     * وهذا ما يجعل التحديد الشامل ممكناً أصلاً.
     */
    public function test_contacts_excluded_after_select_all_survive(): void
    {
        $contacts = $this->manyContacts(15);
        $spared = $contacts->take(3);

        $this->deleteJson('/contacts', [
            'select_all' => true,
            'excluded' => $this->uuidsOf($spared),
        ]);

        $this->assertSame(3, $this->liveCount());
        foreach ($spared as $contact) {
            $this->assertNotNull(Contact::whereNull('deleted_at')->find($contact->id));
        }
    }

    public function test_select_all_never_reaches_another_organizations_contacts(): void
    {
        $this->manyContacts(5);
        $otherOrganization = Organization::factory()->create(['created_by' => $this->user->id]);
        $foreign = $this->contact('Foreign', '+201111111111', $otherOrganization);

        $this->deleteJson('/contacts', ['select_all' => true]);

        $this->assertSame(0, $this->liveCount());
        $this->assertNotNull(Contact::whereNull('deleted_at')->find($foreign->id), 'منشأة أخرى لا تُمسّ');
    }

    // -------------------------------------- الفراغ لم يعد يعني «الكل»

    /**
     * أخطر سلوك في المسار القديم: الخدمة تعامل المصفوفة الفارغة كأمر بحذف كل
     * جهات اتصال المنشأة. أي طلب يفقد معرّفاته في الطريق كان يمسحها كلّها.
     */
    public function test_an_empty_selection_deletes_nothing(): void
    {
        $this->manyContacts(8);

        $this->deleteJson('/contacts', ['uuids' => []]);

        $this->assertSame(8, $this->liveCount(), 'الفراغ يعني «لا شيء» لا «كل شيء»');
    }

    public function test_a_request_with_no_parameters_at_all_deletes_nothing(): void
    {
        $this->manyContacts(8);

        $this->deleteJson('/contacts');

        $this->assertSame(8, $this->liveCount());
    }

    /** الحذف الصريح بمعرّفات يبقى كما كان. */
    public function test_deleting_an_explicit_list_still_works(): void
    {
        $contacts = $this->manyContacts(6);

        $this->deleteJson('/contacts', ['uuids' => $this->uuidsOf($contacts->take(2))]);

        $this->assertSame(4, $this->liveCount());
    }

    /** «احذف الكل» من القائمة المنسدلة يمرّ بنفس البوابة الصريحة. */
    public function test_delete_all_still_clears_the_organization(): void
    {
        $this->manyContacts(12);

        $this->deleteJson('/contacts', ['select_all' => true, 'excluded' => []]);

        $this->assertSame(0, $this->liveCount());
    }

    // ---------------------------------------------------- التصدير

    /** التصدير كان يتجاهل التحديد ويُخرج القائمة كاملة دائماً. */
    public function test_export_returns_only_the_selected_contacts(): void
    {
        $contacts = $this->manyContacts(20);
        $picked = $contacts->take(3);

        $rows = (new ContactsExport($this->uuidsOf($picked)))->collection();

        $this->assertCount(3, $rows);
    }

    public function test_export_without_a_selection_still_returns_everything(): void
    {
        $this->manyContacts(20);

        $this->assertCount(20, (new ContactsExport(null))->collection());
    }

    public function test_export_is_scoped_to_the_current_organization(): void
    {
        $this->manyContacts(4);
        $otherOrganization = Organization::factory()->create(['created_by' => $this->user->id]);
        $foreign = $this->contact('Foreign', '+201111111111', $otherOrganization);

        $rows = (new ContactsExport([(string) $foreign->uuid]))->collection();

        $this->assertCount(0, $rows, 'معرّف من منشأة أخرى لا يُصدَّر');
    }

    public function test_export_route_honours_an_explicit_uuid_list(): void
    {
        $contacts = $this->manyContacts(12);
        $picked = $contacts->take(2);

        $response = $this->get('/contacts/export?format=csv&' . http_build_query([
            'uuids' => $this->uuidsOf($picked),
        ]));

        $response->assertOk();
        $csv = $this->csvBody($response);

        $this->assertSame(3, substr_count(trim($csv), "\n") + 1, 'ترويسة وصفّان');
        $this->assertStringContainsString($picked->first()->first_name, $csv);
    }

    public function test_export_route_resolves_select_all_with_a_search(): void
    {
        $this->contact('Ahmed', '+966500000001');
        $this->contact('Ahmed', '+966500000002');
        $this->contact('Sara', '+966500000003');

        $response = $this->get('/contacts/export?format=csv&select_all=1&search=Ahmed');

        $response->assertOk();
        $csv = $this->csvBody($response);

        $this->assertStringContainsString('Ahmed', $csv);
        $this->assertStringNotContainsString('Sara', $csv);
    }

    public function test_export_route_without_a_selection_returns_every_contact(): void
    {
        $this->manyContacts(15);

        $response = $this->get('/contacts/export?format=csv');

        $response->assertOk();
        $this->assertSame(16, substr_count(trim($this->csvBody($response)), "\n") + 1);
    }

    // ------------------------------------------------ خدمة الحذف

    /**
     * الخدمة تُبقي دلالتها القديمة (الفراغ = الكل) لأن مسارات أخرى تعتمدها؛
     * الحارس الجديد يقف في المتحكّم. هذا الاختبار يوثّق الحدّ بينهما كي لا
     * يُساء فهمه لاحقاً.
     */
    public function test_the_service_still_treats_an_empty_list_as_all(): void
    {
        $this->manyContacts(5);

        (new ContactService($this->organization->id))->delete([]);

        $this->assertSame(0, $this->liveCount());
    }
}
