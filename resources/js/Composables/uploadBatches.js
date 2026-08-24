/**
 * تقسيم المرفقات إلى دفعات تسع في طلب واحد.
 *
 * الملفات تُرسَل مجتمعةً في طلب HTTP واحد، و`post_max_size` يحكم الحمولة كلّها
 * لا كل ملف. وكان الفحص لكل ملف على حدة، فثلاثة ملفات مقبولة فرادى (١٩ و٢٧
 * و٤٦ ميغابايت) تصير حمولةً بـ٩٢ — يرفضها الخادم قبل أن تبلغ PHP، فيقف الرفع
 * عند ٢٪ بلا رسالة ولا خطأ.
 *
 * والتقسيم أولى من الرفض: المستخدم أراد إرسال ملفاته، ولا شأن له بحدود الخادم.
 * الترتيب محفوظ داخل كل دفعة وبينها، فتصل الملفات كما اختارها.
 */

/** هامش للحقول الأخرى في الحمولة — المعرّفات والتعليق وحدود multipart. */
const OVERHEAD_BYTES = 256 * 1024

/**
 * @param {Array<{file: {size?: number}}>} attachments
 * @param {number} maxRequestBytes سقف الطلب الواحد
 * @returns {Array<Array<object>>} دفعات مرتّبة
 */
export function splitIntoBatches(attachments, maxRequestBytes) {
    const items = Array.isArray(attachments) ? attachments : []

    if (items.length === 0) {
        return []
    }

    const limit = Number(maxRequestBytes)

    // بلا سقف معروف نُبقي الدفعة كما هي: التقسيم بلا داعٍ يُكثر الطلبات.
    if (!Number.isFinite(limit) || limit <= 0) {
        return [items]
    }

    const budget = Math.max(1, limit - OVERHEAD_BYTES)
    const batches = []
    let current = []
    let currentBytes = 0

    for (const item of items) {
        const size = Number(item?.file?.size ?? 0)

        // ملفٌ أكبر من السقف وحده ينعزل هنا تلقائياً: يُفرَغ ما قبله، ثم لا
        // يقبل التالي الانضمام إليه — فيُرسَل منفرداً ويفشل بخطأ صريح من
        // الخادم بدل أن يجرّ معه ملفات كانت ستنجح. لا يحتاج فرعاً خاصّاً.
        if (current.length && currentBytes + size > budget) {
            batches.push(current)
            current = []
            currentBytes = 0
        }

        current.push(item)
        currentBytes += size
    }

    if (current.length) {
        batches.push(current)
    }

    return batches
}

/** مجموع أحجام المرفقات بالبايت. */
export function totalBytes(attachments) {
    return (Array.isArray(attachments) ? attachments : [])
        .reduce((sum, item) => sum + Number(item?.file?.size ?? 0), 0)
}
