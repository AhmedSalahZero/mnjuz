/**
 * حذف وصلات المخرج مقصورًا على عقدته.
 *
 * العطل: معرّفات المخارج مشتركة بين كل العقد من النوع نفسه — الزر الأول
 * 'a' في كل عقدة Interactive Buttons. وكان الحذف يفلتر بـ sourceHandle
 * وحده، فالعقدة تحذف وصلات غيرها. يوصّل المستخدم عقدة ثم يكتب حرفًا في
 * عقدة أخرى فتختفي وصلته الأولى، ثم تُحفظ محذوفة عند أول ضغطة Save.
 */
import { edgeIdsForHandle } from '../../modules/FlowBuilder/Pages/User/Components/vue-flow/flowEdges.js'

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

const edge = (id, source, sourceHandle, target) => ({ id, source, sourceHandle, target })

// ------------------------------------------------ الحالة التي شكا منها المستخدم

{
    // العقدة 2 وصّلت زرها الأول، والعقدة 3 زرها الأول فارغ فتطلب حذف 'a'
    const edges = [
        edge('e1', '2', 'a', '4'),
        edge('e2', '2', 'b', '5'),
        edge('e3', '3', 'a', '6'),
    ]

    const removed = edgeIdsForHandle(edges, '3', 'a')

    is(removed.length, 1, 'العقدة تحذف وصلة واحدة لا وصلتين')
    is(removed[0], 'e3', 'وهي وصلتها هي')
    ok(!removed.includes('e1'), 'وصلة الزر الأول في العقدة الأخرى يجب أن تبقى')
}

{
    // الحالة المضمونة: مخرج CTA ('d') — كان يُحذف مع كل حرف في أي عقدة أزرار
    const edges = [
        edge('cta', '2', 'd', '9'),
        edge('other', '7', 'a', '8'),
    ]

    is(edgeIdsForHandle(edges, '3', 'd').length, 0, 'عقدة لا تملك مخرج d لا تحذف شيئًا')
    is(edgeIdsForHandle(edges, '2', 'd')[0], 'cta', 'وصاحبة المخرج تحذف وصلتها')
}

{
    // ثلاث عقد أزرار، كلها موصّلة من الزر الأول
    const edges = ['2', '3', '4'].map((id, i) => edge(`e${i}`, id, 'a', `t${i}`))
    const removed = edgeIdsForHandle(edges, '3', 'a')

    is(removed.length, 1, 'واحدة فقط تُحذف من بين ثلاث تشترك في معرّف المخرج')
    is(removed[0], 'e1', 'وهي وصلة العقدة الطالبة')
}

// ------------------------------------------------ عقد القوائم

{
    // معرّف الصف مشترك بين عقد List: 'a00' في كل واحدة
    const edges = [
        edge('l1', '5', 'a00', '9'),
        edge('l2', '6', 'a00', '10'),
    ]

    const removed = edgeIdsForHandle(edges, '6', 'a00')

    is(removed.length, 1, 'حذف صف من قائمة لا يمسّ الصف المقابل في قائمة أخرى')
    is(removed[0], 'l2', 'الوصلة المحذوفة هي وصلة العقدة الطالبة')
}

// ------------------------------------------------ المخرج نفسه في العقدة نفسها

{
    const edges = [
        edge('a1', '2', 'a', '4'),
        edge('a2', '2', 'a', '5'),
        edge('b1', '2', 'b', '6'),
    ]

    const removed = edgeIdsForHandle(edges, '2', 'a')

    is(removed.length, 2, 'كل وصلات المخرج نفسه في العقدة نفسها تُحذف')
    ok(!removed.includes('b1'), 'ومخرج آخر في العقدة نفسها لا يُمسّ')
}

// ------------------------------------------------ المدخلات الشاذة

is(edgeIdsForHandle(null, '2', 'a').length, 0, 'مدخل غير مصفوفة لا يُسقط شيئًا')
is(edgeIdsForHandle([], '2', 'a').length, 0, 'flow بلا وصلات')
is(edgeIdsForHandle([edge('e', '2', 'a', '3')], null, 'a').length, 0, 'بلا معرّف عقدة لا يُحذف شيء')
is(edgeIdsForHandle([edge('e', '2', 'a', '3')], '2', null).length, 0, 'بلا معرّف مخرج لا يُحذف شيء')
is(edgeIdsForHandle([null, edge('e', '2', 'a', '3')], '2', 'a').length, 1, 'عنصر تالف لا يوقف الحذف')

{
    // العقدة هدفًا لا مصدرًا: لا تُحذف
    const edges = [edge('in', '9', 'a', '2')]
    is(edgeIdsForHandle(edges, '2', 'a').length, 0, 'الوصلة الداخلة إلى العقدة ليست من مخرجها')
}

if (fail.length) {
    console.error(fail.join('\n'))
    process.exit(1)
}

console.log(`OK — ${checks} فحصاً`)
