/**
 * مصدر الوسائط في معاينة القالب.
 *
 * العطل: معاينة الحملة كانت تعرض صورة العنصر النائب دائماً مهما اختار
 * المستخدم — لأن CampaignForm لا يمرّر خاصية placeholder فتبقى على قيمتها
 * الافتراضية true. فالعميل يختار صورة، لا يتغيّر شيء أمامه، فيظنّها لم
 * تُقبل. وحتى لو مُرّرت، كانت المعاينة تقرأ value وهو عند الرفع كائن File
 * لا رابطاً.
 *
 * المعامل الواحد يصل بثلاثة أشكال:
 *   - رفع جديد : value = File،        url = data:...  (من FileReader)
 *   - ملف سابق : value = المعرّف/المسار، url = رابط الملف
 *   - حملة محفوظة: value = الرابط،      ولا url أصلاً
 *
 * ولذلك نقرأ url أوّلاً ثم value، ولا نقبل إلا ما يصلح فعلاً لـsrc. وهذا
 * القيد ليس تجميلاً: القالب الجديد يأتي من Meta بـheader_handle مثل
 * "4::aW1hZ2Uv..." وهو ليس رابطاً — لو مرّرناه لـ<img> ظهرت أيقونة صورة
 * مكسورة، وهي أسوأ من العنصر النائب لأنها تبدو خطأً في النظام.
 */

/** الصيغ التي لها معاينة وسائط. */
export const MEDIA_FORMATS = ['IMAGE', 'VIDEO', 'DOCUMENT']

/**
 * الاختيارات التي تعني «العميل اختار هذا الملف».
 *
 * default ليست منها: القالب يأتي من Meta ومعه example.header_handle، وهو
 * رابط كامل لصورة المثال. فعرضه في نموذج الإنشاء يُظهر صورةً قبل أن يختار
 * العميل شيئاً، فيظنّ أن الترويسة جاهزة ثم يُردّ عليه بأن الحقل مطلوب.
 * أمّا صفحة عرض حملة محفوظة فتمرّر placeholder=false صراحةً، فتعرض ما
 * أُرسل فعلاً أياً كان اختياره.
 */
export const CHOSEN_SELECTIONS = ['upload', 'history']

/** هل النصّ صالح لأن يوضع في src/href؟ */
const isRenderableSource = (value) => {
    if (typeof value !== 'string') {
        return false
    }

    return /^(https?:\/\/|data:|blob:|\/)/i.test(value.trim())
}

/**
 * رابط المعاينة لمعامل ترويسة واحد، أو '' إن لم يوجد ما يُعرض.
 *
 * @param {object|null|undefined} parameter
 * @returns {string}
 */
export function mediaPreviewSource(parameter) {
    if (!parameter || typeof parameter !== 'object') {
        return ''
    }

    for (const candidate of [parameter.url, parameter.value]) {
        if (isRenderableSource(candidate)) {
            return candidate.trim()
        }
    }

    return ''
}

/**
 * رابط معاينة ترويسة القالب كاملاً (يقرأ header.format و header.parameters[0]).
 *
 * @param {object|null|undefined} header
 * @returns {string}
 */
export function headerPreviewSource(header) {
    if (!header || !MEDIA_FORMATS.includes(header.format)) {
        return ''
    }

    const parameters = Array.isArray(header.parameters) ? header.parameters : []

    return mediaPreviewSource(parameters[0])
}

/**
 * هل تُعرض صورة العنصر النائب بدل الوسائط الحقيقية؟
 *
 * @param {object|null|undefined} header
 * @returns {boolean}
 */
export function shouldShowPlaceholder(header) {
    return headerPreviewSource(header) === ''
}

/**
 * رابط المعاينة لما اختاره العميل وحده — لا لمثال القالب.
 *
 * @param {object|null|undefined} header
 * @returns {string}
 */
export function chosenHeaderPreviewSource(header) {
    if (!header || !MEDIA_FORMATS.includes(header.format)) {
        return ''
    }

    const parameters = Array.isArray(header.parameters) ? header.parameters : []
    const parameter = parameters[0]

    if (!parameter || !CHOSEN_SELECTIONS.includes(parameter.selection)) {
        return ''
    }

    return mediaPreviewSource(parameter)
}
