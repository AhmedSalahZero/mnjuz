/**
 * اسم القالب في صفحة الإنشاء: نفس التنظيف القديم، ومعه إشارة إن حُذف شيء
 * لتشرح الصفحة القاعدة بدل أن يبدو الحقل معطّلاً.
 */
import { cleanTemplateName } from '../../resources/js/Composables/templateName.js'

let checks = 0
const fail = []
const is = (actual, expected, msg) => {
    checks++
    const a = JSON.stringify(actual)
    const e = JSON.stringify(expected)
    if (a !== e) fail.push(`${msg} — توقّعنا ${e} ووجدنا ${a}`)
}

// ------------------------------------- ما تقبله Meta يمرّ كما هو

is(cleanTemplateName('eid_offer'), { name: 'eid_offer', rejected: false }, 'اسم صالح لا يتغيّر')
is(cleanTemplateName('eid_offer_2026'), { name: 'eid_offer_2026', rejected: false }, 'الأرقام و«_» مقبولة')

// ------------------------------------- الأحرف الكبيرة تُصغَّر ولا تُرفض

is(cleanTemplateName('Eid_Offer_2026'), { name: 'eid_offer_2026', rejected: false }, 'تصغير الأحرف تحويل لا رفض')

// ------------------------------------- ما سوى ذلك يُحذف ويُعلَن

is(cleanTemplateName('عرض'), { name: '', rejected: true }, 'العربية تُحذف ويُشرح السبب')
is(cleanTemplateName('eid عرض'), { name: 'eid', rejected: true }, 'المسافة والعربية في نصّ ملصوق')
is(cleanTemplateName('sale-50%'), { name: 'sale50', rejected: true }, 'الرموز تُحذف')
is(cleanTemplateName('١٢٣'), { name: '', rejected: true }, 'الأرقام الهندية ليست من 0-9')

// ------------------------------------- حقل فارغ ليس خطأ

is(cleanTemplateName(''), { name: '', rejected: false }, 'حقل فارغ بلا رسالة')
is(cleanTemplateName(null), { name: '', rejected: false }, 'null كحقل فارغ')

if (fail.length) {
    console.error(fail.join('\n'))
    process.exit(1)
}

console.log(`OK — ${checks} فحصاً`)
