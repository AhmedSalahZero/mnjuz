/**
 * نافذة تعديل عقد الـ action في منشئ المسارات.
 *
 * العطل: النافذة كانت div.fixed.inset-0 داخل قالب العقدة نفسها، وvue-flow يضع
 * transform على كل عقدة فيصير مرجع الـ fixed هو العقدة لا الشاشة — فتظهر النافذة
 * مقصوصة بحجم العقدة وخلفيتها الداكنة شريطان على الجانبين، ولا تُغلق بالنقر خارجها.
 *
 * هنا نُترجم القوالب فعلاً بمترجم Vue، فالاستبدال في ثمانية ملفات لا يمرّ إن
 * أخلّ بتوازن وسمٍ أو كسر القالب — وهو ما لا يلتقطه فحص النصّ وحده.
 */
import { readFileSync } from 'node:fs'
import { parse, compileScript, compileTemplate } from '@vue/compiler-sfc'

const DIR = new URL('../../modules/FlowBuilder/Pages/User/Components/vue-flow/nodes/actions/', import.meta.url)
const ACTIONS = ['AddToGroup', 'UpdateContact', 'SendEmail', 'Delay', 'Webhook', 'Conditional', 'AIResponse']
// بلا نافذة: الإجراء لا إعداد له، وحالة التفعيل تُبدَّل من شارة البطاقة.
const MODAL_LESS = ['RemoveFromGroup']

let checks = 0
const fail = []
const ok = (cond, msg) => {
    checks++
    if (!cond) fail.push(msg)
}

const load = (name) => {
    const filename = `${name}.vue`
    const source = readFileSync(new URL(filename, DIR), 'utf8')
    const { descriptor, errors } = parse(source, { filename })

    ok(errors.length === 0, `${filename}: خطأ في تحليل الملف — ${errors.map((e) => e.message).join('؛ ')}`)

    const template = compileTemplate({
        source: descriptor.template?.content ?? '',
        filename,
        id: name,
        compilerOptions: { prefixIdentifiers: true },
    })

    ok(template.errors.length === 0, `${filename}: خطأ في ترجمة القالب — ${template.errors.map((e) => e.message ?? e).join('؛ ')}`)

    return { filename, source, descriptor, template }
}

// ------------------------------------------------ العقد الثماني

for (const action of ACTIONS) {
    const { filename, descriptor, template } = load(`${action}-node`)
    const html = descriptor.template?.content ?? ''

    ok(!/class="[^"]*\bfixed\b[^"]*\binset-0\b/.test(html), `${filename}: ما زالت نافذة fixed داخل قالب العقدة`)
    ok(/<NodeEditModal\s+:open="showEditModal"\s+@close="closeEditModal"/.test(html), `${filename}: لا تستعمل NodeEditModal بالشكل المتّفق`)
    ok(/<\/NodeEditModal>/.test(html), `${filename}: وسم NodeEditModal غير مغلق`)
    ok(template.code.includes('NodeEditModal'), `${filename}: القالب المترجم لا يحوي NodeEditModal`)

    // السكربت يستورده، وحالة الفتح والإغلاق ما زالت فيه
    const script = compileScript(descriptor, { id: action })
    ok(/import NodeEditModal from '\.\/NodeEditModal\.vue'/.test(descriptor.scriptSetup?.content ?? ''), `${filename}: لا يستورد NodeEditModal`)
    ok(script.content.includes('showEditModal'), `${filename}: فقد حالة showEditModal`)
    ok(script.content.includes('closeEditModal'), `${filename}: فقد closeEditModal`)

    // الفورم نفسه بقي داخل النافذة: الحفظ والإلغاء
    ok(/<form\s+@submit\.prevent="saveAction"/.test(html), `${filename}: فقد نموذج الحفظ`)
    ok(/@click="closeEditModal"/.test(html), `${filename}: فقد زرّ الإلغاء`)
}

for (const action of MODAL_LESS) {
    const { filename, descriptor, template } = load(`${action}-node`)
    const html = descriptor.template?.content ?? ''

    ok(!/class="[^"]*\bfixed\b[^"]*\binset-0\b/.test(html), `${filename}: نافذة fixed داخل قالب العقدة`)
    ok(!template.code.includes('NodeEditModal'), `${filename}: عادت نافذة تعديل لإجراء بلا إعداد`)
    ok(/role="switch"/.test(html) && /@click\.stop="toggleActive"/.test(html), `${filename}: شارة التفعيل لا تبدّل الحالة`)
    ok(/class="[^"]*\bnodrag\b/.test(html), `${filename}: الضغط على الشارة يسحب العقدة`)

    const script = compileScript(descriptor, { id: action })
    ok(/is_active: !isActive\.value/.test(script.content), `${filename}: toggleActive لا يقلب is_active`)
}

// ------------------------------------------------ النافذة نفسها

{
    const { filename, descriptor, template } = load('NodeEditModal')
    const html = descriptor.template?.content ?? ''
    const script = descriptor.scriptSetup?.content ?? ''

    ok(/<Teleport\s+to="body"/.test(html), `${filename}: لا تُرسم في body — يعود الـ fixed نسبةً للعقدة`)
    ok(template.code.includes('Teleport'), `${filename}: القالب المترجم بلا Teleport`)

    for (const cls of ['nokey', 'nodrag', 'nowheel']) {
        ok(new RegExp(`class="[^"]*\\b${cls}\\b`).test(html), `${filename}: بلا الصنف ${cls}`)
    }

    ok(/@mousedown\.self="close"/.test(html), `${filename}: النقر على الخلفية لا يغلق`)
    ok(!/@click\.self="close"/.test(html), `${filename}: click لا mousedown — سحب تحديد نصّ ينتهي على الخلفية يغلق الفورم`)
    ok(/<slot\s*\/>/.test(html), `${filename}: بلا slot للمحتوى`)
    ok(/z-\[9999\]/.test(html), `${filename}: z-index لا يعلو القماش`)
    ok(/max-h-\[90vh\][^"]*overflow-y-auto|overflow-y-auto[^"]*max-h-\[90vh\]/.test(html), `${filename}: الفورم الطويل لا يُمرَّر داخل النافذة`)
    ok(/role="dialog"/.test(html) && /aria-modal="true"/.test(html), `${filename}: بلا دور dialog`)

    // Escape: يغلق، إلا إن عالجه عنصر داخلي (قائمة اختيار مفتوحة)
    ok(/event\.key === 'Escape'/.test(script) && /!event\.defaultPrevented/.test(script), `${filename}: معالجة Escape ناقصة`)
    ok(/document\.addEventListener\('keydown', onKeydown\)/.test(script), `${filename}: لا يستمع لـ Escape`)
    ok(
        (script.match(/document\.removeEventListener\('keydown', onKeydown\)/g) ?? []).length >= 2,
        `${filename}: المستمع لا يُزال عند الإغلاق وعند التفكيك — يتسرّب بعد حذف العقدة`,
    )
    ok(/panel\.value\?\.focus\(\)/.test(script), `${filename}: التركيز لا ينتقل إلى النافذة`)
    ok(/defineEmits\(\['close'\]\)/.test(script), `${filename}: لا يُصدر close`)
}

if (fail.length) {
    console.error(fail.join('\n'))
    process.exit(1)
}

console.log(`OK — ${checks} فحصاً`)
