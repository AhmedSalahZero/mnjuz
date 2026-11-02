/**
 * تحديث مفتاح مساعد الذكاء الاصطناعي حين يغيّره الخادم.
 *
 * العطل: الخادم يغيّر الحالة بنفسه (كلمة بدء أو إيقاف يكتبها العميل، أو تفعيل
 * تلقائي عند أول رسالة)، والصفحة المفتوحة لا تعلم — فتعرض «مفعّل» بعد أن أوقفه
 * العميل، فتنتظر الموظفة رداً لن يأتي. الحدث يصل الآن، وهذا يقرّر هل ينطبق
 * على المحادثة المفتوحة، وألّا يمسّ غيرها.
 */
import { applyAiAssistanceUpdate } from '../../resources/js/Composables/contactUpdates.js'

let checks = 0
const fail = []
const is = (actual, expected, msg) => {
    checks++
    if (actual !== expected) fail.push(`${msg} — توقّعنا "${expected}" ووجدنا "${actual}"`)
}

const open = (over = {}) => ({ id: 7, uuid: 'aaa-111', ai_assistance_enabled: true, ...over })
const event = (over = {}) => ({ contact_id: 7, contact_uuid: 'aaa-111', ai_assistance_enabled: false, ...over })

// ------------------------------------------------ ينطبق على المحادثة المفتوحة

{
    const c = open()
    is(applyAiAssistanceUpdate(c, event({ ai_assistance_enabled: false })), true, 'إيقاف من الخادم يُطبَّق')
    is(c.ai_assistance_enabled, false, 'والمفتاح يصير مطفأً')
}

{
    const c = open({ ai_assistance_enabled: false })
    is(applyAiAssistanceUpdate(c, event({ ai_assistance_enabled: true })), true, 'تفعيل من الخادم يُطبَّق')
    is(c.ai_assistance_enabled, true, 'والمفتاح يصير مفعّلاً')
}

// ------------------------------------------------ لا يمسّ محادثة أخرى

{
    const c = open()
    is(applyAiAssistanceUpdate(c, event({ contact_id: 8, contact_uuid: 'bbb-222' })), false, 'محادثة أخرى لا تُطبَّق')
    is(c.ai_assistance_enabled, true, 'والمفتوحة كما هي')
}

// ------------------------------------------------ تعريف المحادثة

{
    // المعرّف قد يصل نصّاً أو رقماً
    const c = open()
    is(applyAiAssistanceUpdate(c, event({ contact_id: '7', contact_uuid: undefined })), true, 'معرّف نصّي يطابق رقمياً')
}

{
    // حمولة بلا contact_id: يكفي الـ uuid
    const c = open()
    is(applyAiAssistanceUpdate(c, { contact_uuid: 'aaa-111', ai_assistance_enabled: false }), true, 'الـ uuid وحده يكفي')
    is(c.ai_assistance_enabled, false, 'ويُطبَّق')
}

{
    // معرّفان يتعارضان: الرقمي لا يطابق والـ uuid يطابق ⇒ يطابق (أيّهما)
    const c = open()
    is(applyAiAssistanceUpdate(c, event({ contact_id: 99 })), true, 'تطابق الـ uuid يكفي')
}

{
    // لا معرّف ولا uuid في الحمولة ⇒ لا نخمّن
    const c = open()
    is(applyAiAssistanceUpdate(c, { ai_assistance_enabled: false }), false, 'حمولة بلا هوية لا تُطبَّق')
    is(c.ai_assistance_enabled, true, 'والمفتاح كما هو')
}

{
    // محادثة بلا هوية (لم تُحمَّل بعد) لا تتطابق بالصدفة مع undefined
    const c = { ai_assistance_enabled: true }
    is(applyAiAssistanceUpdate(c, { ai_assistance_enabled: false }), false, 'محادثة بلا هوية لا تطابق حمولة بلا هوية')
    is(applyAiAssistanceUpdate(c, event()), false, 'ولا تطابق حمولة لها هوية')
}

// ------------------------------------------------ مدخلات غير سليمة

is(applyAiAssistanceUpdate(null, event()), false, 'محادثة null: لا شيء')
is(applyAiAssistanceUpdate(undefined, event()), false, 'محادثة undefined: لا شيء')
is(applyAiAssistanceUpdate(open(), null), false, 'حمولة null: لا شيء')
is(applyAiAssistanceUpdate(open(), undefined), false, 'حمولة undefined: لا شيء')

{
    // قيمة ليست منطقية ⇒ لا نخمّن (كان سيُفسَّر truthy/falsy فيقلب المفتاح)
    for (const bad of ['false', 0, 1, null, undefined, 'true']) {
        const c = open()
        is(applyAiAssistanceUpdate(c, event({ ai_assistance_enabled: bad })), false, `قيمة ${JSON.stringify(bad)} ليست منطقية`)
        is(c.ai_assistance_enabled, true, `ولا تغيّر المفتاح (${JSON.stringify(bad)})`)
    }
}

{
    // تطبيق القيمة نفسها لا يضرّ
    const c = open({ ai_assistance_enabled: true })
    is(applyAiAssistanceUpdate(c, event({ ai_assistance_enabled: true })), true, 'القيمة نفسها تُطبَّق بلا أثر')
    is(c.ai_assistance_enabled, true, 'وتبقى')
}

if (fail.length) {
    console.error(fail.join('\n'))
    process.exit(1)
}

console.log(`OK — ${checks} فحصاً`)
