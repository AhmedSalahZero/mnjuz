/**
 * محرّر المسارات: ما يحفظه القماش وكيف تتبع الوصلات تعديلات العقد.
 *
 * - حذف صفّ من قائمة كان يُبقي وصلات ما بعده على مواضعها القديمة، والخادم يبني
 *   مخرج الصفّ من موضعه: فتقود إلى فرعٍ آخر أو لا شيء.
 * - عقدة الأزرار كانت تحسب الوصلات الداخلة إليها من حصّة أزرارها.
 * - معرّف آخر عقدة حُذفت كان يُعاد لعقدة جديدة.
 * - «تعديلات غير محفوظة» لا تُطلقها إزاحة العرض ولا ما يحسبه vue-flow.
 *
 * وفي الآخر تُترجم كل الملفات التي مسّها الإصلاح بمترجم Vue نفسه.
 */
import { existsSync, readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { parse, compileScript } from '@vue/compiler-sfc'
import { remapListEdges, outgoingEdgeCount, flowSignature, edgeIdFor } from '../../modules/FlowBuilder/Pages/User/Components/vue-flow/flowEdges.js'
import { nextNodeId, duplicateNode } from '../../modules/FlowBuilder/Pages/User/Components/vue-flow/flowNodes.js'

let checks = 0
const fail = []
const is = (actual, expected, msg) => {
    checks++
    if (actual !== expected) fail.push(`${msg} — توقّعنا "${expected}" ووجدنا "${actual}"`)
}
const ok = (cond, msg) => {
    checks++
    if (!cond) fail.push(msg)
}

const edge = (source, sourceHandle, target) => ({
    id: edgeIdFor({ source, target, targetHandle: null }, sourceHandle),
    source,
    sourceHandle,
    target,
    targetHandle: null,
})
const route = (edges) => edges.map((e) => `${e.source}:${e.sourceHandle ?? '-'}>${e.target}`).join(' ')

// ------------------------------------------------ حذف صفّ من القائمة

{
    // [المبيعات a00→X، الدعم a01→Y، الفواتير a02→Z] وقائمة أخرى بالمخارج نفسها.
    const edges = [
        edge('5', 'a00', 'X'), edge('5', 'a01', 'Y'), edge('5', 'a02', 'Z'),
        edge('9', 'a02', 'W'), edge('2', null, '5'),
    ]

    const after = remapListEdges(edges, '5', { section: 0, row: 1 })

    is(route(after), '5:a00>X 5:a01>Z 9:a02>W 2:->5', 'الفواتير تتبع صفّها إلى a01، ووصلة الدعم تُحذف')
    is(after[1].id, edgeIdFor(after[1], 'a01'), 'معرّف الوصلة يتبع مخرجها الجديد فلا يصطدم بوصلة لاحقة')
    ok(after[2] === edges[3], 'وصلات القوائم الأخرى لا تُمسّ')
}

{
    // حذف قسمٍ كامل يُزيح أقسام ما بعده.
    const edges = [edge('5', 'a00', 'A'), edge('5', 'a10', 'B'), edge('5', 'a11', 'C'), edge('5', 'a20', 'D')]
    is(route(remapListEdges(edges, '5', { section: 1 })), '5:a00>A 5:a10>D', 'وصلات القسم المحذوف تُحذف، والقسم الثالث يصير الثاني')
}

{
    const edges = [edge('5', 'a10', 'B')]
    is(route(remapListEdges(edges, '5', { section: 1, row: 3 })), '5:a10>B', 'حذف صفّ بعده لا يغيّر ما قبله')
    is(remapListEdges(null, '5', { section: 0 }).length, 0, 'مدخل غير مصفوفة')
}

// ------------------------------------------------ حصّة الأزرار

{
    const edges = [edge('1', null, '4'), edge('3', null, '4'), edge('4', 'a', 'X'), edge('4', 'b', 'Y')]
    is(outgoingEdgeCount(edges, '4'), 2, 'الوصلتان الداخلتان لا تُحسبان على أزرار العقدة')
    ok(outgoingEdgeCount(edges, '4') <= 3, 'الزرّ الثالث يقبل التوصيل')
}

// ------------------------------------------------ معرّفات العقد

{
    const nodes = [{ id: '1' }, { id: '2' }]
    is(nextNodeId(nodes, 3), '4', 'حُذفت العقدة 3: لا يُعاد معرّفها')
    is(nextNodeId(nodes), '3', 'بلا حدّ أدنى يبقى السلوك القديم')
    is(nextNodeId([{ id: '7' }], 3), '8', 'الأعلى يغلب حين يتجاوز الحدّ')
    is(duplicateNode({ id: '2', data: {} }, nodes, 100, '9').id, '9', 'النسخة تأخذ المعرّف من مولّد القماش')
}

// ------------------------------------------------ التعديلات غير المحفوظة

{
    const saved = JSON.stringify({
        nodes: [{ id: '1', type: 'text', position: { x: 0, y: 0 }, data: { b: 1, a: 2 } }],
        edges: [],
        viewport: { x: 0, y: 0, zoom: 1 },
    })
    const panned = JSON.stringify({
        nodes: [{ id: '1', type: 'text', position: { x: 0, y: 0 }, data: { a: 2, b: 1 }, dimensions: { width: 300 }, selected: true }],
        edges: [],
        viewport: { x: 120, y: -40, zoom: 1.5 },
        lastNodeId: 1,
    })
    const edited = saved.replace('"b":1', '"b":5')

    is(flowSignature(saved), flowSignature(panned), 'الإزاحة والأبعاد والتحديد وترتيب المفاتيح ليست تعديلاً')
    ok(flowSignature(saved) !== flowSignature(edited), 'تغيير بيانات العقدة تعديل')
}

// ------------------------------------------------ القماش

{
    const canvas = readFileSync(new URL('../../modules/FlowBuilder/Pages/User/Components/main-canvas.vue', import.meta.url), 'utf8')

    ok(/rest\.type === 'start' \? \{ \.\.\.rest, deletable: false \}/.test(canvas), 'البداية المحمّلة لا تُحذف')
    ok(/type: 'start',\s*deletable: false,/.test(canvas), 'البداية الجديدة لا تُحذف')
    ok(/const \{ selected, \.\.\.rest \} = node/.test(canvas), 'التحديد المحفوظ لا يُحمَّل')
    ok(/function handleOnDrop[\s\S]*?event\.preventDefault\(\)[\s\S]*?getData\('application\/vueflow'\)/.test(canvas), 'الإفلات يمنع المتصفح من فتح الملف')
    ok(/MESSAGE_NODE_TYPES\.includes\(type\)/.test(canvas), 'نوع غير معروف لا يُنشئ عقدة')
    ok(/lastNodeId: lastNodeId\.value/.test(canvas), 'آخر معرّف يُحفظ مع المسار')
}

// ------------------------------------------------ الملفات تُترجم

const files = [
    'modules/FlowBuilder/Pages/User/View.vue',
    'modules/FlowBuilder/Pages/User/Index.vue',
    'modules/FlowBuilder/Pages/User/Components/FlowsTable.vue',
    'modules/FlowBuilder/Pages/User/Components/main-canvas.vue',
    'modules/FlowBuilder/Pages/User/Components/vue-flow/FlowMedia.vue',
    'modules/FlowBuilder/Pages/User/Components/vue-flow/nodes/Start-node.vue',
    'modules/FlowBuilder/Pages/User/Components/vue-flow/nodes/Text-node.vue',
    'modules/FlowBuilder/Pages/User/Components/vue-flow/nodes/Media-node.vue',
    'modules/FlowBuilder/Pages/User/Components/vue-flow/nodes/Buttons-node.vue',
    'modules/FlowBuilder/Pages/User/Components/vue-flow/nodes/List-node.vue',
    'modules/FlowBuilder/Pages/User/Components/vue-flow/nodes/actions/AddToGroup-node.vue',
    'modules/FlowBuilder/Pages/User/Components/vue-flow/nodes/actions/RemoveFromGroup-node.vue',
    'modules/FlowBuilder/Pages/User/Components/vue-flow/nodes/actions/UpdateContact-node.vue',
    'modules/FlowBuilder/Pages/User/Components/vue-flow/nodes/actions/SendEmail-node.vue',
    'modules/FlowBuilder/Pages/User/Components/vue-flow/nodes/actions/Delay-node.vue',
    'modules/FlowBuilder/Pages/User/Components/vue-flow/nodes/actions/Webhook-node.vue',
    'modules/FlowBuilder/Pages/User/Components/vue-flow/nodes/actions/Conditional-node.vue',
    'modules/FlowBuilder/Pages/User/Components/vue-flow/nodes/actions/AIResponse-node.vue',
    'resources/js/Components/FormInput.vue',
    'resources/js/Components/FormSelect.vue',
    'resources/js/Components/FormTextArea.vue',
]

// العقد تعرّف props بنوعٍ مستورد (NodeProps)، فيحتاج المترجم قراءة تعريفاته.
const fs = { fileExists: existsSync, readFile: (path) => readFileSync(path, 'utf8') }

for (const file of files) {
    const filename = fileURLToPath(new URL(`../../${file}`, import.meta.url))
    // TypeScript ليس في المشروع، فالنوع المستورد يُستبدل بخصائص عادية للترجمة
    // هنا وحدها؛ بناء vite الحقيقي يبقى الفحص الأخير.
    const source = readFileSync(filename, 'utf8')
        .replace(/defineProps<NodeProps>\(\)/g, "defineProps(['id', 'data', 'selected'])")
    const { descriptor, errors } = parse(source, { filename })

    ok(errors.length === 0, `${file}: خطأ في التحليل — ${errors.map((e) => e.message).join('؛ ')}`)

    try {
        compileScript(descriptor, { id: file, inlineTemplate: true, fs })
    } catch (error) {
        fail.push(`${file}: لا يُترجم — ${error.message}`)
    }
}

if (fail.length) {
    console.error(fail.join('\n'))
    process.exit(1)
}

console.log(`OK — ${checks} فحصاً`)
