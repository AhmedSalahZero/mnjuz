/**
 * منطق builder قالب البريد بلا واجهة: العناصر وقيمها، والنقل والتكرار،
 * والملخّص بأسماء المتغيّرات، والقوالب الجاهزة، وقراءة التصميم من حمولة
 * الحملة، وإدراج المتغيّر عند المؤشّر.
 */
import {
    alignToCss, BLOCK_TYPES, blockErrors, blockSummary, createBlock, duplicateBlock, hasContent, insertBlockAfter, labelPlaceholders, moveBlock,
} from '../../resources/js/Composables/emailDesign.js'
import { presetCards, presetDesign } from '../../resources/js/Composables/emailPresets.js'
import { parseMessageDesign, parseMessagePayload } from '../../resources/js/Composables/campaignDisplay.js'
import { insertAtCursor } from '../../resources/js/Composables/insertAtCursor.js'

let checks = 0
const fail = []
const is = (actual, expected, msg) => {
    checks++
    const a = JSON.stringify(actual)
    const e = JSON.stringify(expected)
    if (a !== e) fail.push(`${msg} — توقّعنا ${e} ووجدنا ${a}`)
}
const ok = (cond, msg) => is(Boolean(cond), true, msg)

// vue-i18n بديلاً: يستبدل {param} كما يفعل.
const t = (key, params = {}) => key.replace(/\{(\w+)\}/g, (m, name) => (name in params ? params[name] : m))

// ------------------------------------- العناصر

is(BLOCK_TYPES.length, 10, 'عشرة عناصر')
const heading = createBlock('heading', t)
ok(/^[A-Za-z0-9_-]{1,40}$/.test(heading.id), 'المعرّف يقبله الخادم')
is(heading.props, { text: 'Your headline here', color: '#111827', align: 'center', font_size: 28 }, 'قيم العنوان الافتراضية كالخادم')
is(createBlock('header', t).props.text, '{organization_name}', 'الترويسة باسم المنشأة')
is(createBlock('button', t, { url: 'https://x.example' }).props.url, 'https://x.example', 'خصائص تُمرَّر عند الإنشاء')
ok(createBlock('text', t).id !== createBlock('text', t).id, 'معرّفات فريدة')

const copy = duplicateBlock(heading)
ok(copy.id !== heading.id, 'التكرار بمعرّف جديد')
copy.props.text = 'معدّل'
is(heading.props.text, 'Your headline here', 'التكرار نسخة مستقلّة')

// ------------------------------------- النقل والإضافة

const a = { id: 'a' }, b = { id: 'b' }, c = { id: 'c' }
is(moveBlock([a, b, c], 0, 1).map((x) => x.id), ['b', 'a', 'c'], 'نزول خطوة')
is(moveBlock([a, b, c], 2, -1).map((x) => x.id), ['a', 'c', 'b'], 'صعود خطوة')
is(moveBlock([a, b, c], 0, -1).map((x) => x.id), ['a', 'b', 'c'], 'الأول لا يصعد')
is(moveBlock([a, b, c], 2, 1).map((x) => x.id), ['a', 'b', 'c'], 'الأخير لا ينزل')
is(insertBlockAfter([a, b, c], 'a', { id: 'n' }).map((x) => x.id), ['a', 'n', 'b', 'c'], 'الإضافة بعد المحدد')
is(insertBlockAfter([a, b], null, { id: 'n' }).map((x) => x.id), ['a', 'b', 'n'], 'بلا تحديد: في الآخر')

// ------------------------------------- الملخّص والمحاذاة

const placeholders = [{ value: '{first_name}', label: 'اسم العميل' }, { value: '{organization_name}', label: 'اسم المنشأة' }]
is(labelPlaceholders('أهلاً {first_name} من {organization_name} {unknown}', placeholders), 'أهلاً [اسم العميل] من [اسم المنشأة] {unknown}', 'المتغيّرات بأسمائها')
is(blockSummary(createBlock('header', t), placeholders, t), '[اسم المنشأة]', 'ملخّص الترويسة')
is(blockSummary({ type: 'spacer', props: { height: 32 } }, [], t), '32px', 'ملخّص المسافة')
is(blockSummary({ type: 'social', props: { links: [{ network: 'x', url: 'u' }, { network: 'instagram', url: 'u' }] } }, [], t), 'X، Instagram', 'ملخّص روابط التواصل')
ok(blockSummary({ type: 'text', props: { text: 'س'.repeat(100) } }, [], t).endsWith('…'), 'الملخّص الطويل يُقصّ')

is(alignToCss('start', 'rtl'), 'right', 'البداية يمين في العربية')
is(alignToCss('end', 'rtl'), 'left', 'النهاية يسار في العربية')
is(alignToCss('start', 'ltr'), 'left', 'البداية يسار في الإنجليزية')
is(alignToCss('center', 'rtl'), 'center', 'الوسط')

ok(!hasContent({ blocks: [{ type: 'divider', props: { color: '#000000' } }] }), 'فاصل وحده بلا محتوى')
ok(hasContent({ blocks: [{ type: 'image', props: { src: 'https://x/a.png' } }] }), 'صورة محتوى')

is(blockErrors({ 'design.blocks.1.props.url': ['رابط خطأ'], 'design.blocks.10.props.text': 'x', name: 'n' }, 1), { url: 'رابط خطأ' }, 'أخطاء العنصر بمساره')

// ------------------------------------- القوالب الجاهزة

const cards = presetCards()
is(cards.length, 11, 'أحد عشر قالباً جاهزاً')
is(cards[0].key, 'blank', 'التصميم الفارغ أولاً')
is(presetDesign('blank', t).blocks, [], 'الفارغ بلا عناصر')
is(presetDesign('unknown', t), null, 'مفتاح مجهول')

for (const card of cards.filter((item) => item.key !== 'blank')) {
    const design = presetDesign(card.key, t)
    ok(design.blocks.length >= 3, `${card.key}: عناصر`)
    is(design.blocks[0].type, 'header', `${card.key}: يبدأ بالترويسة`)
    is(design.blocks[design.blocks.length - 1].type, 'footer', `${card.key}: ينتهي بالتذييل`)
    is(design.blocks[0].props.bg_color, card.color, `${card.key}: الترويسة بلون القالب`)
    ok(design.blocks.every((block) => BLOCK_TYPES.includes(block.type)), `${card.key}: أنواع معروفة`)
    ok(new Set(design.blocks.map((block) => block.id)).size === design.blocks.length, `${card.key}: معرّفات فريدة`)
}

const offer = presetDesign('special_offer', t)
ok(offer.blocks.some((block) => String(block.props.text ?? '').includes('{first_name}')), 'نصّ القالب يحمل متغيّر اسم العميل حرفياً')
is(presetDesign('welcome', t, 'ltr').settings.direction, 'ltr', 'اتجاه القالب من الواجهة')

// ------------------------------------- التصميم من حمولة الحملة

is(parseMessageDesign('{"text":"x","design":{"settings":{},"blocks":[{"id":"a","type":"text","props":{}}]}}').blocks.length, 1, 'حملة مصمَّمة')
is(parseMessageDesign('{"text":"x"}'), null, 'حملة نصّية')
is(parseMessageDesign('{"design":{"blocks":[]}}'), null, 'تصميم فارغ')
is(parseMessageDesign('not json'), null, 'نصّ تالف')
is(parseMessageDesign(null), null, 'null')
is(Object.keys(parseMessagePayload('{"text":"x","design":{"blocks":[{}]}}')), ['text', 'media', 'subject', 'template'], 'شكل parseMessagePayload كما كان')

// ------------------------------------- الإدراج عند المؤشّر

is(insertAtCursor('أهلاً ', '{first_name}', null), { text: 'أهلاً {first_name}', caret: 18 }, 'بلا حقل: في الآخر')
is(insertAtCursor('ab', 'X', { selectionStart: 1, selectionEnd: 1 }), { text: 'aXb', caret: 2 }, 'عند المؤشّر')
is(insertAtCursor('abcd', 'X', { selectionStart: 1, selectionEnd: 3 }), { text: 'aXd', caret: 2 }, 'مكان المحدد')

if (fail.length) {
    console.error(fail.join('\n'))
    process.exit(1)
}

console.log(`email-design: ${checks} checks passed`)
