/**
 * منشئ أسئلة البداية في إعدادات المحادثة المباشرة.
 *
 * القواعد مرآة لـ App\Services\WebChat\WebChatQuestions، والخادم يعيد فحصها:
 * لكل حقل سؤال واحد، وحتى 12 سؤالاً، والحقل المخصّص «مربّع اختيار» لا يُسأل.
 *
 * وحدة ESM صرفة تُختبر بـ node (tests/js/webchat-settings.mjs).
 */
export const MAX_QUESTIONS = 12
export const BUILT_IN_TARGETS = ['name', 'email', 'phone']
export const FIELD_VALIDATIONS = ['text', 'number', 'email', 'phone', 'date', 'saudi_id']

/** معرّف السؤال كما يحسبه الخادم: name / email / phone / f_<uuid>. */
export const questionId = (question) => (question.target === 'field' ? `f_${question.field}` : question.target)

/** قيمة «يُحفظ في» في القائمة: الهدف الأساسي، أو field:<uuid>. */
export const targetValue = (question) => (question.target === 'field' ? `field:${question.field}` : question.target)

/** الحقول المخصّصة التي تُسأل: لا مربّعات الاختيار. */
export const askableFields = (fields) => (fields || []).filter((field) => field.type !== 'checkbox')

/** نوع الإجابة الأقرب لحقل مخصّص بحسب نوعه في صفحة الحقول. */
export function validationForField(field) {
    if (field?.type === 'select') return 'select'

    if (field?.type === 'input') {
        if (field.value === 'number') return 'number'
        if (field.value === 'email') return 'email'
        if (field.value === 'date') return 'date'
    }

    return 'text'
}

/**
 * خيارات «يُحفظ في» لسؤال: الأساسية ثم الحقول، وما يستعمله سؤال آخر معطَّل.
 * @returns {Array<{value: string, target: string, field: ?string, disabled: boolean, name: ?string}>}
 */
export function targetOptions(fields, questions, current) {
    const used = new Set(questions.filter((q) => q !== current).map(questionId))

    return [
        ...BUILT_IN_TARGETS.map((target) => ({ value: target, target, field: null, name: null, disabled: used.has(target) })),
        ...askableFields(fields).map((field) => ({
            value: `field:${field.uuid}`,
            target: 'field',
            field: field.uuid,
            name: field.name,
            disabled: used.has(`f_${field.uuid}`),
        })),
    ]
}

/** تغيير «يُحفظ في»: الهدف والحقل ونوع الإجابة المناسب له. */
export function retarget(question, value, fields) {
    if (BUILT_IN_TARGETS.includes(value)) {
        return { ...question, target: value, field: null, validation: value === 'name' ? 'text' : value }
    }

    const uuid = String(value).replace(/^field:/, '')
    const field = (fields || []).find((f) => f.uuid === uuid)

    return { ...question, target: 'field', field: uuid, validation: validationForField(field) }
}

let counter = 0

/** مفتاح ثابت لكل سؤال في الصفحة (للسحب والترتيب)، لا يُحفظ معنى له. */
export const withKey = (question) => ({ ...question, _key: question._key ?? `q${++counter}` })

/** سؤال جديد بأوّل هدف لم يُستعمل، أو null إن لم يبقَ هدف أو بلغت الحدّ. */
export function newQuestion(fields, questions) {
    if (questions.length >= MAX_QUESTIONS) return null

    const option = targetOptions(fields, questions, null).find((o) => !o.disabled)

    if (!option) return null

    return withKey(retarget({ required: true, label_ar: null, label_en: null }, option.value, fields))
}

/** أسئلة الـ widget الجديد: الاسم والبريد والجوال، إجبارية. */
export const defaultQuestions = () =>
    BUILT_IN_TARGETS.map((target) =>
        withKey({ target, field: null, validation: target === 'name' ? 'text' : target, required: true, label_ar: null, label_en: null }),
    )

/** ما يُحفظ: بلا مفتاح الصفحة. */
export const toPayload = (questions) => questions.map(({ _key, ...question }) => question)
