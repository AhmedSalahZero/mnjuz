import { useEffect, useMemo, useRef, useState } from 'preact/hooks'
import { faCommentDots, faXmark } from '@fortawesome/free-solid-svg-icons'
import { ApiError, createApi } from '../core/api.ts'
import { checkAnswer, type Reason } from '../core/answers.ts'
import { localBubble } from '../core/bubble.ts'
import { chooseJourney, faqTranscript, journeyContext, journeyStage, newJourney, parseJourney, type Faq, type Journey, type JourneyContext } from '../core/faq.ts'
import { mediaType, pickFiles } from '../core/files.ts'
import { translator, type TextKey } from '../core/i18n.ts'
import { answer, newProgress, nextStep, parseProgress, payload, questionsOf, rewind, skip, transcript, type Progress } from '../core/intake.ts'
import { LANG_KEY, pick, resolveLanguage, type Lang } from '../core/lang.ts'
import { connectRealtime, type RealtimeState } from '../core/realtime.ts'
import { isLeader, parseLock, pollInterval, shouldConnectRealtime } from '../core/scheduler.ts'
import { addPending, lastOutboundSeq, lastSeq, firstSeq, markFailed, mergeMessages, unreadCount } from '../core/store.ts'
import { createStorage, type Persisted } from '../core/storage.ts'
import { firstNewBotBubble, typingDelay } from '../core/typing.ts'
import type { IntakeQuestion, WidgetConfig, WidgetMessage } from '../core/types.ts'
import { uuidv4 } from '../core/uuid.ts'
import { Composer, rememberEmoji } from './Composer.tsx'
import { Icon } from './Icon.tsx'
import { IntakeInput } from './IntakeInput.tsx'
import { MessageList } from './MessageList.tsx'

export interface MountContext {
    base: string
    key: string
    config: WidgetConfig
    preview: boolean
    open: boolean
    /**
     * للاختبارات وحدها: مدّة نقاط الكتابة ثابتة، و0 بلا نقاط. غائبة في
     * الإنتاج (main.tsx لا يضعها): المدّة من طول النص (core/typing.ts).
     */
    typingMs?: number
}

const PAGE_SIZE = 30
const EMOJI_KEY = 'mnjz:emoji'
/** أسئلة البداية في التخزين (core/intake.ts): يكمل الزائر من حيث توقّف. */
const INTAKE_KEY = 'intake'
/** القائمة والأسئلة الشائعة (core/faq.ts): ما ضغطه الزائر قبل أن يكتب. */
const JOURNEY_KEY = 'journey'

/** سبب رفض الإجابة ⇐ نصّه بلغة الزائر. */
const REASON_TEXT: Record<Reason, TextKey> = {
    required: 'err_required',
    too_long: 'err_too_long',
    email: 'err_email',
    number: 'err_number',
    date: 'err_date',
    select: 'err_select',
    saudi_id: 'err_saudi_id',
    phone_letters: 'err_phone_letters',
    phone_invalid: 'err_phone',
    phone_landline: 'err_phone_landline',
}

/** نصّ السؤال الأساسي حين لا يكتبه التاجر. */
const ASK_TEXT: Record<string, TextKey> = { name: 'ask_name', email: 'ask_email', phone: 'ask_phone' }

function readRecentEmoji(): string[] {
    try {
        const value = JSON.parse(localStorage.getItem(EMOJI_KEY) || '[]')

        return Array.isArray(value) ? value.filter((e) => typeof e === 'string') : []
    } catch {
        return []
    }
}

function draft(clientId: string, fields: Partial<WidgetMessage>): WidgetMessage {
    return {
        id: null,
        seq: null,
        client_id: clientId,
        direction: 'in',
        type: 'text',
        text: null,
        media: null,
        interactive: null,
        status: 'pending',
        created_at: new Date().toISOString(),
        sender: { kind: 'visitor' },
        ...fields,
    }
}

/**
 * الـ widget كلّه: الزرّ العائم، ونافذة المحادثة، والاتصال بالخادم.
 *
 * المصدر الوحيد للحقيقة هو الخادم: الرسائل تُدمج بمعرّفها وترتيبها (seq)،
 * والفقاعة المتفائلة تُستبدل حين يصل صفّها. ما يُحفظ في المتصفّح التوكن
 * وعلامة القراءة لا أكثر.
 */
export function Widget({ ctx }: { ctx: MountContext }) {
    const { config } = ctx
    const appearance = config.widget.appearance
    const storage = useMemo(() => createStorage(ctx.key), [ctx.key])
    // اختيار الزائر من زرّ اللغة يغلب إعداد التاجر، ويبقى بين الزيارات.
    const [langChoice, setLangChoice] = useState<string | null>(() => storage.raw(LANG_KEY).get())
    const lang = resolveLanguage(langChoice, appearance.language, document.documentElement.lang, navigator.language)
    const langRef = useRef<Lang>(lang)
    langRef.current = lang
    const t = useMemo(() => translator(lang), [lang])

    const [persisted, setPersisted] = useState<Persisted>(() => storage.read())
    const tokenRef = useRef<string | null>(persisted.token ?? null)
    tokenRef.current = persisted.token ?? null
    const api = useMemo(
        () => createApi(ctx.base, ctx.key, () => tokenRef.current, ctx.preview, () => langRef.current),
        [ctx.base, ctx.key, ctx.preview],
    )

    const [open, setOpen] = useState(ctx.open)
    const [messages, setMessages] = useState<WidgetMessage[]>([])
    const messagesRef = useRef(messages)
    messagesRef.current = messages
    const [hasMore, setHasMore] = useState(false)
    const [loadingEarlier, setLoadingEarlier] = useState(false)
    const [visible, setVisible] = useState(!document.hidden)
    const [realtime, setRealtime] = useState<RealtimeState>('disconnected')
    const [leader, setLeader] = useState(false)
    const [notice, setNotice] = useState<string | null>(null)
    const [recentEmoji, setRecentEmoji] = useState<string[]>(readRecentEmoji)
    const [clock, setClock] = useState(Date.now())
    const lastActivity = useRef(Date.now())
    const tabId = useRef(uuidv4())
    const lastOutSeen = useRef<number | null>(null)

    const token = persisted.token ?? null
    const hasConversation = Boolean(token)
    const idleMs = clock - lastActivity.current
    // نصوص التاجر بلغة الزائر: الإنجليزية إن كتبها التاجر، وإلّا العربية.
    const title = pick(lang, appearance.title, appearance.title_en) || t('title')
    const subtitle = pick(lang, appearance.subtitle, appearance.subtitle_en) || t('subtitle')
    const welcome = pick(lang, appearance.welcome_message, appearance.welcome_message_en)

    const switchLanguage = () => {
        const next: Lang = lang === 'ar' ? 'en' : 'ar'
        storage.raw(LANG_KEY).set(next)
        setLangChoice(next)
    }

    const persist = (patch: Persisted) => setPersisted(storage.write(patch))
    const touch = () => {
        lastActivity.current = Date.now()
    }

    /* ───────────── أسئلة البداية ───────────── */

    const prechat = config.widget.prechat
    const questions = useMemo(() => questionsOf(prechat), [prechat])
    const needsConsent = Boolean(prechat.consent_text)
    const [progress, setProgress] = useState<Progress>(() => parseProgress(storage.raw(INTAKE_KEY).get(), Date.now()))
    // إجابة رُفضت للسؤال الحالي: تظهر فقاعتها ثم السبب ثم السؤال.
    const [attempt, setAttempt] = useState<{ text: string; reason: string } | null>(null)
    const [starting, setStarting] = useState(false)
    const [startError, setStartError] = useState<string | null>(null)
    const honeypot = useRef('')
    const openedAt = useRef(Date.now())
    const intake = !token && config.available && (questions.length > 0 || needsConsent)
    const step = nextStep(questions, progress, needsConsent)
    // سجلّ الأسئلة يبقى فوق المحادثة بعد بدئها — لمن أجاب فعلاً، لا لزائرٍ
    // قديم بلا أسئلة.
    const answeredIntake = step.kind === 'done' && (Object.keys(progress.answers).length > 0 || progress.skipped.length > 0)

    const saveProgress = (next: Progress) => {
        setProgress(next)
        storage.raw(INTAKE_KEY).set(JSON.stringify(next))
    }

    const labelOf = (question: IntakeQuestion) =>
        pick(lang, question.label_ar, question.label_en) || (ASK_TEXT[question.target] ? t(ASK_TEXT[question.target]) : t('answer'))

    const intakeMessages =
        intake || answeredIntake
            ? transcript(
                  questions,
                  progress,
                  needsConsent,
                  { label: labelOf, consent: pick(lang, prechat.consent_text, prechat.consent_text_en), accept: t('accept'), skipped: t('skipped') },
                  attempt,
              )
            : []

    /* ───────────── القائمة والأسئلة الشائعة ───────────── */

    const hasFaqs = (config.faqs ?? 0) > 0
    const [journey, setJourney] = useState<Journey | null>(() => parseJourney(storage.raw(JOURNEY_KEY).get()))
    const journeyRef = useRef(journey)
    journeyRef.current = journey
    const [faqs, setFaqs] = useState<Faq[] | null>(null)
    // زائرٌ بلا جلسة يبدأ بالقائمة إن كان للتاجر أسئلة شائعة؛ ومن له جلسة بلا
    // رحلة (قبل الأسئلة الشائعة) يكمل محادثته كما هي. وقائمةٌ لم يضغط فيها
    // شيئاً تسقط إن حذف التاجر أسئلته كلها.
    const storedJourney = journey && (hasFaqs || journey.steps.length > 0) ? journey : null
    const activeJourney = storedJourney ?? (!token && hasFaqs ? newJourney() : null)
    const stage = activeJourney ? journeyStage(activeJourney) : 'chat'
    // ما دام في القائمة ولم يصله شيء من الفريق: لا خانة كتابة، أزرارٌ فقط.
    const inJourney = stage !== 'chat' && messages.length === 0
    const journeyMessages =
        activeJourney && (stage !== 'chat' || activeJourney.steps.length > 0)
            ? // بلا أسئلة عند التاجر الآن: قائمةٌ فارغة معروفة، فيبقى «لم أجد إجابتي».
              faqTranscript(hasFaqs ? faqs : [], activeJourney, lang, {
                  menuPrompt: t('menu_prompt'),
                  menuQuestion: t('menu_question'),
                  menuProblem: t('menu_problem'),
                  faqPrompt: t('faq_prompt'),
                  faqList: t('faq_list'),
                  morePrompt: t('faq_more_prompt'),
                  more: t('faq_more'),
                  none: t('faq_none'),
                  askQuestion: t('faq_ask'),
                  describeProblem: t('faq_problem'),
              })
            : []
    // انتهت أسئلة البداية وبدأت الجلسة ولا قائمة أسئلة شائعة: رسالة التاجر
    // («سنحوّل محادثتك…») إن كتبها، وإلّا لا يصل الزائرَ بعد اسمه شيء.
    const handoffText = pick(lang, prechat.handoff_message, prechat.handoff_message_en)
    const intakeFinished = step.kind === 'done' && (answeredIntake || (needsConsent && progress.consented))
    const handoffMessages = token && intakeFinished && !activeJourney && handoffText ? [localBubble('l:handoff', 'out', handoffText)] : []
    // ما سبق المحادثة يبقى فوقها: الأسئلة وإجاباتها، ثم رسالة ما بعدها أو القائمة.
    const preface = intakeMessages.concat(handoffMessages, journeyMessages)
    const needsFaqs = open && hasFaqs && activeJourney !== null && (stage !== 'chat' || activeJourney.steps.some((step) => step.startsWith('faq:')))

    /* ───────────── نقاط الكتابة ───────────── */

    // ما تعرضه النافذة قبل المحادثة: الأسئلة وحدها أثناءها، ثم السجلّ كلّه
    // (startSession يعيد حساب الموضع حين يتبدّل الاثنان).
    const scripted = intake ? intakeMessages : preface
    // ضغطة الزائر أو إجابته تُمسك ما بعدها: فقاعته تظهر فوراً، وأوّل فقاعة آلية
    // بعدها (السؤال التالي، رسالة ما بعد الأسئلة، القائمة) تسبقها النقاط.
    // before = طول السجلّ لحظة الضغط؛ والتحميل وتبديل اللغة لا يُمسكان شيئاً.
    const [hold, setHold] = useState<{ before: number } | null>(null)
    const expectReply = () => {
        if (ctx.typingMs !== 0) {
            setHold({ before: scripted.length })
        }
    }
    // بدأت المحادثة الفعلية (رسالة الزائر أو ردّ): لا شيء يُخفى.
    const holdAt = hold && messages.length === 0 ? firstNewBotBubble(scripted, hold.before) : -1
    const typing = holdAt >= 0
    const shown = typing ? scripted.slice(0, holdAt) : scripted
    const typingFor = typing ? ctx.typingMs ?? typingDelay(scripted[holdAt].text) : 0

    // يبدأ مع ظهور النقاط ولا يُعاد بتغيّر السجلّ أثناءها (وصول الأسئلة الشائعة).
    useEffect(() => {
        if (!typing) return

        const timer = setTimeout(() => setHold(null), typingFor)

        return () => clearTimeout(timer)
    }, [typing])

    const saveJourney = (next: Journey | null) => {
        journeyRef.current = next
        setJourney(next)
        storage.raw(JOURNEY_KEY).set(next ? JSON.stringify(next) : '')
    }

    const pickJourney = (_message: WidgetMessage, optionId: string) => {
        const next = activeJourney && chooseJourney(activeJourney, optionId)

        if (next) {
            touch()
            expectReply()
            saveJourney(next)
        }
    }

    /** وصلت أوّل رسالة بما قرأه الزائر: لا يُرسل ثانية. */
    const journeyDelivered = (context: JourneyContext | null) => {
        const current = journeyRef.current

        if (context && current && !current.sent) {
            saveJourney({ ...current, sent: true })
        }
    }

    // الأسئلة الشائعة حين يحتاجها السجلّ. فشلُ جلبها لا يُعلِق الزائر: تبقى
    // «لم أجد إجابتي» فيكتب للفريق.
    useEffect(() => {
        if (!needsFaqs || faqs !== null) return

        let cancelled = false
        api.faqs()
            .then((result) => !cancelled && setFaqs(result.faqs))
            .catch(() => !cancelled && setFaqs([]))

        return () => {
            cancelled = true
        }
    }, [needsFaqs])

    const resetSession = () => {
        setHold(null)
        storage.clear()
        storage.raw(INTAKE_KEY).set('')
        saveJourney(null)
        setPersisted({})
        setProgress(newProgress(Date.now()))
        setMessages([])
        setHasMore(false)
    }

    const handleError = (error: unknown) => {
        if (error instanceof ApiError) {
            if (error.status === 401) {
                // التوكن لم يعد صالحاً (زائر صامت حُذف): يبدأ الزائر من جديد.
                resetSession()

                return
            }

            setNotice(error.status === 429 ? t('too_fast') : error.status === 413 ? t('file_too_large') : t('error'))

            return
        }

        setNotice(t('error'))
    }

    /** آخر صفحة من السجلّ — وتُسقط ما حذفه الموظّف (refresh). */
    const loadLatest = async () => {
        if (!tokenRef.current) return

        try {
            const result = await api.messages({ limit: PAGE_SIZE })
            setMessages((current) => mergeMessages(current.filter((m) => !m.id), result.messages))
            setHasMore(result.has_more)
        } catch (error) {
            handleError(error)
        }
    }

    /** ما بعد آخر صفّ عندنا: المزامنة وسدّ الفجوة بعد انقطاع. */
    const fetchAfter = async () => {
        if (!tokenRef.current) return

        const after = lastSeq(messagesRef.current)

        if (!after) {
            await loadLatest()

            return
        }

        try {
            let cursor = after

            for (let page = 0; page < 5; page++) {
                const result = await api.messages({ after: cursor, limit: 50 })

                if (result.messages.length) {
                    setMessages((current) => mergeMessages(current, result.messages))
                    cursor = Math.max(cursor, ...result.messages.map((m) => m.seq ?? 0))
                }

                if (!result.has_more) break
            }

            if (cursor > after) {
                storage.raw('sync').set(String(cursor))
            }
        } catch (error) {
            handleError(error)
        }
    }

    const loadEarlier = async () => {
        const before = firstSeq(messagesRef.current)

        if (!before || loadingEarlier) return

        setLoadingEarlier(true)

        try {
            const result = await api.messages({ before, limit: PAGE_SIZE })
            setMessages((current) => mergeMessages(current, result.messages))
            setHasMore(result.has_more)
        } catch (error) {
            handleError(error)
        } finally {
            setLoadingEarlier(false)
        }
    }

    // ── أول تحميل، وكل تغيّر في الجلسة ──
    useEffect(() => {
        if (token) {
            loadLatest()
        }
    }, [token])

    // ── ساعة دقيقة: لحساب الخمول بلا مستمعين على كل حركة ──
    useEffect(() => {
        const timer = setInterval(() => setClock(Date.now()), 60000)
        const onVisibility = () => {
            setVisible(!document.hidden)

            if (!document.hidden) {
                touch()
                setClock(Date.now())
            }
        }

        document.addEventListener('visibilitychange', onVisibility)

        return () => {
            clearInterval(timer)
            document.removeEventListener('visibilitychange', onVisibility)
        }
    }, [])

    // ── التاب القائد: وحده يُزامن، والبقية تسمع إشارته ──
    useEffect(() => {
        const lock = storage.raw('leader')
        const elect = () => {
            const now = Date.now()
            const mine = isLeader(parseLock(lock.get()), tabId.current, now)

            if (mine) {
                lock.set(JSON.stringify({ id: tabId.current, ts: now }))
            }

            setLeader(mine)
        }

        elect()
        const timer = setInterval(elect, 5000)

        return () => clearInterval(timer)
    }, [storage])

    // ── تابات الزائر الأخرى: جلسة بدأت هناك، أو رسائل جديدة جلبها القائد ──
    useEffect(() => {
        const onStorage = (event: StorageEvent) => {
            if (event.key === storage.key) {
                setPersisted(storage.read())
            } else if (event.key === `${storage.key}:sync`) {
                fetchAfter()
            } else if (event.key === `${storage.key}:${JOURNEY_KEY}`) {
                setJourney(parseJourney(event.newValue))
            }
        }

        window.addEventListener('storage', onStorage)

        return () => window.removeEventListener('storage', onStorage)
    }, [storage])

    // ── الاتصال اللحظي ──
    const wantsRealtime = Boolean(config.realtime && persisted.channel) && shouldConnectRealtime({ open, visible, hasConversation, idleMs })

    useEffect(() => {
        if (!wantsRealtime || !token || !config.realtime || !persisted.channel) {
            setRealtime('disconnected')

            return
        }

        let wasConnected = false
        const handle = connectRealtime(
            config.realtime,
            persisted.channel,
            api.authEndpoint,
            token,
            (event) => {
                if (event.kind === 'message' && event.message) {
                    setMessages((current) => mergeMessages(current, [event.message as WidgetMessage]))
                } else {
                    loadLatest()
                }
            },
            (state) => {
                setRealtime(state)

                // عاد الاتصال بعد انقطاع: ما فات يُجلب.
                if (state === 'connected') {
                    if (wasConnected) fetchAfter()
                    wasConnected = true
                }
            },
        )

        if (!handle) {
            setRealtime('unavailable')
        }

        return () => handle?.disconnect()
    }, [wantsRealtime, token, persisted.channel])

    // ── المزامنة البديلة حين لا اتصال لحظي ──
    const interval = pollInterval({
        open,
        visible,
        realtimeConnected: realtime === 'connected',
        hasConversation,
        leader,
        idleMs,
    })

    useEffect(() => {
        if (!interval) return

        const timer = setInterval(fetchAfter, interval)

        return () => clearInterval(timer)
    }, [interval])

    // ── علامة القراءة: ما رآه الزائر والنافذة مفتوحة أمامه ──
    useEffect(() => {
        if (!open || !visible || !token) return

        const seq = lastOutboundSeq(messages)

        if (seq > (persisted.lastReadSeq ?? 0)) {
            persist({ lastReadSeq: seq })
            api.read(seq).catch(() => {})
        }
    }, [messages, open, visible, token])

    const unread = open && visible ? 0 : unreadCount(messages, persisted.lastReadSeq ?? 0)

    // ── صوت للردّ الجديد حين لا يراه الزائر ──
    useEffect(() => {
        const latest = lastOutboundSeq(messages)

        if (lastOutSeen.current !== null && latest > lastOutSeen.current && (!open || !visible) && config.widget.behavior.sound) {
            try {
                new Audio(`${ctx.base}/sounds/message-pop-alert.mp3`).play().catch(() => {})
            } catch {
                // المتصفّح يمنع الصوت قبل تفاعل: لا بأس.
            }
        }

        lastOutSeen.current = latest
    }, [messages])

    // ── عنوان الصفحة يومض والتاب مخفيّ وفيه ردّ لم يُقرأ ──
    useEffect(() => {
        if (visible || unread === 0) return

        const original = document.title
        let flip = false
        const timer = setInterval(() => {
            flip = !flip
            document.title = flip ? `(${unread}) ${t('new_message')}` : original
        }, 1500)

        return () => {
            clearInterval(timer)
            document.title = original
        }
    }, [visible, unread])

    /* ───────────── الأفعال ───────────── */

    /**
     * الجلسة بإجابات الأسئلة كلها. رفضُ الخادم لإجابة (422) يعيد سؤالها
     * بسببه؛ وما سواه (انقطاع، تمهّل) يُعرض مع «حاول مرة أخرى».
     */
    const startSession = async (): Promise<boolean> => {
        if (starting) return false

        setStarting(true)
        setStartError(null)

        try {
            const answered = Object.keys(progress.answers).length > 0 || progress.consented
            const result = await api.startSession({
                ...payload(questions, progress),
                ...(needsConsent ? { consent: progress.consented } : {}),
                website: honeypot.current,
                elapsed_ms: Math.max(0, Date.now() - (answered ? progress.startedAt : openedAt.current)),
                page_url: location.href,
                referrer: document.referrer || undefined,
                // لغة المحادثة كما يراها الزائر، لا لغة متصفّحه.
                locale: lang,
            })

            tokenRef.current = result.token
            persist({ token: result.token, visitorId: result.visitor.id, name: result.visitor.name, channel: result.channel, lastReadSeq: 0 })
            touch()
            // ما ينتظر النقاط (رسالة ما بعد الأسئلة، القائمة) يأتي بعد ما يبقى
            // من الأسئلة فوق المحادثة — ولا شيء يبقى لـ widget الموافقة وحدها.
            setHold((current) => (current ? { before: answeredIntake ? intakeMessages.length : 0 } : null))

            // بعد أسئلة البداية: القائمة. ومن ضغط فيها قبل الجلسة (widget بلا
            // أسئلة) تبقى رحلته كما هي.
            if (hasFaqs && !journeyRef.current) {
                saveJourney(newJourney())
            }

            return true
        } catch (error) {
            if (error instanceof ApiError && error.status === 422) {
                // رفض الخادم يُعرض فوراً كرفض المتصفّح، بلا نقاط قبله.
                setHold(null)
                const { progress: next, failed } = rewind(questions, progress, error.errors)
                saveProgress(next)
                setAttempt(failed ? { text: failed.previous, reason: failed.message } : null)

                if (!failed && !error.errors.consent) {
                    setStartError(t('error'))
                    setNotice(t('error'))
                }
            } else {
                // startError لشاشة الأسئلة، وnotice للمحادثة: widget بلا أسئلة
                // يبدأ جلسته مع أوّل رسالة.
                const message = error instanceof ApiError && error.status === 429 ? t('too_fast') : t('error')
                setStartError(message)
                setNotice(message)
            }

            return false
        } finally {
            setStarting(false)
        }
    }

    // آخر سؤال أُجيب: تُرسل الإجابات وتبدأ الجلسة.
    useEffect(() => {
        if (intake && step.kind === 'done' && !starting && !startError) {
            startSession()
        }
    }, [intake, step.kind])

    /** widget بلا أسئلة: الجلسة مع أوّل رسالة لا مع فتح النافذة. */
    const ensureSession = async (): Promise<boolean> => (tokenRef.current ? true : startSession())

    const answerIntake = (raw: string, country: string) => {
        if (step.kind !== 'ask') return

        touch()
        const checked = checkAnswer(step.question, raw, country)

        if (!checked.ok) {
            setAttempt({ text: raw, reason: t(REASON_TEXT[checked.reason]) })

            return
        }

        setAttempt(null)
        expectReply()
        saveProgress(
            checked.value === null
                ? skip(progress, step.question.id)
                : answer(progress, step.question.id, checked.value, step.question.validation === 'phone' ? country : undefined),
        )
    }

    const skipIntake = () => {
        if (step.kind === 'ask' && !step.question.required) {
            setAttempt(null)
            expectReply()
            saveProgress(skip(progress, step.question.id))
        }
    }

    /** أزرار الفقاعات المحلّية: الموافقة، وخيار سؤال القائمة. */
    const chooseIntake = (_message: WidgetMessage, optionId: string, title: string) => {
        if (optionId === 'consent') {
            expectReply()
            saveProgress({ ...progress, consented: true })
        } else if (optionId.startsWith('o:')) {
            answerIntake(title, '')
        }
    }

    /** بعد الإرسال وحين لا اتصال لحظي: نسأل بعد لحظة فيصل الصفّ المحفوظ والردّ الآلي سريعاً. */
    const soonFetch = () => {
        if (realtime !== 'connected') {
            setTimeout(fetchAfter, 1500)
            setTimeout(fetchAfter, 4000)
        }
    }

    const sendText = async (text: string, clientId = uuidv4()) => {
        touch()
        setNotice(null)
        setMessages((current) => addPending(current.filter((m) => m.client_id !== clientId || m.id), draft(clientId, { text })))

        // لم تبدأ الجلسة (انقطاع، تمهّل): تبقى الرسالة بزرّ إعادة المحاولة.
        if (!(await ensureSession())) {
            setMessages((current) => markFailed(current, clientId))

            return
        }

        const context = journeyContext(journeyRef.current)
        api.sendText(clientId, text, location.href, context)
            .then(() => {
                journeyDelivered(context)
                soonFetch()
            })
            .catch((error) => {
                setMessages((current) => markFailed(current, clientId))
                handleError(error)
            })
    }

    const choose = (message: WidgetMessage, optionId: string, title: string) => {
        // فقاعات أسئلة البداية (l:…) محلّية: لا خيارات لها عند الخادم.
        if (!message.id || message.id.startsWith('l:')) return

        touch()
        const clientId = uuidv4()
        const context = journeyContext(journeyRef.current)
        setMessages((current) => addPending(current, draft(clientId, { text: title })))
        api.sendChoice(clientId, message.id, optionId, location.href, context)
            .then(() => {
                journeyDelivered(context)
                soonFetch()
            })
            .catch((error) => {
                setMessages((current) => markFailed(current, clientId))
                handleError(error)
            })
    }

    const retry = (message: WidgetMessage) => {
        if (message.client_id && message.text && message.type === 'text') {
            sendText(message.text, message.client_id)
        }
    }

    /**
     * ما اختاره الزائر: أوّل files_per_send منها (3)، وما يُرفض نوعاً أو حجماً
     * يُقال باسمه قبل أن يُرفع. كل ملف يُرفع وحده بفقاعته، بالترتيب.
     */
    const sendFiles = async (files: File[]) => {
        if (!(await ensureSession())) return

        touch()
        const picked = pickFiles(files, config.limits)
        const rejected = picked.rejected[0]

        if (picked.overflow) {
            setNotice(t('too_many_files').replace('{n}', String(picked.max)))
        } else if (rejected) {
            setNotice(t(rejected.reason === 'size' ? 'file_too_large_named' : 'file_type_named').replace('{name}', rejected.name))
        } else {
            setNotice(null)
        }

        for (const file of picked.accepted) {
            uploadFile(file)
        }
    }

    const uploadFile = (file: File) => {
        const type = mediaType(file.type || '')
        const clientId = uuidv4()
        const preview = type === 'image' ? URL.createObjectURL(file) : null

        setMessages((current) =>
            addPending(current, draft(clientId, { type, preview, media: { url: null, name: file.name, mime: file.type, size: file.size } })),
        )
        const context = journeyContext(journeyRef.current)
        api.upload(clientId, file, '', context)
            .then(() => {
                journeyDelivered(context)
                soonFetch()
            })
            .catch((error) => {
                setMessages((current) => markFailed(current, clientId))
                handleError(error)
            })
    }

    const toggle = () => {
        touch()
        setOpen(!open)
    }

    const panelStyle = `--mnjz-color:${appearance.color};--mnjz-x:${appearance.offset_x}px;--mnjz-y:${appearance.offset_y}px`

    return (
        <div
            class={`mnjz-root mnjz-${appearance.position}`}
            dir={lang === 'ar' ? 'rtl' : 'ltr'}
            lang={lang}
            style={panelStyle}
            onKeyDown={(event) => {
                if (event.key === 'Escape' && open) setOpen(false)
            }}
        >
            {open && (
                <div class="mnjz-panel" role="dialog" aria-label={title}>
                    <header class="mnjz-head">
                        {appearance.logo_url && <img class="mnjz-logo" src={appearance.logo_url} alt="" />}
                        <div class="mnjz-head-text">
                            <strong>{title}</strong>
                            <span>{subtitle}</span>
                        </div>
                        {/* نصٌّ لا أيقونة: يكفي ويوفّر الحجم. يعرض اللغة الأخرى. */}
                        <button
                            type="button"
                            class="mnjz-lang"
                            aria-label={t('language')}
                            title={t('language')}
                            lang={lang === 'ar' ? 'en' : 'ar'}
                            onClick={switchLanguage}
                        >
                            {lang === 'ar' ? 'EN' : 'ع'}
                        </button>
                        <button type="button" class="mnjz-icon mnjz-close" aria-label={t('close')} onClick={() => setOpen(false)}>
                            <Icon icon={faXmark} />
                        </button>
                    </header>

                    {!config.available && !token ? (
                        <div class="mnjz-empty">{t('unavailable')}</div>
                    ) : intake ? (
                        // أسئلة البداية: فقاعاتها في السجلّ، والإجابة في خانة السؤال.
                        <>
                            {startError && (
                                <button type="button" class="mnjz-banner mnjz-banner-error" onClick={() => startSession()}>
                                    {startError} {t('retry')}
                                </button>
                            )}
                            <MessageList
                                messages={shown}
                                t={t}
                                lang={lang}
                                hasMore={false}
                                loadingEarlier={false}
                                onEarlier={() => {}}
                                onChoose={chooseIntake}
                                onRetry={() => {}}
                                welcome={welcome}
                                typing={typing}
                            />
                            <IntakeInput
                                question={step.kind === 'ask' && !typing ? step.question : null}
                                t={t}
                                lang={lang}
                                defaultCountry={prechat.default_country}
                                busy={starting}
                                waiting={typing}
                                onAnswer={answerIntake}
                                onSkip={skipIntake}
                                onHoneypot={(value) => {
                                    honeypot.current = value
                                }}
                            />
                        </>
                    ) : inJourney ? (
                        // القائمة والأسئلة الشائعة: فقاعات محلّية وأزرارها، بلا خانة كتابة.
                        <>
                            {!config.open_now && <div class="mnjz-banner">{t('offline')}</div>}
                            <MessageList
                                messages={shown}
                                t={t}
                                lang={lang}
                                hasMore={false}
                                loadingEarlier={false}
                                onEarlier={() => {}}
                                onChoose={pickJourney}
                                onRetry={() => {}}
                                welcome={welcome}
                                typing={typing}
                            />
                            <IntakeInput question={null} t={t} lang={lang} defaultCountry={prechat.default_country} busy={false} onAnswer={() => {}} onSkip={() => {}} onHoneypot={() => {}} />
                        </>
                    ) : (
                        <>
                            {!config.open_now && <div class="mnjz-banner">{t('offline')}</div>}
                            {notice && (
                                <button type="button" class="mnjz-banner mnjz-banner-error" onClick={() => setNotice(null)}>
                                    {notice}
                                </button>
                            )}
                            <MessageList
                                messages={preface.length && !hasMore ? shown.concat(messages) : messages}
                                t={t}
                                lang={lang}
                                hasMore={hasMore}
                                loadingEarlier={loadingEarlier}
                                onEarlier={loadEarlier}
                                onChoose={choose}
                                onRetry={retry}
                                welcome={welcome}
                                typing={typing}
                            />
                            <Composer
                                t={t}
                                maxLength={config.limits.text}
                                uploads={config.widget.behavior.uploads}
                                accept={config.limits.accept}
                                multiple={(config.limits.files_per_send ?? 3) > 1}
                                disabled={!config.available}
                                onSend={(text) => sendText(text)}
                                onFiles={sendFiles}
                                recentEmoji={recentEmoji}
                                onEmojiUsed={(emoji) => {
                                    const next = rememberEmoji(recentEmoji, emoji)
                                    setRecentEmoji(next)

                                    try {
                                        localStorage.setItem(EMOJI_KEY, JSON.stringify(next))
                                    } catch {
                                        // لا شيء.
                                    }
                                }}
                                autoFocus={open}
                            />
                        </>
                    )}
                </div>
            )}

            <button type="button" class="mnjz-launcher" aria-label={open ? t('close') : t('open')} aria-expanded={open} onClick={toggle}>
                {open ? <Icon icon={faXmark} class="mnjz-launcher-x" /> : <Icon icon={faCommentDots} class="mnjz-launcher-icon" />}
                {!open && unread > 0 && <span class="mnjz-badge">{unread > 9 ? '9+' : unread}</span>}
            </button>
        </div>
    )
}
