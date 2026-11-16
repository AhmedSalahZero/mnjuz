import { choices, localBubble as bubble } from './bubble.ts'
import { pick, type Lang } from './lang.ts'
import type { WidgetMessage } from './types.ts'

/**
 * بعد أسئلة البداية: «كيف نقدر نساعدك؟» — عندي سؤال (الأسئلة الشائعة) أو
 * عندي مشكلة (المحادثة). قراءة الأسئلة الشائعة هنا وحدها، بلا محادثة عند
 * الخادم؛ وأوّل رسالة يكتبها الزائر تحمل ما فعله (context) ليراه الموظّف.
 *
 * الرحلة قائمةُ ما ضغطه الزائر بالترتيب، تُحفظ في المتصفّح: منها المرحلة،
 * وما يُرسل، والفقاعات — فيُعاد السجلّ كما جرى بعد إعادة تحميل الصفحة.
 */
export interface Faq {
    id: number
    question_ar: string
    question_en: string | null
    answer_ar: string | null
    answer_en: string | null
    image_url: string | null
}

export interface Journey {
    v: 1
    /** menu:question، faq:7، faq:more، faq:none… */
    steps: string[]
    /** أُرسل مع أوّل رسالة؟ يُرسل مرّة واحدة. */
    sent: boolean
}

export type JourneyStage = 'menu' | 'faqs' | 'answered' | 'chat'

export interface FaqTexts {
    menuPrompt: string
    menuQuestion: string
    menuProblem: string
    faqPrompt: string
    faqList: string
    morePrompt: string
    more: string
    none: string
    /** بعد «لم أجد إجابتي»: اكتب سؤالك. */
    askQuestion: string
    /** بعد «عندي مشكلة»: صِف مشكلتك. */
    describeProblem: string
}

export const MENU_QUESTION = 'menu:question'
export const MENU_PROBLEM = 'menu:problem'
export const FAQ_MORE = 'faq:more'
export const FAQ_NONE = 'faq:none'

/** ما يقبله الخادم من الأسئلة المقروءة (WebChatFaqs::MAX). */
const MAX_CONTEXT_FAQS = 30

export function newJourney(): Journey {
    return { v: 1, steps: [], sent: false }
}

/** المحفوظ، أو null — زائرٌ قديم بلا رحلة يرى محادثته كما هي. */
export function parseJourney(raw: string | null): Journey | null {
    try {
        const value = JSON.parse(raw || 'null')

        return value && value.v === 1 && Array.isArray(value.steps) && value.steps.every((step: unknown) => typeof step === 'string')
            ? { v: 1, steps: value.steps, sent: value.sent === true }
            : null
    } catch {
        return null
    }
}

function faqId(step: string): number | null {
    const id = step.startsWith('faq:') ? Number(step.slice(4)) : NaN

    return Number.isInteger(id) && id > 0 ? id : null
}

export function journeyStage(journey: Journey): JourneyStage {
    const last = journey.steps[journey.steps.length - 1]

    if (last === undefined) return 'menu'
    if (last === MENU_PROBLEM || last === FAQ_NONE) return 'chat'
    if (last === MENU_QUESTION || last === FAQ_MORE) return 'faqs'

    return 'answered'
}

/** زرٌّ ضغطه الزائر ⇐ الرحلة بعده، أو null إن لم يكن من أزرار مرحلته. */
export function chooseJourney(journey: Journey, optionId: string): Journey | null {
    const stage = journeyStage(journey)
    const allowed =
        stage === 'menu'
            ? optionId === MENU_QUESTION || optionId === MENU_PROBLEM
            : stage === 'faqs'
              ? optionId === FAQ_NONE || faqId(optionId) !== null
              : stage === 'answered' && (optionId === FAQ_MORE || optionId === FAQ_NONE)

    return allowed ? { ...journey, steps: journey.steps.concat(optionId) } : null
}

/** ما يُرسل مع أوّل رسالة (SendWebChatMessageRequest: context). */
export interface JourneyContext {
    menu: 'question' | 'problem' | null
    faqs: number[]
    not_found: boolean
}

/** ما يُرسل مع أوّل رسالة، أو null إن لم يضغط الزائر شيئاً أو أُرسل من قبل. */
export function journeyContext(journey: Journey | null): JourneyContext | null {
    if (!journey || journey.sent || journey.steps.length === 0) {
        return null
    }

    const first = journey.steps[0]
    const viewed = journey.steps.map(faqId).filter((id): id is number => id !== null)

    return {
        menu: first === MENU_QUESTION ? 'question' : first === MENU_PROBLEM ? 'problem' : null,
        faqs: [...new Set(viewed)].slice(0, MAX_CONTEXT_FAQS),
        not_found: journey.steps.includes(FAQ_NONE),
    }
}

/**
 * فقاعات الرحلة كما جرت، والأزرار على آخر سؤالٍ منها وحده.
 *
 * faqs = null: لم تصل الأسئلة بعد — تظهر الفقاعات بلا قائمة الأسئلة ريثما
 * تصل. وسؤالٌ حذفه التاجر بعد أن قرأه الزائر يسقط من السجلّ وحده.
 */
export function faqTranscript(faqs: Faq[] | null, journey: Journey, lang: Lang, texts: FaqTexts): WidgetMessage[] {
    const messages: WidgetMessage[] = []
    const byId = new Map((faqs ?? []).map((faq) => [faq.id, faq]))
    const steps = journey.steps
    const say = (direction: 'in' | 'out', text: string | null, offered: WidgetMessage['interactive'] = null, image: string | null = null, alt: string | null = null) =>
        messages.push(bubble(`l:j:${messages.length}`, direction, text, offered, image, alt))
    const list = () =>
        faqs === null
            ? null
            : choices(
                  faqs.map((faq) => ({ id: `faq:${faq.id}`, title: pick(lang, faq.question_ar, faq.question_en) })).concat({ id: FAQ_NONE, title: texts.none }),
                  texts.faqList,
              )

    say('out', texts.menuPrompt, steps.length === 0 ? choices([{ id: MENU_QUESTION, title: texts.menuQuestion }, { id: MENU_PROBLEM, title: texts.menuProblem }], '') : null)

    steps.forEach((step, index) => {
        const last = index === steps.length - 1

        if (step === MENU_QUESTION || step === FAQ_MORE) {
            say('in', step === FAQ_MORE ? texts.more : texts.menuQuestion)
            say('out', texts.faqPrompt, last ? list() : null)
        } else if (step === MENU_PROBLEM) {
            say('in', texts.menuProblem)
            say('out', texts.describeProblem)
        } else if (step === FAQ_NONE) {
            say('in', texts.none)
            say('out', texts.askQuestion)
        } else {
            const faq = byId.get(faqId(step) ?? 0)

            if (faq) {
                const question = pick(lang, faq.question_ar, faq.question_en)
                say('in', question)
                // الصورة قد تحمل الإجابة كلها (جدول أسعار): وصفها السؤال نفسه.
                say('out', pick(lang, faq.answer_ar, faq.answer_en) || null, null, faq.image_url, question)
            }

            say('out', texts.morePrompt, last ? choices([{ id: FAQ_MORE, title: texts.more }, { id: FAQ_NONE, title: texts.none }], '') : null)
        }
    })

    return messages
}
