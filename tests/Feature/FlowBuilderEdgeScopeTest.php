<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * وصلات الـ flow: العقدة تحذف وصلات مخرجها هي وحدها.
 *
 * العطل: معرّفات المخارج ثابتة ومشتركة بين كل العقد من النوع نفسه — الزر
 * الأول 'a' في كل عقدة Interactive Buttons، والصف الأول 'a00' في كل عقدة
 * List. وكان الحذف يفلتر بـ sourceHandle وحده بلا فحص العقدة، فالعقدة تحذف
 * وصلات غيرها.
 *
 * وواضع الـ flow كان يرى ذلك هكذا: يوصّل عقدة، ثم يكتب حرفًا واحدًا في عقدة
 * أخرى، فتختفي وصلته الأولى بلا سبب ظاهر — والمراقب في العقدة الثانية يعمل
 * مع كل تغيير، ويحذف مخارج أزرارها الفارغة من كل مكان. ثم تُحفظ الوصلة
 * محذوفة عند أول ضغطة Save.
 */
class FlowBuilderEdgeScopeTest extends TestCase
{
    private const HELPER = 'modules/FlowBuilder/Pages/User/Components/vue-flow/flowEdges.js';
    private const BUTTONS = 'modules/FlowBuilder/Pages/User/Components/vue-flow/nodes/Buttons-node.vue';
    private const LIST = 'modules/FlowBuilder/Pages/User/Components/vue-flow/nodes/List-node.vue';

    private function nodeAvailable(): bool
    {
        exec('node --version 2>/dev/null', $output, $status);

        return $status === 0;
    }

    public function test_the_scoping_rules_hold(): void
    {
        if (!$this->nodeAvailable()) {
            $this->markTestSkipped('node غير متاح في هذه البيئة');
        }

        $script = base_path('tests/js/flow-edges.mjs');
        $this->assertFileExists($script);

        exec('node ' . escapeshellarg($script) . ' 2>&1', $output, $status);

        $this->assertSame(0, $status, implode("\n", $output));
        $this->assertStringContainsString('OK', implode("\n", $output));
    }

    public function test_the_logic_stays_in_its_own_testable_module(): void
    {
        $this->assertFileExists(base_path(self::HELPER));
    }

    /**
     * الحارس الأهم: لا فلترة بـ sourceHandle بلا فحص العقدة.
     *
     * هذا هو العطل نفسه — وعودته تعني عودة اختفاء الوصلات.
     */
    public function test_no_node_deletes_edges_by_handle_alone(): void
    {
        foreach ([self::BUTTONS, self::LIST] as $path) {
            $source = file_get_contents(base_path($path));

            $this->assertDoesNotMatchRegularExpression(
                '/edges\.value\.filter\(\s*edge\s*=>\s*edge\.sourceHandle\s*===/',
                $source,
                $path . ': الفلترة بـ sourceHandle وحده تحذف وصلات العقد الأخرى'
            );

            $this->assertStringContainsString(
                'edgeIdsForHandle(edges.value, node.id, handleId)',
                $source,
                $path . ': الحذف يجب أن يمرّ بالدالة المقصورة على العقدة'
            );
        }
    }

    /** الحذف يشترط العقدة والمخرج معًا، لا أحدهما. */
    public function test_the_helper_requires_both_the_node_and_the_handle(): void
    {
        $helper = file_get_contents(base_path(self::HELPER));

        $this->assertMatchesRegularExpression(
            '/edge\.source === nodeId && edge\.sourceHandle === handleId/',
            $helper,
            'الشرطان معًا هما ما يمنع العقدة من حذف وصلات غيرها'
        );
    }

    /**
     * المخارج ما زالت مشتركة بين العقد — وهو ما يجعل الحارس أعلاه ضروريًا.
     * لو صارت فريدة يومًا فالحارس يبقى صحيحًا ولا يضرّ.
     */
    public function test_handle_ids_are_shared_across_nodes(): void
    {
        $buttons = file_get_contents(base_path(self::BUTTONS));

        $this->assertStringContainsString('id="a" type="source"', $buttons);
        $this->assertStringContainsString('id="d" type="source"', $buttons);

        $list = file_get_contents(base_path(self::LIST));

        $this->assertStringContainsString("'a' + sectionIndex + rowIndex", $list);
    }

    // ------------------------------------------------ معرّفات العقد

    /**
     * السبب الثاني لنفس العَرَض: عقدتان بمعرّف واحد.
     *
     * التوليد كان بطريقتين في المكان نفسه — السحب والإفلات «الأكبر + 2»
     * فيقفز ويترك فجوات، وزر Duplicate «عدد العقد + 1» فيمشي فيها. فـ flow
     * فيه start('1') وعقدة مسحوبة ('3') يعطي أول تكرار المعرّف '3' أيضًا.
     * وعقدتان بمعرّف واحد تجعلان قاعدة «مخرج واحد لهدف واحد» تراهما عقدة
     * واحدة، فتوصيل النسخة يحذف وصلة الأصل.
     */
    public function test_node_ids_come_from_one_generator(): void
    {
        if (!$this->nodeAvailable()) {
            $this->markTestSkipped('node غير متاح في هذه البيئة');
        }

        $script = base_path('tests/js/flow-nodes.mjs');
        $this->assertFileExists($script);

        exec('node ' . escapeshellarg($script) . ' 2>&1', $output, $status);

        $this->assertSame(0, $status, implode("\n", $output));
        $this->assertStringContainsString('OK', implode("\n", $output));
    }

    /** الحارس: لا معرّف يُبنى من عدد العقد أو من «الأكبر + 2». */
    public function test_no_file_derives_an_id_from_the_node_count(): void
    {
        $files = array_merge(
            glob(base_path('modules/FlowBuilder/Pages/User/Components/vue-flow/nodes/*.vue')),
            [base_path('modules/FlowBuilder/Pages/User/Components/main-canvas.vue')]
        );

        $this->assertNotEmpty($files);

        foreach ($files as $file) {
            $source = file_get_contents($file);
            $name = basename($file);

            $this->assertStringNotContainsString(
                'nodes.value.length + 1',
                $source,
                $name . ': المعرّف من عدد العقد يصطدم بمعرّف موجود بعد أي حذف أو فجوة'
            );

            $this->assertDoesNotMatchRegularExpression(
                '/Math\.max\(\.\.\.nodes\.value\.map/',
                $source,
                $name . ': توليد المعرّف يجب أن يمرّ بمولّد واحد مشترك'
            );
        }
    }

    /** النسخة لا تشارك الأصل كائن data نفسه. */
    public function test_duplicating_a_node_copies_its_data(): void
    {
        $helper = file_get_contents(
            base_path('modules/FlowBuilder/Pages/User/Components/vue-flow/flowNodes.js')
        );

        $this->assertStringContainsString('cloneNodeData(source.data)', $helper);

        foreach (['Buttons-node', 'List-node', 'Media-node'] as $name) {
            $source = file_get_contents(
                base_path('modules/FlowBuilder/Pages/User/Components/vue-flow/nodes/' . $name . '.vue')
            );

            $this->assertStringContainsString(
                'duplicateNode(node.node, nodes.value)',
                $source,
                $name . ': التكرار يجب أن يمرّ بالدالة المشتركة'
            );
        }
    }

    /**
     * حذف العقدة نفسها يبقى شاملًا: كل وصلاتها داخلةً وخارجة.
     * تضييق النطاق يخصّ حذف مخرج بعينه لا حذف العقدة.
     */
    public function test_deleting_a_node_still_removes_all_of_its_edges(): void
    {
        foreach ([self::BUTTONS, self::LIST] as $path) {
            $source = file_get_contents(base_path($path));

            $this->assertMatchesRegularExpression(
                '/edge\.source === node\.id \|\| edge\.target === node\.id/',
                $source,
                $path . ': حذف العقدة يجب أن يُزيل وصلاتها كلها'
            );
        }
    }
}
