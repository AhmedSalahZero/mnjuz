/**
 * أسئلة البداية في الـ widget: التحقّق من الإجابة (بنفس حالات الخادم في
 * tests/Fixtures/webchat/answers.json)، وحالة الأسئلة، وفقاعاتها.
 */
import { readFileSync } from 'node:fs'
import { asciiDigits, checkAnswer, isSaudiId } from '../../resources/widget/src/core/answers.ts'
import { answer, newProgress, nextStep, parseProgress, payload, questionsOf, rewind, skip, transcript } from '../../resources/widget/src/core/intake.ts'
import { translator } from '../../resources/widget/src/core/i18n.ts'

let checks = 0
const fail = []
const is = (actual, expected, msg) => {
    checks++
    const a = JSON.stringify(actual)
    const e = JSON.stringify(expected)
    if (a !== e) fail.push(`${msg} — توقّعنا ${e} ووجدنا ${a}`)
}

const read = (path) => JSON.parse(readFileSync(new URL(path, import.meta.url), 'utf8'))

// ── الحالات المشتركة مع الخادم (WebChatAnswersTest) ──
for (const c of read('../Fixtures/webchat/answers.json')) {
    const result = checkAnswer(c.question, c.input, c.country ?? 'SA')
    is(result.ok, c.ok, `${c.why}: القبول`)

    if (!c.ok) {
        is(result.reason, c.reason, `${c.why}: السبب`)
    } else if (c.question.validation !== 'phone') {
        // الجوال يُرسل كما كُتب ويحسب الخادم صيغته الدولية.
        is(result.value, c.value ?? null, `${c.why}: القيمة`)
    }
}

{
    is(asciiDigits('\u200F٠٥٠\u00A0۱۲۳ '), '050 123', 'أرقام عربية وفارسية وعلامة اتجاه ومسافة غير قاطعة')
    is([isSaudiId('1000000008'), isSaudiId('1000000009'), isSaudiId('3000000004')], [true, false, false], 'الهوية')
    is(checkAnswer({ target: 'phone', validation: 'phone', required: true }, '01001234567', 'EG').ok, true, 'دولة غير السعودية: يحكم الخادم')
    is(checkAnswer({ target: 'phone', validation: 'phone', required: true }, '+966 50-123 4567', 'EG').ok, true, 'رقم سعودي دولي ولو اختيرت دولة أخرى')
    is(checkAnswer({ target: 'phone', validation: 'phone', required: true }, '+966112345678', 'SA'), { ok: false, reason: 'phone_landline' }, 'أرضي سعودي دولي')
}

// ── رسائل الرفض نفسها عند الخادم (lang/*.json) ──
{
    const server = {
        err_required: 'This question is required.',
        err_too_long: 'That answer is too long.',
        err_email: 'Enter a valid email address.',
        err_number: 'Enter a number.',
        err_date: 'Enter a valid date.',
        err_select: 'Choose one of the options.',
        err_saudi_id: 'Enter a valid Saudi national ID or Iqama number (10 digits).',
        err_phone: 'Enter a valid mobile number.',
        err_phone_letters: 'Use digits only for the mobile number.',
        err_phone_landline: 'That looks like a landline number. Enter a mobile number.',
    }
    const arJson = read('../../lang/ar.json')

    for (const [key, english] of Object.entries(server)) {
        is(translator('en')(key), english, `${key}: الإنجليزية كالخادم`)
        is(translator('ar')(key), arJson[english], `${key}: العربية كالخادم`)
    }
}

// ── حالة الأسئلة ──
const q = (id, target, validation, required, extra = {}) => ({ id, target, validation, required, label_ar: null, label_en: null, ...extra })
const questions = [
    q('name', 'name', 'text', true),
    q('phone', 'phone', 'phone', true),
    q('email', 'email', 'email', false),
    q('f_city', 'field', 'select', true, { options: ['الرياض', 'جدة'] }),
]

{
    is(questionsOf({ questions }), questions, 'أسئلة الخادم كما هي')

    let p = newProgress(100)
    is(nextStep(questions, p, true), { kind: 'consent' }, 'الموافقة أوّلاً')
    p = { ...p, consented: true }
    is(nextStep(questions, p, true).question.id, 'name', 'ثم أوّل سؤال')
    p = answer(p, 'name', 'سارة')
    p = answer(p, 'phone', '0501234567', 'SA')
    is(nextStep(questions, p, true).question.id, 'email', 'بالترتيب')
    p = skip(p, 'email')
    is(nextStep(questions, p, true).question.id, 'f_city', 'التخطّي يمضي')
    p = answer(p, 'f_city', 'جدة')
    is(nextStep(questions, p, true), { kind: 'done' }, 'انتهت')

    is(payload(questions, p), { answers: { name: 'سارة', phone: '0501234567', f_city: 'جدة' }, countries: { phone: 'SA' } }, 'ما يُرسل')

    // الخادم رفض الجوال والمدينة: يُسأل الجوال أوّلاً بسبب الخادم وإجابته السابقة.
    const { progress, failed } = rewind(questions, p, { f_city: ['اختر أحد الخيارات.'], phone: ['يبدو أنه رقم هاتف أرضي. أدخل رقم جوال.'] })
    is(failed, { id: 'phone', message: 'يبدو أنه رقم هاتف أرضي. أدخل رقم جوال.', previous: '0501234567' }, 'أوّل سؤال مرفوض')
    is(nextStep(questions, progress, true).question.id, 'phone', 'يُعاد سؤاله')
    is(Object.keys(progress.answers), ['name'], 'تُمحى إجابات المرفوض وحدها')
    is(rewind(questions, p, { consent: ['يرجى الموافقة للمتابعة.'] }).progress.consented, false, 'رفض الموافقة يعيدها')

    is(parseProgress('not json', 5), newProgress(5), 'محفوظ تالف: بداية جديدة')
    is(parseProgress(JSON.stringify({ v: 2, answers: {}, skipped: [] }), 5), newProgress(5), 'نسخة أخرى: بداية جديدة')
    is(parseProgress(JSON.stringify(p), 5).answers.name, 'سارة', 'المحفوظ يُكمل')
}

// ── الفقاعات ──
{
    const texts = { label: (question) => `؟${question.id}`, consent: 'أوافق على الشروط', accept: 'أوافق', skipped: 'تخطّيت' }
    const ids = (messages) => messages.map((m) => `${m.id}${m.interactive ? `[${(m.interactive.buttons ?? m.interactive.sections[0].rows).map((b) => b.id).join(',')}]` : ''}`)

    let p = newProgress(0)
    is(ids(transcript(questions, p, true, texts, null)), ['l:consent[consent]'], 'الموافقة بزرّها وحدها')
    p = { ...p, consented: true }
    is(ids(transcript(questions, p, true, texts, null)), ['l:consent', 'l:consented', 'l:q:name'], 'بعد الموافقة: السؤال الأول')

    p = skip(answer(answer(p, 'name', 'سارة'), 'phone', '0501234567'), 'email')
    const list = transcript(questions, p, false, texts, null)
    is(ids(list), ['l:q:name', 'l:a:name', 'l:q:phone', 'l:a:phone', 'l:q:email', 'l:a:email', 'l:q:f_city[o:0,o:1]'], 'خيارات القائمة تحت سؤالها الحالي')
    is(list[5].text, 'تخطّيت', 'المتخطّى')
    is([list[1].direction, list[2].direction], ['in', 'out'], 'الإجابة من الزائر والسؤال من الطرف الآخر')

    const many = [q('f_x', 'field', 'select', true, { options: ['أ', 'ب', 'ج', 'د'] })]
    is(transcript(many, newProgress(0), false, texts, null)[0].interactive.kind, 'list', 'أكثر من ثلاثة خيارات: قائمة')

    const retry = transcript(questions, newProgress(0), false, texts, { text: '0112345678', reason: 'أرضي' })
    is(ids(retry), ['l:q:name', 'l:try:name', 'l:why:name'], 'الإجابة المرفوضة ثم السبب')
    is(retry[2].text, 'أرضي\n؟name', 'السبب ثم السؤال مرّة أخرى')
}

if (fail.length) {
    console.error(fail.join('\n'))
    process.exit(1)
}

console.log(`✓ webchat-intake: ${checks} checks`)
