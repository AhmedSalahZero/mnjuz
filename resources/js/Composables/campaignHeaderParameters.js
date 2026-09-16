/**
 * معاملات ترويسة القالب في نموذج الحملة.
 *
 * العطل الأوّل: قالب ترويسته VIDEO أنشأه العميل في Meta بلا example يصل
 * إلينا بلا header_handle، فكانت قائمة المعاملات تبقى فارغة — فلا يظهر زرّ
 * اختيار الفيديو أصلاً، ويمرّ التحقّق لأنه مشروط بوجود معاملات، وتُحفظ
 * الحملة بلا وسائط. وMeta عندها ترسل القالب بمثاله المرفق: الفيديو الذي
 * رُفع يوم أُنشئ القالب — أي الأقدم دائماً.
 *
 * العطل الثاني: header_handle مصفوفة، وكانت تُنتج معاملاً لكل عنصر. وMeta
 * لا تقبل في ترويسة الوسائط إلا معاملاً واحداً، فكان الاختيار يذهب إلى
 * الأوّل وتُرسل البقيّة فارغة.
 *
 * فترويسة الوسائط لها معامل واحد دائماً: وُجد المثال أو لم يوجد.
 */

export const MEDIA_HEADER_FORMATS = ['IMAGE', 'VIDEO', 'DOCUMENT']

/**
 * @param {string|null} format صيغة الترويسة كما في القالب
 * @param {object|null} headerExamples محتوى example من مكوّن HEADER
 * @returns {Array<object>}
 */
export function buildHeaderParameters(format, headerExamples) {
    if (format === 'TEXT') {
        const texts = headerExamples?.header_text

        return Array.isArray(texts)
            ? texts.map((value) => ({ type: 'text', selection: 'static', value }))
            : []
    }

    if (!MEDIA_HEADER_FORMATS.includes(format)) {
        return []
    }

    const handles = Array.isArray(headerExamples?.header_handle)
        ? headerExamples.header_handle
        : []

    return [{
        type: format,
        selection: 'default',
        value: null,
        url: handles[0] ?? null,
    }]
}

/**
 * هل هذا الملف السابق هو المختار فعلاً؟
 *
 * نقرأ المعامل نفسه لا متغيّراً منفصلاً: كان الاختيار محفوظاً في
 * selectedHistoryUuid، ولا يُصفّره تحميل قالب جديد. فيبقى الفيديو القديم
 * معلَّماً «✓ مختار» بينما القيمة الحقيقية صارت فارغة — يرى العميل ملفاً
 * مختاراً ولم يُختَر شيء.
 */
export function isHistoryItemSelected(parameter, item) {
    if (!parameter || !item) {
        return false
    }

    return parameter.selection === 'history' && parameter.value === item.uuid
}

/**
 * اسم الملف المختار، أو null إن تعذّر تحديده.
 *
 * كان يُقرأ عبر selectedHistoryUuid كذلك، فمتى اختلف عن قيمة المعامل عُرض
 * اسم ملف وأُرسل آخر.
 */
export function headerMediaLabel(parameter, mediaHistory = []) {
    if (!parameter || !parameter.value) {
        return null
    }

    if (parameter.selection === 'history') {
        const list = Array.isArray(mediaHistory) ? mediaHistory : []

        return list.find((entry) => entry.uuid === parameter.value)?.name ?? null
    }

    if (parameter.selection === 'upload') {
        return parameter.value?.name ?? null
    }

    return typeof parameter.value === 'string' ? parameter.value : null
}
