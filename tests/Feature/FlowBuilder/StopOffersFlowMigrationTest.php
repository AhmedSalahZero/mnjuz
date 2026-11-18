<?php

namespace Tests\Feature\FlowBuilder;

use App\Models\ContactGroup;
use App\Models\Organization;
use App\Services\CampaignAudienceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\FlowBuilder\Models\Flow;

/**
 * ترحيل يُدرج «إعادة الاشتراك في العروض» بعد زرّ «إعادة الإشتراك» في مسار
 * «ايقاف الاعلانات» لدى المنشأة — بالشكل الذي هو عليه على الإنتاج.
 *
 * قبله: الزرّ يضيف العميل إلى مجموعة ويبقيه منسحباً. بعده: يعود إلى الحملات،
 * وما بعد الزرّ كما بناه التاجر.
 */
class StopOffersFlowMigrationTest extends FlowBuilderTestCase
{
    private const MIGRATION = 'database/migrations/2026_11_18_120100_add_resubscribe_step_to_stop_offers_flow.php';
    private const ORGANIZATION_UUID = 'fc83d605-9b9b-4725-a703-701abbf4ea96';
    private const FLOW_UUID = 'c3b35d4a-71a8-4dad-9256-edaeb937c2cb';

    private ContactGroup $group;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('organizations')->where('id', $this->organization->id)->update(['uuid' => self::ORGANIZATION_UUID]);
        $this->group = ContactGroup::create([
            'organization_id' => $this->organization->id,
            'name' => 'إعادة الإشتراك في تلقي العروض',
            'created_by' => $this->owner->id,
        ]);
    }

    public function test_the_step_is_inserted_between_the_button_and_what_followed_it(): void
    {
        $flow = $this->stopOffersFlow();

        $this->runMigration();

        $metadata = json_decode($flow->fresh()->metadata);
        $step = collect($metadata->nodes)->firstWhere('id', '9');
        $this->assertNotNull($step, 'no new step');
        $this->assertSame('action', $step->type);
        $this->assertSame('resubscribe_marketing', $step->data->actionType);
        $this->assertTrue($step->data->is_active);
        $this->assertEquals(new \stdClass(), $step->data->config);
        $this->assertSame(9, $metadata->lastNodeId);

        $edges = collect($metadata->edges)->keyBy('id');
        $this->assertFalse($edges->has('vueflow__edge-5a-6'));
        $this->assertSame(['5', 'a', '9'], [$edges['vueflow__edge-5a-9']->source, $edges['vueflow__edge-5a-9']->sourceHandle, $edges['vueflow__edge-5a-9']->target]);
        $this->assertSame('9', $edges['vueflow__edge-5a-9']->targetNode->id);
        $this->assertSame(['9', '6'], [$edges['vueflow__edge-9-6']->source, $edges['vueflow__edge-9-6']->target]);
        $this->assertSame('add_to_group', $edges['vueflow__edge-9-6']->targetNode->data->actionType);

        // كل ما سواها كما كان.
        $this->assertCount(8, $metadata->nodes);
        $this->assertCount(7, $metadata->edges);
        $this->assertTrue($edges->has('vueflow__edge-2a-4') && $edges->has('vueflow__edge-6-8') && $edges->has('vueflow__edge-2b-7'));
    }

    /** المسار كاملاً بعد الترحيل، كما يمرّ به العميل على واتساب. */
    public function test_after_the_migration_pressing_resubscribe_brings_the_contact_back(): void
    {
        $this->stopOffersFlow();
        $this->runMigration();

        $this->receive('إيقاف تلقي العروض');
        $this->receiveButton('تأكيد إلغاء الإشتراك');
        $this->assertNotNull($this->contact->fresh()->marketing_opted_out_at);

        $this->receiveButton('إعادة الإشتراك');

        $this->assertNull($this->contact->fresh()->marketing_opted_out_at);
        $this->assertTrue($this->contact->fresh()->contactGroups()->where('contact_groups.id', $this->group->id)->exists());
        $this->assertFalse(CampaignAudienceService::isEmpty($this->organization->id, $this->group->id));
        $this->assertContains('سعيدين باهتمامك بعروضنا', $this->sentBodies());
    }

    public function test_a_flow_that_already_has_the_step_is_left_alone(): void
    {
        $flow = $this->stopOffersFlow(withStep: true);
        $before = $flow->fresh()->metadata;

        $this->runMigration();

        $this->assertSame($before, $flow->fresh()->metadata);
    }

    public function test_a_flow_without_the_resubscribe_button_is_left_alone(): void
    {
        $flow = $this->stopOffersFlow(resubscribeTitle: 'رجوع');
        $before = $flow->fresh()->metadata;

        $this->runMigration();

        $this->assertSame($before, $flow->fresh()->metadata);
    }

    public function test_two_resubscribe_buttons_are_ambiguous_and_left_alone(): void
    {
        $flow = $this->stopOffersFlow(secondResubscribeButton: true);
        $before = $flow->fresh()->metadata;

        $this->runMigration();

        $this->assertSame($before, $flow->fresh()->metadata);
    }

    public function test_the_same_flow_uuid_in_another_organization_is_left_alone(): void
    {
        $flow = $this->stopOffersFlow();
        $other = Organization::factory()->create(['created_by' => $this->owner->id]);
        DB::table('flows')->where('id', $flow->id)->update(['organization_id' => $other->id]);
        $before = $flow->fresh()->metadata;

        $this->runMigration();

        $this->assertSame($before, $flow->fresh()->metadata);
    }

    public function test_running_it_twice_adds_one_step(): void
    {
        $flow = $this->stopOffersFlow();

        $this->runMigration();
        $this->runMigration();

        $steps = collect(json_decode($flow->fresh()->metadata)->nodes)
            ->filter(fn ($node) => ($node->data->actionType ?? null) === 'resubscribe_marketing');
        $this->assertCount(1, $steps);
    }

    /** الكائنات الفارغة تبقى كائنات: [] بدلها يغيّر ما يقرؤه المحرّر. */
    public function test_empty_objects_survive_the_round_trip(): void
    {
        $flow = $this->stopOffersFlow();
        DB::table('flows')->where('id', $flow->id)->update([
            'metadata' => str_replace('"viewport":{', '"viewport":{"extra":{},', $flow->fresh()->metadata),
        ]);

        $this->runMigration();

        $this->assertStringContainsString('"extra":{}', $flow->fresh()->metadata);
        $this->assertStringNotContainsString('"config":[]', $flow->fresh()->metadata);
    }

    // ------------------------------------------------- أدوات

    private function runMigration(): void
    {
        $migration = require base_path(self::MIGRATION);
        $migration->up();
    }

    /** مسار 217 كما هو على الإنتاج (16:44): العقد 1،2،4،5،6،7،8 و lastNodeId = 8. */
    private function stopOffersFlow(bool $withStep = false, string $resubscribeTitle = 'إعادة الإشتراك', bool $secondResubscribeButton = false): Flow
    {
        $nodes = [
            $this->startNode('keywords', 'إيقاف تلقي العروض'),
            $this->buttonsNode('2', 'هل فعلاً لاتريد استقبال العروض ؟', ['تأكيد إلغاء الإشتراك', $secondResubscribeButton ? 'إعادة الإشتراك' : 'أبغى أستقبل عروضك']),
            $this->actionNode('4', 'remove_from_group'),
            $this->buttonsNode('5', 'تم إلغاء الإشتراك في رسائل العروض الخاصة', [$resubscribeTitle]),
            $this->actionNode('6', 'add_to_group', ['group_id' => $this->group->id]),
            $this->textNode('7', 'سعيدين باهتمامك بعروضنا'),
            $this->textNode('8', 'سعيدين باهتمامك بعروضنا'),
        ];
        $links = [['1', '2'], ['2', '4', 'a'], ['4', '5'], ['5', '6', 'a'], ['2', '7', 'b'], ['6', '8']];

        if ($withStep) {
            $nodes[] = $this->actionNode('9', 'resubscribe_marketing');
            $links = [['1', '2'], ['2', '4', 'a'], ['4', '5'], ['5', '9', 'a'], ['9', '6'], ['2', '7', 'b'], ['6', '8']];
        }

        $flow = $this->makeFlow($nodes, $links);

        $metadata = json_decode($flow->metadata);
        $metadata->lastNodeId = $withStep ? 9 : 8;
        DB::table('flows')->where('id', $flow->id)->update([
            'uuid' => self::FLOW_UUID,
            'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        return $flow->fresh();
    }
}
