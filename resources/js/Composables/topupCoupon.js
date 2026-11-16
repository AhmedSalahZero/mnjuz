/**
 * كوبون «إضافة دفعة»: يُدفع المبلغ ناقصاً الخصم، ويزيد الرصيد بالمبلغ كاملاً.
 * الخادم يحسب (POST /billing/coupon/quote) ويُعيد الحساب عند الدفع؛ هنا
 * العرض وحالة «طُبّق على هذا المبلغ» فقط.
 */

/** هل الكوبون المطبَّق ما زال صالحاً للمبلغ المكتوب الآن؟ */
export const quoteMatchesAmount = (quote, amount) => {
    if (!quote) return false

    return Math.abs(Number(quote.amount) - Number(amount)) < 0.005
}

/** المبلغ بخانتين للعرض. */
export const formatMoney = (value) => {
    const number = Number(value)

    return Number.isFinite(number) ? number.toFixed(2) : '0.00'
}

/** ما يُرسَل إلى /pay: الكوبون فقط إن طُبّق على المبلغ نفسه. */
export const paymentPayload = (amount, method, quote) => ({
    amount,
    method,
    ...(quoteMatchesAmount(quote, amount) ? { coupon: quote.code } : {}),
})
