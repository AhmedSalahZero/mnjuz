/**
 * منشئ أسئلة البداية في إعدادات المحادثة المباشرة
 * (resources/js/Pages/User/Settings/webchatQuestions.js).
 */
import {
    MAX_QUESTIONS,
    defaultQuestions,
    newQuestion,
    questionId,
    retarget,
    targetOptions,
    toPayload,
    validationForField,
} from '../../resources/js/Pages/User/Settings/webchatQuestions.js'

let checks = 0
const fail = []
const is = (actual, expected, msg) => {
    checks++
    const a = JSON.stringify(actual)
    const e = JSON.stringify(expected)
    if (a !== e) fail.push(`${msg} — توقّعنا ${e} ووجدنا ${a}`)
}

const fields = [
    { uuid: 'u-city', name: 'المدينة', type: 'select', value: 'الرياض, جدة' },
    { uuid: 'u-id', name: 'رقم الهوية', type: 'input', value: 'text' },
    { uuid: 'u-age', name: 'العمر', type: 'input', value: 'number' },
    { uuid: 'u-agree', name: 'موافق', type: 'checkbox', value: null },
]

{
    const defaults = defaultQuestions()
    is(defaults.map(questionId), ['name', 'email', 'phone'], 'الأسئلة الافتراضية')
    is(defaults.every((q) => q.required), true, 'إجبارية')
    is(toPayload(defaults)[0], { target: 'name', field: null, validation: 'text', required: true, label_ar: null, label_en: null }, 'مفتاح الصفحة لا يُحفظ')

    const options = targetOptions(fields, defaults, defaults[0])
    is(options.map((o) => o.value), ['name', 'email', 'phone', 'field:u-city', 'field:u-id', 'field:u-age'], 'مربّع الاختيار لا يُسأل')
    is(options.filter((o) => o.disabled).map((o) => o.value), ['email', 'phone'], 'ما يستعمله سؤال آخر معطّل، لا سؤاله هو')

    is(validationForField(fields[0]), 'select', 'حقل قائمة')
    is(validationForField(fields[2]), 'number', 'حقل رقم')
    is(validationForField(fields[1]), 'text', 'حقل نصّ')

    is(retarget(defaults[1], 'field:u-age', fields), { ...defaults[1], target: 'field', field: 'u-age', validation: 'number' }, 'إلى حقل مخصّص')
    is(retarget(defaults[1], 'phone', fields).validation, 'phone', 'إلى الجوال')

    const added = newQuestion(fields, defaults)
    is([added.target, added.field, added.validation], ['field', 'u-city', 'select'], 'سؤال جديد بأوّل هدف لم يُستعمل')

    const all = defaults.concat(['u-city', 'u-id', 'u-age'].map((uuid) => ({ target: 'field', field: uuid })))
    is(newQuestion(fields, all), null, 'لا هدف باقٍ')
    is(newQuestion(fields, Array.from({ length: MAX_QUESTIONS }, () => ({ target: 'name' }))), null, 'بلغت الحدّ')
}

if (fail.length) {
    console.error(fail.join('\n'))
    process.exit(1)
}

console.log(`✓ webchat-settings: ${checks} checks`)
