/**
 * كوبون «إضافة دفعة» في الواجهة: يُرسَل مع الدفع فقط إن طُبّق على المبلغ
 * المكتوب الآن — تغيير المبلغ بعد التطبيق يُسقطه.
 */
import { formatMoney, paymentPayload, quoteMatchesAmount } from '../../resources/js/Composables/topupCoupon.js'

let checks = 0
const fail = []
const is = (actual, expected, msg) => {
    checks++
    const a = JSON.stringify(actual)
    const e = JSON.stringify(expected)
    if (a !== e) fail.push(`${msg} — توقّعنا ${e} ووجدنا ${a}`)
}

const quote = { code: 'EID10', amount: 1000, discount: 100, charge: 900 }

is(quoteMatchesAmount(quote, 1000), true, 'المبلغ نفسه')
is(quoteMatchesAmount(quote, '1000.00'), true, 'نصّ من الحقل')
is(quoteMatchesAmount(quote, 1200), false, 'تغيّر المبلغ')
is(quoteMatchesAmount(null, 1000), false, 'بلا كوبون')

is(paymentPayload(1000, 'myfatoorah', quote), { amount: 1000, method: 'myfatoorah', coupon: 'EID10' }, 'يُرسل الكوبون')
is(paymentPayload(1200, 'stripe', quote), { amount: 1200, method: 'stripe' }, 'مبلغ آخر: بلا كوبون')
is(paymentPayload(500, 'paypal', null), { amount: 500, method: 'paypal' }, 'بلا كوبون كما كان')

is(formatMoney(899.999), '900.00', 'تقريب')
is(formatMoney('abc'), '0.00', 'قيمة تالفة')

if (fail.length) {
    console.error(fail.join('\n'))
    process.exit(1)
}

console.log(`topup-coupon: ${checks} checks passed`)
