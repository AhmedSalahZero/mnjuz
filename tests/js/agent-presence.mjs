/**
 * دمج حالة الاتصال الآنية في جدول صفحة الأداء.
 *
 * الصفحة تسأل نقطة خفيفة كل بضع ثوانٍ وتدمج الردّ في الصفوف التي حمّلتها.
 * العطل الذي نحرسه: ظهور الموظف «متصلاً» فور دخوله، من غير إعادة تحميل —
 * وألّا يضرّ هذا الدمجُ بقيمةٍ صحيحة موجودة (آخر نشاط أقدم من نافذة النقطة).
 */
import { applyPresence } from '../../resources/js/Composables/agentPresence.js'

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

const agent = (over = {}) => ({
    user_id: 1,
    name: 'سالم',
    online: false,
    last_activity_at: null,
    messages_sent: 7,
    ...over,
})

// ------------------------------------------------ الحالة تتحوّل فوراً

{
    const [row] = applyPresence([agent()], [{ user_id: 1, online: true, last_activity_at: '2026-06-01 12:00:00' }])
    is(row.online, true, 'الموظف الذي دخل يصير متصلاً')
    is(row.last_activity_at, '2026-06-01 12:00:00', 'ويظهر آخر نشاطه')
}

{
    const [row] = applyPresence([agent({ online: true })], [{ user_id: 1, online: false, last_activity_at: null }])
    is(row.online, false, 'وانقطاعه يُظهره غير متصل')
}

// ------------------------------------------------ لا نُتلف ما في الجدول

{
    // للجدول آخر نشاط قبل ثلاثة أيام؛ النقطة لا ترى إلا آخر يومين فتردّ null
    const [row] = applyPresence(
        [agent({ last_activity_at: '2026-05-29 09:00:00' })],
        [{ user_id: 1, online: false, last_activity_at: null }],
    )
    is(row.last_activity_at, '2026-05-29 09:00:00', 'null الوارد لا يمحو آخر نشاط محفوظ')
}

{
    const [row] = applyPresence(
        [agent({ last_activity_at: '2026-06-01 12:30:00' })],
        [{ user_id: 1, online: true, last_activity_at: '2026-06-01 12:00:00' }],
    )
    is(row.last_activity_at, '2026-06-01 12:30:00', 'الأقدم لا يستبدل الأحدث')
}

{
    const [row] = applyPresence(
        [agent({ last_activity_at: '2026-06-01 11:00:00' })],
        [{ user_id: 1, online: true, last_activity_at: '2026-06-01 12:00:00' }],
    )
    is(row.last_activity_at, '2026-06-01 12:00:00', 'الأحدث يستبدل الأقدم')
}

{
    // التنسيقان معاً: «Y-m-d H:i:s» بلا منطقة (UTC) وISO بـ Z — يقارَنان على أنهما اللحظة نفسها
    const [row] = applyPresence(
        [agent({ last_activity_at: '2026-06-01 12:00:00' })],
        [{ user_id: 1, online: true, last_activity_at: '2026-06-01T11:00:00Z' }],
    )
    is(row.last_activity_at, '2026-06-01 12:00:00', 'تنسيقان لحظتان مختلفتان: يُحفظ الأحدث لا المقارنة النصّية')
}

{
    const [row] = applyPresence([agent()], [{ user_id: 1, online: true, last_activity_at: 'تاريخ تالف' }])
    is(row.online, true, 'تاريخ تالف: الحالة تُطبَّق')
    is(row.last_activity_at, null, 'ولا يُكتب التاريخ التالف')
}

// ------------------------------------------------ بقية الصفوف

{
    const rows = applyPresence(
        [agent({ user_id: 1 }), agent({ user_id: 2, name: 'نورة' }), agent({ user_id: 3, name: 'وفاء' })],
        [{ user_id: 2, online: true, last_activity_at: '2026-06-01 12:00:00' }],
    )
    is(rows[0].online, false, 'من ليس في الرد يبقى كما هو')
    is(rows[1].online, true, 'ومن في الرد يتغيّر')
    is(rows[2].online, false, 'وغيره لا يتأثّر')
    is(rows.length, 3, 'عدد الصفوف ثابت')
    is(rows.map((r) => r.user_id).join(','), '1,2,3', 'والترتيب ثابت')
}

{
    // موظف في الرد وليس في الجدول (عضو جديد): لا يُضاف صفّ ناقص المقاييس
    const rows = applyPresence([agent({ user_id: 1 })], [{ user_id: 9, online: true, last_activity_at: '2026-06-01 12:00:00' }])
    is(rows.length, 1, 'لا يُضاف صفّ لعضو غير موجود في الجدول')
}

{
    // المعرّف قد يصل نصّاً من JSON أو رقماً
    const [row] = applyPresence([agent({ user_id: 5 })], [{ user_id: '5', online: true, last_activity_at: null }])
    is(row.online, true, 'معرّف نصّي يطابق رقمياً')
}

// ------------------------------------------------ لا تعديل للمدخلات

{
    const original = agent({ online: false })
    const rows = [original]
    const result = applyPresence(rows, [{ user_id: 1, online: true, last_activity_at: '2026-06-01 12:00:00' }])
    is(original.online, false, 'الصفّ الأصلي لا يُعدَّل')
    ok(result !== rows, 'ويُرجَع مصفوفة جديدة')
    is(result[0].messages_sent, 7, 'وبقية الحقول تُنسخ')
}

// ------------------------------------------------ مدخلات غير سليمة

is(applyPresence(null, []).length, 0, 'جدول null: مصفوفة فارغة')
is(applyPresence(undefined, []).length, 0, 'جدول undefined: مصفوفة فارغة')
{
    const rows = [agent()]
    ok(applyPresence(rows, null) === rows, 'رد null: الجدول كما هو')
    ok(applyPresence(rows, undefined) === rows, 'رد undefined: الجدول كما هو')
    ok(applyPresence(rows, { agents: [] }) === rows, 'رد ليس مصفوفة: الجدول كما هو')
}

if (fail.length) {
    console.error(fail.join('\n'))
    process.exit(1)
}

console.log(`OK — ${checks} فحصاً`)
