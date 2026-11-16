/**
 * اسم قالب واتساب كما يُكتب في صفحة الإنشاء.
 *
 * Meta لا تقبل في الاسم إلا الأحرف الإنجليزية الصغيرة والأرقام و«_»، فالحقل
 * يُصغّر الأحرف ويحذف ما سواها وهو يُكتب. كان الحذف صامتاً: من كتب بالعربية
 * رأى حقلاً لا يكتب شيئاً فظنّه معطّلاً. rejected يُخبر الصفحة أن شيئاً حُذف
 * فتشرح القاعدة تحت الحقل. وتصغير الأحرف الكبيرة تحويلٌ لا رفض.
 *
 * وحدة ESM صرفة تُختبر بـ node (tests/js/template-name.mjs).
 */

/**
 * @param {unknown} raw  ما في الحقل الآن
 * @returns {{name: string, rejected: boolean}}
 */
export function cleanTemplateName(raw) {
    const lowered = String(raw ?? '').toLowerCase()
    const name = lowered.replace(/[^a-z0-9_]/g, '')

    return { name, rejected: name !== lowered }
}
