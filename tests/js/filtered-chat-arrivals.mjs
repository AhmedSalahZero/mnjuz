/**
 * المحادثات الواصلة أثناء بحثٍ نشط.
 *
 * العطل كما وصفته العميلة: تبحث عن رقم فتُصفّى القائمة إلى محادثة واحدة، ثم
 * تصل رسالة من عميل آخر فتظهر داخل نتائج بحثها — فتظنّ أن للرقم محادثتين.
 * وعندها آلاف المحادثات فيتكرّر هذا كثيراً.
 */
import {
    activeChatSearch,
    chatUrlWithFilters,
    holdArrival,
    shouldHoldArrival,
} from '../../resources/js/Composables/filteredChatArrivals.js'

import { readFileSync } from 'node:fs'

let checks = 0
const fail = []
const is = (actual, expected, msg) => {
    checks++
    if (actual !== expected) fail.push(`${msg} — توقّعنا "${expected}" ووجدنا "${actual}"`)
}
const same = (actual, expected, msg) => is(JSON.stringify(actual), JSON.stringify(expected), msg)
const has = (h, n, msg) => { checks++; if (!h.includes(n)) fail.push(`${msg} — لم نجد "${n}"`) }

// ----------------------------------------- قراءة البحث من العنوان

is(activeChatSearch('?search=0556149709'), '0556149709', 'البحث يُقرأ من العنوان')
is(activeChatSearch('?status=open&search=0556149709&x=1'), '0556149709', 'ومع معاملات أخرى')
is(activeChatSearch('?search=%2B966556149709'), '+966556149709', 'ويُفكّ ترميزه')
is(activeChatSearch('?search=  0556  '), '0556', 'ويُشذَّب')
is(activeChatSearch('?search='), '', 'بحث فارغ = لا بحث')
is(activeChatSearch('?search=%20%20'), '', 'مسافات فقط = لا بحث')
is(activeChatSearch('?status=open'), '', 'بلا معامل بحث')
is(activeChatSearch(''), '', 'عنوان فارغ')
is(activeChatSearch(null), '', 'null')
is(activeChatSearch(undefined), '', 'undefined')

// ----------------------------------------- متى تُحجَز

is(shouldHoldArrival('?search=0556149709'), true, 'مع بحث نشط تُحجَز')
is(shouldHoldArrival(''), false, 'بلا بحث تُدرج كالمعتاد')
is(shouldHoldArrival('?status=open'), false, 'مرشِّح الحالة ليس بحثاً — القائمة تعمل كالمعتاد')
is(shouldHoldArrival('?search=%20'), false, 'بحث بمسافة لا يُعدّ بحثاً')

// ----------------------------------------- العدّ

same(holdArrival([], 5), [5], 'أوّل محادثة')
same(holdArrival([5], 7), [5, 7], 'محادثة ثانية من عميل آخر')
same(holdArrival([5], 5), [5], 'خمس رسائل من العميل نفسه = محادثة واحدة')
same(holdArrival([5], '5'), [5], 'ولا يُخدع باختلاف النوع')
same(holdArrival([], null), [], 'بلا معرّف لا يُعدّ شيء')
same(holdArrival([], undefined), [], 'undefined كذلك')
same(holdArrival([], ''), [], 'معرّف فارغ')
same(holdArrival(null, 5), [5], 'قائمة غير مهيّأة')

// الأصل لا يُعدَّل في مكانه — Vue يعتمد على استبدال المرجع
const original = [1]
holdArrival(original, 2)
same(original, [1], 'الدالّة لا تُعدّل المصفوفة الأصلية')

// ----------------------------------------- فتح محادثة يُبقي المرشِّحات

is(chatUrlWithFilters('abc', '?search=0556149709'), '/chats/abc?search=0556149709',
   'العطل نفسه: البحث كان يسقط عند فتح المحادثة')
is(chatUrlWithFilters('abc', '?search=055&status=open'), '/chats/abc?search=055&status=open',
   'وكل المرشِّحات تبقى')
is(chatUrlWithFilters('abc', '?search=055&contact_page=3'), '/chats/abc?search=055',
   'صفحة القائمة لا معنى لها في عنوان محادثة')
is(chatUrlWithFilters('abc', '?contact_page=3'), '/chats/abc',
   'ولا يبقى علامة استفهام فارغة')
is(chatUrlWithFilters('abc', ''), '/chats/abc', 'بلا مرشِّحات: العنوان كما كان')
is(chatUrlWithFilters('abc', null), '/chats/abc', 'null')
is(chatUrlWithFilters('abc', '?search=%2B966556149709'), '/chats/abc?search=%2B966556149709',
   'الترميز يبقى صالحاً')

// ----------------------------------------- حراسة على الشاشة

const page = readFileSync(new URL('../../resources/js/Pages/User/Chat/Index.vue', import.meta.url), 'utf8')

has(page, 'shouldHoldArrival(window.location.search)',
    'الإدراج يجب أن يُفحص قبله وجود بحث نشط')
has(page, 'heldArrivals', 'المحجوزات تُعدّ')
has(page, "$t('New conversations arrived that are not shown')",
    'شريط يُخبر بالمحادثات المحجوزة — لا نُسقطها صامتين')

// النصّ يقول المؤكَّد: «غير معروضة». فالمحادثة قد تطابق البحث وتكون في صفحة
// لم تُحمَّل بعد، والحدث لا يحمل الرقم فلا سبيل إلى تمييز الحالتين.
checks++
if (page.includes('outside your search')) {
    fail.push('النصّ يدّعي أن المحادثة خارج البحث — وقد تكون داخله في صفحة غير محمَّلة')
}
has(page, 'showAllChats', 'وطريقة لمسح البحث وعرضها')
has(page, "params.delete('search')", 'مسح البحث لا يُغلق المحادثة المفتوحة — يمسح البحث وحده')

const table = readFileSync(new URL('../../resources/js/Components/ChatComponents/ChatTable.vue', import.meta.url), 'utf8')

has(table, 'chatUrlWithFilters(contact.uuid, window.location.search)',
    'فتح المحادثة يجب أن يُبقي المرشِّحات في العنوان')
checks++
if (/router\.visit\('\/chats\/' \+ contact\.uuid/.test(table)) {
    fail.push('ما زال فتح المحادثة ينتقل إلى عنوان مجرّد يُسقط البحث')
}

// الإدراج غير المشروط هو العطل نفسه
const insertion = page.slice(page.indexOf('} else if (chat[0].value.contact_uuid) {'))
const guardAt = insertion.indexOf('shouldHoldArrival')
const pushAt = insertion.indexOf('rows.value.data.push')
checks++
if (guardAt === -1 || guardAt > pushAt) {
    fail.push('الفحص يجب أن يسبق الإدراج، وإلا أُدرجت المحادثة ثمّ حُجزت')
}

if (fail.length) {
    console.error(fail.join('\n'))
    process.exit(1)
}

console.log(`OK — ${checks} فحصاً`)
