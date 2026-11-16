import { choices, localBubble as bubble } from './bubble.ts'
import type { IntakeQuestion, WidgetConfig, WidgetMessage } from './types.ts'

/**
 * أسئلة البداية سؤالاً سؤالاً داخل المحادثة، قبل أن تبدأ.
 *
 * الحالة (Progress) تُحفظ في المتصفّح فيكمل الزائر من حيث توقّف بعد تحديث
 * الصفحة، وفقاعات الأسئلة والإجابات تُبنى منها (transcript) لا تُخزَّن.
 * الإجابات تُرسل مرّة واحدة في النهاية؛ والخادم إن رفض شيئاً أعاد سؤاله.
 */
export interface Progress {
    v: 1
    /** بداية الأسئلة: الزمن منها يُرسل (elapsed_ms) دليلاً على إنسان. */
    startedAt: number
    consented: boolean
    /** الإجابة كما تُرسل بمعرّف السؤال. */
    answers: Record<string, string>
    /** دولة كل سؤال جوال. */
    countries: Record<string, string>
    skipped: string[]
}

export type Step = { kind: 'consent' } | { kind: 'ask'; question: IntakeQuestion } | { kind: 'done' }

type Prechat = WidgetConfig['widget']['prechat']

/** أسئلة الخادم؛ وخادمٌ أقدم بلا questions: من مفاتيح النموذج القديم. */
export function questionsOf(prechat: Prechat): IntakeQuestion[] {
    if (Array.isArray(prechat.questions)) {
        return prechat.questions
    }

    const builtIn = (target: 'name' | 'phone' | 'email', required: boolean, label: string | null): IntakeQuestion => ({
        id: target,
        target,
        validation: target === 'name' ? 'text' : target,
        required,
        label_ar: label,
        label_en: null,
    })
    const list = [builtIn('name', true, prechat.name_label)]

    if (prechat.phone !== 'off') {
        list.push(builtIn('phone', prechat.phone !== 'optional', prechat.phone_label))
    }

    if (prechat.email === 'optional' || prechat.email === 'required') {
        list.push(builtIn('email', prechat.email === 'required', null))
    }

    return list
}

export function newProgress(now: number): Progress {
    return { v: 1, startedAt: now, consented: false, answers: {}, countries: {}, skipped: [] }
}

/** المحفوظ في المتصفّح، أو بداية جديدة إن كان تالفاً أو من نسخة أخرى. */
export function parseProgress(raw: string | null, now: number): Progress {
    try {
        const value = JSON.parse(raw || 'null')

        if (value && value.v === 1 && typeof value.answers === 'object' && Array.isArray(value.skipped)) {
            return { ...newProgress(now), ...value }
        }
    } catch {
        // تالف: نبدأ من جديد.
    }

    return newProgress(now)
}

export function nextStep(questions: IntakeQuestion[], progress: Progress, needsConsent: boolean): Step {
    if (needsConsent && !progress.consented) {
        return { kind: 'consent' }
    }

    const question = questions.find((q) => !(q.id in progress.answers) && !progress.skipped.includes(q.id))

    return question ? { kind: 'ask', question } : { kind: 'done' }
}

export function answer(progress: Progress, id: string, value: string, country?: string): Progress {
    return {
        ...progress,
        answers: { ...progress.answers, [id]: value },
        countries: country ? { ...progress.countries, [id]: country } : progress.countries,
    }
}

export function skip(progress: Progress, id: string): Progress {
    return { ...progress, skipped: progress.skipped.concat(id) }
}

/**
 * الخادم رفض إجابات (422): تُمحى فيُسأل أوّلها من جديد بسبب الخادم.
 * @returns الحالة الجديدة، والسؤال الأوّل المرفوض ورسالته وإجابته السابقة.
 */
export function rewind(
    questions: IntakeQuestion[],
    progress: Progress,
    errors: Record<string, string[]>,
): { progress: Progress; failed: { id: string; message: string; previous: string } | null } {
    const answers = { ...progress.answers }
    let failed: { id: string; message: string; previous: string } | null = null

    for (const question of questions) {
        const message = errors[question.id]?.[0]

        if (message) {
            failed ??= { id: question.id, message, previous: answers[question.id] ?? '' }
            delete answers[question.id]
        }
    }

    return {
        progress: {
            ...progress,
            answers,
            skipped: progress.skipped.filter((id) => !errors[id]),
            consented: errors.consent ? false : progress.consented,
        },
        failed,
    }
}

/** ما يُرسل لبدء الجلسة: إجابات الأسئلة المعروضة فقط، ودولة كل جوال. */
export function payload(questions: IntakeQuestion[], progress: Progress): { answers: Record<string, string>; countries: Record<string, string> } {
    const answers: Record<string, string> = {}
    const countries: Record<string, string> = {}

    for (const question of questions) {
        if (question.id in progress.answers) {
            answers[question.id] = progress.answers[question.id]
        }

        if (progress.countries[question.id]) {
            countries[question.id] = progress.countries[question.id]
        }
    }

    return { answers, countries }
}

export interface TranscriptTexts {
    label: (question: IntakeQuestion) => string
    consent: string
    accept: string
    skipped: string
}

/**
 * فقاعات الأسئلة والإجابات حتى السؤال الحالي. معرّفاتها «l:…» (محلّية):
 * الضغط على أزرارها لا يذهب إلى الخادم. خيارات سؤال القائمة تحت سؤاله
 * الحالي وحده (o:رقم الخيار)، وزرّ الموافقة «consent».
 *
 * attempt: إجابة رُفضت للسؤال الحالي — تظهر، ثم السبب، ثم السؤال مرة أخرى.
 */
export function transcript(
    questions: IntakeQuestion[],
    progress: Progress,
    needsConsent: boolean,
    texts: TranscriptTexts,
    attempt: { text: string; reason: string } | null,
): WidgetMessage[] {
    const messages: WidgetMessage[] = []

    if (needsConsent) {
        messages.push(bubble('l:consent', 'out', texts.consent, progress.consented ? null : { kind: 'buttons', buttons: [{ id: 'consent', title: texts.accept }] }))

        if (!progress.consented) {
            return messages
        }

        messages.push(bubble('l:consented', 'in', texts.accept))
    }

    for (const question of questions) {
        const answered = question.id in progress.answers
        const skipped = progress.skipped.includes(question.id)
        const current = !answered && !skipped
        const options = current && question.validation === 'select' ? question.options ?? [] : []
        const offered = options.length === 0 ? null : choices(options.map((title, i) => ({ id: `o:${i}`, title })), texts.label(question))

        if (current && attempt) {
            messages.push(bubble(`l:q:${question.id}`, 'out', texts.label(question)))
            messages.push(bubble(`l:try:${question.id}`, 'in', attempt.text))
            messages.push(bubble(`l:why:${question.id}`, 'out', `${attempt.reason}\n${texts.label(question)}`, offered))

            return messages
        }

        messages.push(bubble(`l:q:${question.id}`, 'out', texts.label(question), offered))

        if (current) {
            return messages
        }

        messages.push(bubble(`l:a:${question.id}`, 'in', answered ? progress.answers[question.id] : texts.skipped))
    }

    return messages
}
