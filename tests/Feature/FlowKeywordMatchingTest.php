<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\ContactGroup;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\FlowBuilder\Services\ActionExecutionService;
use Modules\FlowBuilder\Support\FlowKeywords;
use Tests\TestCase;

/**
 * كلمة STOP التي لم تكن تعمل.
 *
 * العطب: الكلمات تُخزَّن نصّاً مفصولاً بفاصلة، والمستخدم يكتبها «أ, ب, ج»
 * بفاصلة **ومسافة**. وكانت المطابقة `FIND_IN_SET(?, keywords)` في MySQL،
 * وهي لا تقصّ المسافات — فتصير العناصر « ب» و« ج» بمسافة بادئة ولا
 * يطابقها بحثٌ عن «ب».
 *
 * أي أن **الكلمة الأولى وحدها تعمل وكل ما بعدها ميّت**.
 *
 * قياس الإنتاج: ٢٦ من ٩١ كلمة (٢٩٪) لا تُطابَق، في ٣ منشآت. ومنها STOP
 * لإيقاف الرسائل التسويقية — العميل يكتبها فلا يُزال من المجموعات، وتصله
 * الحملة التالية.
 */
class FlowKeywordMatchingTest extends TestCase
{
    use RefreshDatabase;

    /** الصيغة التي يكتبها المستخدم فعلاً: فاصلة ومسافة. */
    private const HUMAN_INPUT = 'ايقاف تلقي الاعلانات, إيقاف الأعلانات؟, STOP';

    /* ───────────────── جوهر العطب ───────────────── */

    /**
     * الكلمة بعد الأولى كانت ميّتة — وهذا برهانه.
     *
     * نُثبت أن FIND_IN_SET (المطابقة القديمة) تعجز، وأن مطابقتنا تنجح.
     * فلو عاد أحد إلى FIND_IN_SET يسقط هذا الاختبار ويقول لماذا.
     */
    public function test_find_in_set_fails_where_our_matcher_succeeds(): void
    {
        $oldMatch = \DB::selectOne('SELECT FIND_IN_SET(?, ?) AS p', ['stop', self::HUMAN_INPUT])->p;

        $this->assertSame(0, (int) $oldMatch, 'FIND_IN_SET كانت تطابق — العطب لم يعد قائماً أصلاً؟');
        $this->assertTrue(FlowKeywords::matches(self::HUMAN_INPUT, 'STOP'));
    }

    /** وكل كلمة في القائمة تعمل، لا الأولى وحدها. */
    public function test_every_keyword_matches_not_only_the_first(): void
    {
        foreach (['ايقاف تلقي الاعلانات', 'إيقاف الأعلانات؟', 'STOP'] as $keyword) {
            $this->assertTrue(
                FlowKeywords::matches(self::HUMAN_INPUT, $keyword),
                "الكلمة «{$keyword}» لا تُطابَق."
            );
        }
    }

    /** وحالة الأحرف لا تُهمّ — العميل يكتب Stop و stop و STOP. */
    public function test_matching_ignores_letter_case(): void
    {
        foreach (['STOP', 'stop', 'Stop', 'sToP'] as $written) {
            $this->assertTrue(FlowKeywords::matches(self::HUMAN_INPUT, $written), $written);
        }
    }

    /** والمسافات حول الرسالة كذلك. */
    public function test_matching_ignores_surrounding_whitespace(): void
    {
        $this->assertTrue(FlowKeywords::matches(self::HUMAN_INPUT, '  STOP  '));
        $this->assertTrue(FlowKeywords::matches("أ ,  ب  , STOP", 'stop'));
    }

    /**
     * والسلوك القديم محفوظ: كلمة مفردة داخل جملة تُشغّل الـ flow.
     *
     * هذا ما كان يفعله التنفيذ القديم (يُجرّب الرسالة كاملةً ثم كل كلمة)،
     * وتغييره يُغيّر سلوك ٦٤ flow قائماً.
     */
    public function test_a_single_word_inside_a_sentence_still_triggers(): void
    {
        $this->assertTrue(FlowKeywords::matches(self::HUMAN_INPUT, 'من فضلك STOP'));
    }

    /** ورسالة لا تحوي أي كلمة لا تُشغّل شيئاً. */
    public function test_an_unrelated_message_does_not_trigger(): void
    {
        $this->assertFalse(FlowKeywords::matches(self::HUMAN_INPUT, 'السلام عليكم'));
        $this->assertFalse(FlowKeywords::matches(self::HUMAN_INPUT, 'stopping'));
    }

    /** والفارغ لا يُشغّل شيئاً — وإلّا شغّلت كل رسالة كل flow. */
    public function test_empty_input_never_triggers(): void
    {
        $this->assertFalse(FlowKeywords::matches(self::HUMAN_INPUT, ''));
        $this->assertFalse(FlowKeywords::matches(self::HUMAN_INPUT, '   '));
        $this->assertFalse(FlowKeywords::matches(self::HUMAN_INPUT, null));
        $this->assertFalse(FlowKeywords::matches(null, 'STOP'));
        $this->assertFalse(FlowKeywords::matches('', 'STOP'));
        $this->assertFalse(FlowKeywords::matches('  ,  , ', 'STOP'));
    }

    /* ───────────────── التطبيع عند الحفظ ───────────────── */

    /** الحفظ يُخرج صيغة بلا مسافات. */
    public function test_storage_form_has_no_stray_whitespace(): void
    {
        $this->assertSame(
            'ايقاف تلقي الاعلانات,إيقاف الأعلانات؟,stop',
            FlowKeywords::normalizeForStorage(self::HUMAN_INPUT)
        );
    }

    /** والتكرار يُزال — كلمة مكرّرة لا تُغيّر شيئاً وتُربك القراءة. */
    public function test_duplicates_are_removed(): void
    {
        $this->assertSame('stop,ايقاف', FlowKeywords::normalizeForStorage('STOP, stop , Stop, ايقاف'));
    }

    /** والفارغ يُحفظ null لا نصّاً فارغاً. */
    public function test_blank_input_is_stored_as_null(): void
    {
        $this->assertNull(FlowKeywords::normalizeForStorage(''));
        $this->assertNull(FlowKeywords::normalizeForStorage('   '));
        $this->assertNull(FlowKeywords::normalizeForStorage(', ,  ,'));
        $this->assertNull(FlowKeywords::normalizeForStorage(null));
    }

    /**
     * والصيغة المطبَّعة تعمل مع FIND_IN_SET كذلك.
     *
     * يهمّ لأن كوداً آخر أو تقريراً قد يستعملها، فلا نترك العمود معطوباً
     * اتّكالاً على أن المطابقة صارت في PHP.
     */
    public function test_the_stored_form_also_works_with_find_in_set(): void
    {
        $normalized = FlowKeywords::normalizeForStorage(self::HUMAN_INPUT);

        foreach (['stop', 'ايقاف تلقي الاعلانات', 'إيقاف الأعلانات؟'] as $keyword) {
            $found = \DB::selectOne('SELECT FIND_IN_SET(?, ?) AS p', [$keyword, $normalized])->p;
            $this->assertGreaterThan(0, (int) $found, $keyword);
        }
    }

    /* ───────────────── الإجراء نفسه ───────────────── */

    /**
     * والإزالة من المجموعات تعمل — لم تكن هي العطب.
     *
     * أثبتناه على بيانات الإنتاج داخل transaction متراجَع عنه، ونُثبّته
     * هنا حتى لا ينكسر لاحقاً.
     */
    public function test_removing_from_groups_detaches_and_opts_out(): void
    {
        $owner = User::factory()->create(['role' => 'user']);
        $organization = Organization::factory()->create(['created_by' => $owner->id]);

        $contact = Contact::create([
            'organization_id' => $organization->id,
            'phone' => '+966500000001',
            'first_name' => 'أحمد',
            'created_by' => 0,
        ]);

        $groups = collect(['عملاء', 'مهتمّون'])->map(fn ($name) => ContactGroup::create([
            'organization_id' => $organization->id,
            'name' => $name,
            'created_by' => $owner->id,
        ]));

        $contact->contactGroups()->attach($groups->pluck('id')->all());
        $this->assertSame(2, $contact->contactGroups()->count());

        $result = (new ActionExecutionService($organization->id))
            ->executeAction('remove_from_group', [], $contact, 'STOP');

        $this->assertTrue($result);
        $this->assertSame(0, $contact->fresh()->contactGroups()->count());
        $this->assertNotNull($contact->fresh()->marketing_opted_out_at);
    }

    /**
     * ولا تمسّ مجموعات منشأة أخرى.
     *
     * جدول الربط لا يحمل organization_id، فالحصر يجري عبر المجموعات — ولو
     * سقط الحصر لأزال الإجراءُ عملاءَ منشأةٍ أخرى من مجموعاتهم.
     */
    public function test_removing_never_touches_another_organizations_groups(): void
    {
        $ownerA = User::factory()->create(['role' => 'user']);
        $orgA = Organization::factory()->create(['created_by' => $ownerA->id]);
        $ownerB = User::factory()->create(['role' => 'user']);
        $orgB = Organization::factory()->create(['created_by' => $ownerB->id]);

        $groupA = ContactGroup::create(['organization_id' => $orgA->id, 'name' => 'أ', 'created_by' => $ownerA->id]);
        $groupB = ContactGroup::create(['organization_id' => $orgB->id, 'name' => 'ب', 'created_by' => $ownerB->id]);

        $contactA = Contact::create([
            'organization_id' => $orgA->id, 'phone' => '+966500000002', 'created_by' => 0,
        ]);
        $contactB = Contact::create([
            'organization_id' => $orgB->id, 'phone' => '+966500000003', 'created_by' => 0,
        ]);

        $contactA->contactGroups()->attach($groupA->id);
        $contactB->contactGroups()->attach($groupB->id);

        (new ActionExecutionService($orgA->id))
            ->executeAction('remove_from_group', [], $contactA, 'STOP');

        $this->assertSame(0, $contactA->fresh()->contactGroups()->count());
        $this->assertSame(1, $contactB->fresh()->contactGroups()->count());
        $this->assertNull($contactB->fresh()->marketing_opted_out_at);
    }

    /** وتاريخ الانسحاب لا يُعاد ضبطه عند تكرار المرور — هو حجّة عند المراجعة. */
    public function test_the_opt_out_date_is_kept_from_the_first_time(): void
    {
        $owner = User::factory()->create(['role' => 'user']);
        $organization = Organization::factory()->create(['created_by' => $owner->id]);

        $contact = Contact::create([
            'organization_id' => $organization->id,
            'phone' => '+966500000004',
            'created_by' => 0,
            'marketing_opted_out_at' => now()->subMonth(),
        ]);

        $original = $contact->fresh()->marketing_opted_out_at;

        (new ActionExecutionService($organization->id))
            ->executeAction('remove_from_group', [], $contact, 'STOP');

        $this->assertEquals($original, $contact->fresh()->marketing_opted_out_at);
    }

    /* ───────────────── حارس انحدار ───────────────── */

    /** لا عودة إلى FIND_IN_SET في مسار المطابقة. */
    public function test_the_matcher_no_longer_relies_on_find_in_set(): void
    {
        $source = file_get_contents(base_path('modules/FlowBuilder/Services/FlowExecutionService.php'));

        // نفحص الكود لا التعليقات: الشرح يذكر FIND_IN_SET ليُفسّر العطب،
        // فنُسقط أسطر التعليقات قبل الفحص.
        $code = array_filter(
            explode("\n", $source),
            static function ($line) {
                $trimmed = ltrim($line);

                return $trimmed !== ''
                    && !str_starts_with($trimmed, '*')
                    && !str_starts_with($trimmed, '//')
                    && !str_starts_with($trimmed, '/*');
            }
        );

        $this->assertStringNotContainsString(
            'FIND_IN_SET',
            implode("\n", $code),
            'عادت المطابقة إلى FIND_IN_SET — كل كلمة بعد الأولى ستموت من جديد.'
        );
    }

    /** والحفظ ما زال يُطبّع. */
    public function test_saving_still_normalizes(): void
    {
        $source = file_get_contents(base_path('modules/FlowBuilder/Services/FlowService.php'));

        $this->assertStringContainsString('FlowKeywords::normalizeForStorage', $source);
    }
}
