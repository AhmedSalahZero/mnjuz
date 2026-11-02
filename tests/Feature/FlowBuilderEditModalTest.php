<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * نافذة تعديل عقد الـ action: كاملة، وتُغلق بالنقر خارجها، ولا تحذف العقدة.
 *
 * ثلاثة أعراض لسبب واحد: النافذة كانت داخل قالب العقدة، وvue-flow يضع transform
 * على كل عقدة، فصار مرجع position: fixed هو العقدة لا الشاشة.
 *  - تظهر مقصوصة بحجم العقدة وخلفيتها الداكنة شريطان جانبيان.
 *  - لا تُغلق بالنقر خارجها.
 *  - ويحذف Backspace العقدة المحدّدة وهو على قائمة اختيار (زرّ لا حقل)، فيختفي
 *    «الزرّ» كأنه أُغلق.
 *
 * فحص الترجمة والبنية يجري بـ node (tests/js/flow-action-modals.mjs)؛ وهنا
 * الحراسة على أن الجذر الحقيقي للمشكلة لا يعود.
 */
class FlowBuilderEditModalTest extends TestCase
{
    private const DIR = 'modules/FlowBuilder/Pages/User/Components/vue-flow/nodes/actions/';
    private const MODAL = self::DIR . 'NodeEditModal.vue';

    private const ACTIONS = [
        'AddToGroup', 'RemoveFromGroup', 'UpdateContact', 'SendEmail',
        'Delay', 'Webhook', 'Conditional', 'AIResponse',
    ];

    private function source(string $path): string
    {
        $this->assertFileExists(base_path($path));

        return file_get_contents(base_path($path));
    }

    private function nodeAvailable(): bool
    {
        exec('node --version 2>/dev/null', $output, $status);

        return $status === 0;
    }

    /** @return array<string, array{0: string}> */
    public static function actions(): array
    {
        return array_combine(self::ACTIONS, array_map(fn ($a) => [$a], self::ACTIONS));
    }

    public function test_the_templates_compile_and_the_modal_is_wired(): void
    {
        if (!$this->nodeAvailable() || !is_dir(base_path('node_modules/@vue/compiler-sfc'))) {
            $this->markTestSkipped('node أو @vue/compiler-sfc غير متاح في هذه البيئة');
        }

        $script = base_path('tests/js/flow-action-modals.mjs');
        $this->assertFileExists($script);

        exec('node ' . escapeshellarg($script) . ' 2>&1', $output, $status);

        $this->assertSame(0, $status, implode("\n", $output));
        $this->assertStringContainsString('OK', implode("\n", $output));
    }

    // ------------------------------------------------- لا نافذة داخل العقدة

    /**
     * @dataProvider actions
     *
     * الجذر نفسه: fixed داخل قالب عقدة. عودته تعني عودة الأعراض الثلاثة.
     */
    public function test_no_action_node_draws_a_fixed_overlay_inside_itself(string $action): void
    {
        $source = $this->source(self::DIR . "{$action}-node.vue");

        $this->assertDoesNotMatchRegularExpression(
            '/class="[^"]*\bfixed\b[^"]*\binset-0\b/',
            $source,
            "{$action}: نافذة fixed داخل العقدة — مرجعها العقدة لا الشاشة بسبب transform."
        );
    }

    /** @dataProvider actions */
    public function test_every_action_node_uses_the_shared_modal(string $action): void
    {
        $source = $this->source(self::DIR . "{$action}-node.vue");

        $this->assertStringContainsString("import NodeEditModal from './NodeEditModal.vue'", $source);
        $this->assertStringContainsString('<NodeEditModal :open="showEditModal" @close="closeEditModal">', $source);
        $this->assertStringContainsString('</NodeEditModal>', $source);
    }

    /** كل العقد الثماني، لا بعضها: عقدة جديدة بنافذتها الخاصة تعيد العطل. */
    public function test_no_action_node_was_left_out(): void
    {
        $found = array_map(
            fn ($path) => basename($path, '-node.vue'),
            glob(base_path(self::DIR . '*-node.vue'))
        );
        sort($found);
        $expected = self::ACTIONS;
        sort($expected);

        $this->assertSame($expected, $found, 'عقدة action لا تغطّيها هذه الحراسة.');
    }

    // ------------------------------------------------- النافذة المشتركة

    public function test_the_modal_is_rendered_in_the_body_not_inside_the_node(): void
    {
        $modal = $this->source(self::MODAL);

        $this->assertStringContainsString('<Teleport to="body">', $modal);
    }

    /** nokey يمنع Backspace من حذف العقدة؛ بقية الأصناف تمنع السحب والتكبير من داخلها. */
    public function test_keystrokes_and_gestures_inside_it_do_not_reach_the_canvas(): void
    {
        $modal = $this->source(self::MODAL);

        foreach (['nokey', 'nodrag', 'nowheel'] as $class) {
            $this->assertMatchesRegularExpression('/class="[^"]*\b' . $class . '\b/', $modal, "الصنف {$class} غائب.");
        }
    }

    public function test_clicking_outside_closes_it(): void
    {
        $modal = $this->source(self::MODAL);

        $this->assertStringContainsString('@mousedown.self="close"', $modal);
        // mousedown لا click: سحب تحديدٍ ينتهي على الخلفية لا يُغلق الفورم.
        $this->assertStringNotContainsString('@click.self', $modal);
    }

    public function test_escape_closes_it_unless_an_inner_control_handled_it(): void
    {
        $modal = $this->source(self::MODAL);

        $this->assertStringContainsString("event.key === 'Escape'", $modal);
        $this->assertStringContainsString('!event.defaultPrevented', $modal);
    }

    public function test_the_key_listener_does_not_leak(): void
    {
        $modal = $this->source(self::MODAL);

        $this->assertStringContainsString("document.addEventListener('keydown', onKeydown)", $modal);
        $this->assertGreaterThanOrEqual(
            2,
            substr_count($modal, "document.removeEventListener('keydown', onKeydown)"),
            'يُزال عند الإغلاق وعند تفكيك المكوّن.'
        );
    }

    public function test_a_long_form_scrolls_inside_the_window(): void
    {
        $modal = $this->source(self::MODAL);

        $this->assertStringContainsString('max-h-[90vh]', $modal);
        $this->assertStringContainsString('overflow-y-auto', $modal);
    }

    /** فوق عقد القماش (التي ترتفع إلى 1000 عند تحديدها) وفوق أزرار التحكّم. */
    public function test_it_stacks_above_the_canvas(): void
    {
        $modal = $this->source(self::MODAL);

        preg_match('/z-\[(\d+)\]/', $modal, $match);

        $this->assertNotEmpty($match, 'لا z-index صريح.');
        $this->assertGreaterThan(1000, (int) $match[1]);
    }

    // ------------------------------------------------- Tailwind

    /**
     * كلاسات الوحدات تُولَّد: بلا ذلك تفقد العقد حدودها وألوانها لأن Tailwind
     * لا يرى modules/ — والنافذة الجديدة تعتمد على أصنافٍ مثل bg-black/50.
     */
    public function test_tailwind_scans_the_modules(): void
    {
        $config = $this->source('tailwind.config.js');

        $this->assertStringContainsString('"./modules/**/*.vue"', $config);
        $this->assertStringContainsString('"./modules/**/*.js"', $config);
    }

    // ------------------------------------------------- لم يتغيّر غيرها

    /** عقد الرسائل (نص، أزرار، قائمة…) لا نافذة لها — تُعدَّل داخل بطاقتها. */
    public function test_message_nodes_were_not_touched(): void
    {
        foreach (['Text', 'Buttons', 'List', 'Media', 'Start'] as $node) {
            $path = "modules/FlowBuilder/Pages/User/Components/vue-flow/nodes/{$node}-node.vue";
            if (!file_exists(base_path($path))) {
                continue;
            }

            $this->assertStringNotContainsString('NodeEditModal', $this->source($path), $path);
        }
    }
}
