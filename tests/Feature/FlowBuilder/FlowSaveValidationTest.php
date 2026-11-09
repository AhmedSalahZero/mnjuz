<?php

namespace Tests\Feature\FlowBuilder;

use Illuminate\Support\Facades\DB;
use Modules\FlowBuilder\Models\Flow;
use Modules\FlowBuilder\Validators\FlowValidator;

/**
 * الحفظ والنشر.
 *
 * «حفظ» على مسارٍ منشور فيه خطوةٌ ناقصة كان يُلغي نشره بصمت ويُظهر «تم الحفظ
 * بنجاح»، فيتوقّف البوت عن الردّ على كل العملاء. والأخطاء كانت مفتاحاً لكل
 * نوع (يمحو آخرُها ما قبله) بنصّ إنجليزي لا يقول أيّ خطوة.
 */
class FlowSaveValidationTest extends FlowBuilderTestCase
{
    private function asOwner(): static
    {
        return $this->actingAs($this->owner, 'user')
            ->withSession(['current_organization' => $this->organization->id]);
    }

    /** @return array{0: Flow, 1: array} مسار سليم ونسخةٌ منه بخطوة نصّ فارغة. */
    private function flowWithBrokenEdit(string $status): array
    {
        $flow = $this->makeFlow([$this->startNode('keywords', 'start'), $this->textNode('2', 'أهلاً')], [['1', '2']], ['status' => $status]);
        $graph = json_decode($flow->metadata, true);
        $graph['nodes'][1]['data']['metadata']['fields']['body'] = '';

        return [$flow, $graph];
    }

    private function save(Flow $flow, array $graph, ?int $publish = null)
    {
        $payload = ['metadata' => json_encode($graph, JSON_UNESCAPED_UNICODE)];
        if ($publish !== null) {
            $payload['publish'] = $publish;
        }

        return $this->asOwner()->putJson('/automation/flows/' . $flow->uuid, $payload);
    }

    // ------------------------------------------------- المسار المنشور

    public function test_a_broken_edit_to_a_live_flow_is_rejected_and_the_flow_keeps_running(): void
    {
        [$flow, $graph] = $this->flowWithBrokenEdit('active');
        $before = $flow->metadata;

        $this->save($flow, $graph)
            ->assertOk()
            ->assertJsonPath('success', false)
            ->assertJsonPath('status', 'active')
            ->assertJsonPath('errors.0.node_id', '2')
            ->assertJsonPath('errors.0.node_type', 'text');

        $flow->refresh();
        $this->assertSame('active', $flow->status);
        $this->assertSame($before, $flow->metadata);
    }

    public function test_a_valid_edit_to_a_live_flow_is_saved_and_stays_live(): void
    {
        [$flow, $graph] = $this->flowWithBrokenEdit('active');
        $graph['nodes'][1]['data']['metadata']['fields']['body'] = 'أهلاً وسهلاً';

        $this->save($flow, $graph)->assertJsonPath('success', true)->assertJsonPath('status', 'active');

        $this->assertStringContainsString('أهلاً وسهلاً', $flow->fresh()->metadata);
    }

    public function test_unpublishing_saves_even_a_broken_flow(): void
    {
        [$flow, $graph] = $this->flowWithBrokenEdit('active');

        $this->save($flow, $graph, 0)->assertJsonPath('success', true)->assertJsonPath('status', 'inactive');

        $this->assertSame('inactive', $flow->fresh()->status);
    }

    // ------------------------------------------------- مسودّة

    public function test_a_draft_is_saved_with_its_problems_listed(): void
    {
        [$flow, $graph] = $this->flowWithBrokenEdit('inactive');

        $this->save($flow, $graph)
            ->assertJsonPath('success', true)
            ->assertJsonPath('status', 'inactive')
            ->assertJsonPath('errors.0.node_id', '2');

        $this->assertStringContainsString('"body":""', $flow->fresh()->metadata);
    }

    public function test_publishing_a_broken_flow_saves_it_but_keeps_it_unpublished(): void
    {
        [$flow, $graph] = $this->flowWithBrokenEdit('inactive');

        $this->save($flow, $graph, 1)
            ->assertJsonPath('success', false)
            ->assertJsonPath('status', 'inactive');

        $this->assertSame('inactive', $flow->fresh()->status);
    }

    /** بعد حذف البداية كان المحفّز يُقرأ من أوّل عقدة («text») فيرفضه العمود. */
    public function test_a_flow_without_a_start_step_saves_without_crashing(): void
    {
        $flow = $this->makeFlow([$this->startNode(), $this->textNode('2', 'نص')], [['1', '2']], ['status' => 'inactive']);

        $this->save($flow, ['nodes' => [$this->textNode('2', 'نص')], 'edges' => []])
            ->assertOk()
            ->assertJsonPath('errors.0.message', __('The flow has no start step.'));

        $this->assertNull($flow->fresh()->trigger);
    }

    // ------------------------------------------------- الرسائل

    public function test_every_problem_is_reported_with_its_step_and_in_the_users_language(): void
    {
        app()->setLocale('ar');

        $result = (new FlowValidator())->validateMessageNodes(['nodes' => [
            $this->startNode('keywords', ''),
            $this->textNode('2', ''),
            $this->textNode('3', ''),
        ]]);

        $this->assertSame(['1', '2', '3'], array_column($result, 'node_id'), 'كل خطوةٍ بخطئها، لا آخرها وحده');
        $this->assertMatchesRegularExpression('/\p{Arabic}/u', $result[1]['message']);
    }

    /** @return array<string, array{0: array, 1: string}> */
    public static function whatsappLimits(): array
    {
        return [
            'no trigger' => [['start', ['type' => null]], 'Choose what starts the flow.'],
            'audio header on buttons' => [['buttons', ['headerType' => 'audio']], 'WhatsApp does not allow an audio header here. Use an image, video or document.'],
            'button longer than 20' => [['buttons', ['buttons' => ['button1' => str_repeat('ب', 21)]]], 'The button ":title" is longer than :max characters.'],
            'duplicate buttons' => [['buttons', ['buttons' => ['button1' => 'نعم', 'button2' => 'نعم']]], 'Two options have the same title ":title". Each option needs its own title.'],
            'row longer than 24' => [['list', ['rows' => [str_repeat('ص', 25)]]], 'The row ":title" is longer than :max characters.'],
            'more than 10 rows' => [['list', ['rows' => array_map(fn ($i) => "صف {$i}", range(1, 11))]], 'A list can have at most :max rows in total.'],
        ];
    }

    /** @dataProvider whatsappLimits */
    public function test_whatsapp_limits_are_checked_before_publishing(array $case, string $expected): void
    {
        [$type, $override] = $case;

        $node = match ($type) {
            'start' => $this->startNode(...['trigger' => 'keywords']),
            'buttons' => $this->buttonsNode('2', 'اختر', ['نعم', 'لا']),
            'list' => $this->listNode('2', 'اختر', [['أ']]),
        };

        if ($type === 'start') {
            $node['data']['metadata']['fields']['type'] = null;
        } elseif ($type === 'list') {
            $node['data']['metadata']['fields']['sections'][0]['rows'] = array_map(
                fn ($title, $i) => ['id' => "r{$i}", 'title' => $title, 'description' => ''],
                $override['rows'],
                array_keys($override['rows'])
            );
        } else {
            $node['data']['metadata']['fields'] = array_replace_recursive($node['data']['metadata']['fields'], $override);
        }

        $nodes = $type === 'start' ? [$node] : [$this->startNode(), $node];
        $result = (new FlowValidator())->validateMessageNodes(['nodes' => $nodes]);

        $this->assertIsArray($result);
        $prefix = explode(':', explode('"', $expected)[0])[0];
        $this->assertNotEmpty(
            array_filter($result, fn ($error) => str_starts_with($error['message'], $prefix)),
            "لم يُرصد: {$expected}\n" . json_encode($result, JSON_UNESCAPED_UNICODE)
        );
    }

    public function test_a_valid_flow_passes(): void
    {
        $list = $this->listNode('3', 'اختر', [['أ', 'ب']]);
        $list['data']['metadata']['fields']['sections'][0]['rows'][0]['id'] = 'r1';

        $result = (new FlowValidator())->validateMessageNodes(['nodes' => [
            $this->startNode(),
            $this->buttonsNode('2', 'اختر', ['نعم', 'لا']),
            $list,
        ]]);

        $this->assertTrue($result, json_encode($result, JSON_UNESCAPED_UNICODE));
    }

    /** Conditional معطّل، لكن مسارات قديمة تحمله بلا condition_type: كان الحفظ يسقط بـ 500. */
    public function test_a_legacy_conditional_without_a_type_does_not_crash(): void
    {
        $result = (new FlowValidator())->validateMessageNodes(['nodes' => [
            $this->startNode(),
            $this->actionNode('2', 'conditional', ['conditions' => [['value' => 'x']]]),
        ]]);

        $this->assertSame(__('Choose the condition type.'), $result[0]['message']);
    }

    // ------------------------------------------------- القائمة والتكرار

    public function test_a_duplicated_flow_starts_unpublished(): void
    {
        $flow = $this->makeFlow([$this->startNode('keywords', 'start')], [], ['name' => 'ترحيب_100%']);

        $this->asOwner()->get('/automation/flows/duplicate/' . $flow->uuid);

        $copy = Flow::where('organization_id', $this->organization->id)->where('id', '!=', $flow->id)->first();
        $this->assertSame('inactive', $copy->status);
        $this->assertSame('ترحيب_100% (1)', $copy->name);
    }

    public function test_the_description_can_be_cleared(): void
    {
        $flow = $this->makeFlow([$this->startNode()], [], ['description' => 'قديم', 'status' => 'inactive']);

        $this->asOwner()->putJson('/automation/flows/' . $flow->uuid, ['name' => $flow->name, 'description' => null])->assertOk();

        $this->assertNull($flow->fresh()->description);
    }

    public function test_the_metadata_column_holds_large_flows(): void
    {
        $type = DB::selectOne(
            'SELECT DATA_TYPE AS type FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            ['flows', 'metadata']
        )->type;

        $this->assertSame('longtext', strtolower($type));
    }
}
