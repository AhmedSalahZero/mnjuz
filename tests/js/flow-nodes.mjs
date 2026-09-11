/**
 * معرّفات عقد الـ flow.
 *
 * العطل: التوليد كان بطريقتين مختلفتين في المكان نفسه — السحب والإفلات
 * «الأكبر + 2» فيقفز ويترك فجوات، وزر Duplicate «عدد العقد + 1» فيمشي في
 * تلك الفجوات. فتصطدمان: عقدتان بمعرّف واحد تجعلان قاعدة «مخرج واحد لهدف
 * واحد» تراهما عقدة واحدة، فتوصيل النسخة يحذف وصلة الأصل.
 *
 * وكانت النسخة تشير إلى كائن data نفسه، فتعديلها يعدّل الأصل.
 */
import { nextNodeId, cloneNodeData, duplicateNode } from '../../modules/FlowBuilder/Pages/User/Components/vue-flow/flowNodes.js'

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

const unique = (nodes) => nodes.length === new Set(nodes.map((n) => n.id)).size

// ------------------------------------------------ الحالة التي كانت تصطدم

{
    // flow جديد: start ثم عقدة مسحوبة ثم تكراران
    const nodes = [{ id: '1' }]
    for (let i = 0; i < 3; i++) {
        nodes.push({ id: nextNodeId(nodes) })
    }

    is(nodes.map((n) => n.id).join(','), '1,2,3,4', 'التسلسل متّصل بلا فجوات')
    ok(unique(nodes), 'لا معرّف مكرّر')
}

{
    // الفجوة القديمة: '3' موجود و'2' لا. المعرّف التالي يملأ ولا يصطدم
    const nodes = [{ id: '1' }, { id: '3' }]
    const id = nextNodeId(nodes)

    is(id, '4', 'التالي بعد الأكبر')
    ok(!nodes.some((n) => n.id === id), 'ولا يساوي معرّفًا موجودًا')
}

{
    // حذف عقدة من الوسط: العدد ينقص لكن المعرّف لا يعود
    const nodes = [{ id: '1' }, { id: '2' }, { id: '3' }, { id: '4' }]
    nodes.splice(1, 1) // حذف '2' — العدد صار 3
    const id = nextNodeId(nodes)

    is(id, '5', 'المعرّف يتبع الأكبر لا العدد')
    ok(!nodes.some((n) => n.id === id), 'فلا يصطدم بعقدة باقية')
}

{
    // flow محفوظ من نسخة قديمة فيه تكرار: التالي يتجاوزه
    const nodes = [{ id: '1' }, { id: '3' }, { id: '3' }, { id: '4' }]
    is(nextNodeId(nodes), '5', 'flow قديم فيه تكرار لا يُنتج تكرارًا جديدًا')
}

{
    // معرّف غير رقمي: كان Math.max يرجع NaN فيصير معرّف كل عقدة "NaN"
    const nodes = [{ id: '1' }, { id: 'start' }, { id: '2' }]
    const id = nextNodeId(nodes)

    is(id, '3', 'المعرّف غير الرقمي لا يُفسد الحساب')
    ok(id !== 'NaN', 'ولا يُنتج "NaN"')
}

is(nextNodeId([]), '1', 'flow فارغ يبدأ من 1')
is(nextNodeId(null), '1', 'مدخل غير مصفوفة لا يُسقط شيئًا')

{
    // مئة تكرار متتالٍ: لا اصطدام في أي خطوة
    const nodes = [{ id: '1' }]
    for (let i = 0; i < 100; i++) {
        nodes.push({ id: nextNodeId(nodes) })
    }
    ok(unique(nodes), 'مئة عقدة بلا تكرار')
}

// ------------------------------------------------ النسخة مستقلّة

{
    const original = {
        id: '3',
        type: 'buttons',
        label: 'buttons node',
        position: { x: 40, y: 60 },
        data: { uuid: 'u1', metadata: { fields: { body: 'الأصل', buttons: { button1: 'نعم' } } } },
    }
    const nodes = [{ id: '1' }, original]

    const copy = duplicateNode(original, nodes)

    is(copy.id, '4', 'النسخة تأخذ معرّفًا جديدًا')
    is(copy.type, 'buttons', 'ونوع الأصل')
    is(copy.position.x, 140, 'وإزاحة أفقية')
    is(copy.position.y, 160, 'وإزاحة رأسية')
    is(copy.data.metadata.fields.body, 'الأصل', 'والبيانات منسوخة')

    copy.data.metadata.fields.body = 'النسخة'
    copy.data.metadata.fields.buttons.button1 = 'لا'

    is(original.data.metadata.fields.body, 'الأصل', 'تعديل النسخة لا يمسّ الأصل')
    is(original.data.metadata.fields.buttons.button1, 'نعم', 'ولا يمسّ الحقول العميقة')

    ok(original.position.x === 40, 'وموضع الأصل لا يتحرّك')
}

{
    // تكراران متتاليان لنفس العقدة
    const original = { id: '2', type: 'text', position: { x: 0, y: 0 }, data: { a: 1 } }
    const nodes = [{ id: '1' }, original]

    const first = duplicateNode(original, nodes)
    nodes.push(first)
    const second = duplicateNode(original, nodes)
    nodes.push(second)

    ok(first.id !== second.id, 'تكراران متتاليان بمعرّفين مختلفين')
    ok(unique(nodes), 'ولا تكرار في الـ flow')
}

{
    // مدخلات ناقصة لا تُسقط النسخ
    const copy = duplicateNode({ id: '2' }, [{ id: '2' }])
    is(copy.id, '3', 'عقدة بلا موضع تأخذ معرّفًا صحيحًا')
    is(copy.position.x, 100, 'وموضعًا افتراضيًا مزاحًا')
}

is(cloneNodeData(null), null, 'بيانات فارغة تبقى كما هي')
is(cloneNodeData('نص'), 'نص', 'والقيمة غير الكائن تبقى كما هي')

{
    const data = { list: [1, 2, 3] }
    const clone = cloneNodeData(data)
    clone.list.push(4)
    is(data.list.length, 3, 'المصفوفات داخل البيانات تُنسخ لا تُشارَك')
}

if (fail.length) {
    console.error(fail.join('\n'))
    process.exit(1)
}

console.log(`OK — ${checks} فحصاً`)
