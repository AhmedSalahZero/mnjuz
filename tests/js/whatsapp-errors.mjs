/**
 * ترجمة أخطاء واتساب إلى سبب مفهوم.
 *
 * العطل: حملة نجحت لرقم مصري وفشلت لرقم سعودي، ولم تجد العميلة في الواجهة
 * إلا «failed». السبب كان في سجلّ الحالة: الكود 131049 — واتساب أوقفت
 * التسليم لذلك المستلِم وحده حفاظاً على صحّة التفاعل.
 */
import {
    explainWhatsappError,
    explainWhatsappErrorCode,
    firstWhatsappError,
    hasWhatsappExplanation,
    WHATSAPP_ERROR_EXPLANATIONS,
} from '../../resources/js/Composables/whatsappErrors.js'

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

// الحمولة كما وصلت فعلاً من الإنتاج للرقم +966594809994
const realFailure = JSON.stringify({
    id: 'wamid.HBgMOTY2NTk0ODA5OTk0FQIAERgUQ0VCQTFCNDhBMThCQzE2OUZFOEIA',
    status: 'failed',
    timestamp: '1787859314',
    recipient_id: '966594809994',
    errors: [{
        code: 131049,
        title: 'This message was not delivered to maintain healthy ecosystem engagement.',
        message: 'This message was not delivered to maintain healthy ecosystem engagement.',
        error_data: { details: 'In order to maintain a healthy ecosystem engagement, the message failed to be delivered.' },
    }],
})

const explained = explainWhatsappError(realFailure)
is(explained.code, 131049, 'الكود يُستخرج من الحمولة الحقيقية')
ok(explained.translatable, 'الكود 131049 له شرح مترجَم')
ok(explained.explanation.includes('marketing message'), 'الشرح يذكر أنها رسالة تسويقية')
ok(!explained.explanation.includes('ecosystem'), 'لا نُعيد مصطلح واتساب كما هو')
ok(explained.raw.includes('healthy ecosystem'), 'النصّ الخام محفوظ للدعم')

// الحمولة الناجحة لا خطأ فيها
is(explainWhatsappError(JSON.stringify({ status: 'delivered', pricing: { billable: true } })), null,
   'رسالة ناجحة بلا خطأ')

// شكل ردّ Graph API عند رفض الإرسال نفسه
is(explainWhatsappError({ data: { error: { code: 131026, message: 'Message undeliverable' } } }).code, 131026,
   'يقرأ data.error أيضاً')
is(explainWhatsappError({ error: { code: 131042, message: 'x' } }).code, 131042, 'يقرأ error المسطّح')

// كود مجهول: نعرض نصّ واتساب بدل الكتمان
const unknown = explainWhatsappError({ errors: [{ code: 999999, title: 'Odd', message: 'Something odd' }] })
is(unknown.code, 999999, 'الكود المجهول يظهر')
ok(!unknown.translatable, 'الكود المجهول بلا ترجمة')
is(unknown.explanation, 'Odd — Something odd', 'يُعرض نصّ واتساب الخام')

// حمولات تالفة لا تُسقط الواجهة
is(explainWhatsappError('ليس JSON'), null, 'نصّ تالف')
is(explainWhatsappError(null), null, 'null')
is(explainWhatsappError(undefined), null, 'undefined')
is(explainWhatsappError('{}'), null, 'كائن فارغ')
is(explainWhatsappError({ errors: [] }), null, 'مصفوفة أخطاء فارغة')
is(firstWhatsappError({ errors: ['نصّ لا كائن'] }), null, 'عنصر خطأ ليس كائناً')

// الأكواد المغطّاة — الأكثر تكراراً في الإنتاج
for (const code of [131042, 131049, 131026, 131048, 130472, 131053, 131050, 131031, 131047]) {
    ok(hasWhatsappExplanation(code), `الكود ${code} يجب أن يكون مشروحاً`)
    ok(explainWhatsappErrorCode(code) !== '', `الكود ${code} بلا نصّ`)
    ok(explainWhatsappErrorCode(String(code)) !== '', `الكود ${code} كنصّ`)
}

is(explainWhatsappErrorCode('غير رقم'), '', 'كود غير رقمي')
is(explainWhatsappErrorCode(null), '', 'كود null')
ok(!hasWhatsappExplanation(424242), 'كود غير معروف')

// كل شرح جملة كاملة لا مصطلح
for (const [code, text] of Object.entries(WHATSAPP_ERROR_EXPLANATIONS)) {
    ok(text.length > 30, `شرح الكود ${code} أقصر من أن يفيد`)
    ok(text.trim().endsWith('.'), `شرح الكود ${code} ليس جملة تامّة`)
}

if (fail.length) {
    console.error(fail.join('\n'))
    process.exit(1)
}

console.log(`OK — ${checks} فحصاً`)
