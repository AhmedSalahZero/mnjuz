import { useEffect, useMemo, useRef, useState } from 'preact/hooks'
import { faCommentDots, faXmark } from '@fortawesome/free-solid-svg-icons'
import { ApiError, createApi } from '../core/api.ts'
import { mediaType, pickFiles } from '../core/files.ts'
import { resolveLanguage, translator } from '../core/i18n.ts'
import { connectRealtime, type RealtimeState } from '../core/realtime.ts'
import { sessionFields, type PrechatValues } from '../core/prechat.ts'
import { isLeader, parseLock, pollInterval, shouldConnectRealtime } from '../core/scheduler.ts'
import { addPending, lastOutboundSeq, lastSeq, firstSeq, markFailed, mergeMessages, unreadCount } from '../core/store.ts'
import { createStorage, type Persisted } from '../core/storage.ts'
import type { WidgetConfig, WidgetMessage } from '../core/types.ts'
import { uuidv4 } from '../core/uuid.ts'
import { Composer, rememberEmoji } from './Composer.tsx'
import { Icon } from './Icon.tsx'
import { MessageList } from './MessageList.tsx'
import { Prechat } from './Prechat.tsx'

export interface MountContext {
    base: string
    key: string
    config: WidgetConfig
    preview: boolean
    open: boolean
}

const PAGE_SIZE = 30
const EMOJI_KEY = 'mnjz:emoji'

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
    const lang = resolveLanguage(appearance.language, document.documentElement.lang, navigator.language)
    const t = useMemo(() => translator(lang), [lang])
    const storage = useMemo(() => createStorage(ctx.key), [ctx.key])

    const [persisted, setPersisted] = useState<Persisted>(() => storage.read())
    const tokenRef = useRef<string | null>(persisted.token ?? null)
    tokenRef.current = persisted.token ?? null
    const api = useMemo(() => createApi(ctx.base, ctx.key, () => tokenRef.current, ctx.preview), [ctx.base, ctx.key, ctx.preview])

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
    const title = appearance.title || t('title')
    const subtitle = appearance.subtitle || t('subtitle')

    const persist = (patch: Persisted) => setPersisted(storage.write(patch))
    const touch = () => {
        lastActivity.current = Date.now()
    }

    const resetSession = () => {
        storage.clear()
        setPersisted({})
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

    const startSession = async (values: PrechatValues): Promise<Record<string, string[]> | null> => {
        try {
            const result = await api.startSession({
                ...sessionFields(config.widget.prechat, values),
                page_url: location.href,
                referrer: document.referrer || undefined,
                locale: navigator.language,
            })

            tokenRef.current = result.token
            persist({ token: result.token, visitorId: result.visitor.id, name: result.visitor.name, channel: result.channel, lastReadSeq: 0 })
            touch()

            return null
        } catch (error) {
            if (error instanceof ApiError && error.status === 422) {
                return error.errors
            }

            return { form: [error instanceof ApiError && error.status === 429 ? t('too_fast') : t('error')] }
        }
    }

    /** بعد الإرسال وحين لا اتصال لحظي: نسأل بعد لحظة فيصل الصفّ المحفوظ والردّ الآلي سريعاً. */
    const soonFetch = () => {
        if (realtime !== 'connected') {
            setTimeout(fetchAfter, 1500)
            setTimeout(fetchAfter, 4000)
        }
    }

    const sendText = (text: string, clientId = uuidv4()) => {
        touch()
        setNotice(null)
        setMessages((current) => addPending(current.filter((m) => m.client_id !== clientId || m.id), draft(clientId, { text })))
        api.sendText(clientId, text, location.href)
            .then(soonFetch)
            .catch((error) => {
                setMessages((current) => markFailed(current, clientId))
                handleError(error)
            })
    }

    const choose = (message: WidgetMessage, optionId: string, title: string) => {
        if (!message.id) return

        touch()
        const clientId = uuidv4()
        setMessages((current) => addPending(current, draft(clientId, { text: title })))
        api.sendChoice(clientId, message.id, optionId, location.href)
            .then(soonFetch)
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
    const sendFiles = (files: File[]) => {
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
        api.upload(clientId, file, '')
            .then(soonFetch)
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
                        <button type="button" class="mnjz-icon mnjz-close" aria-label={t('close')} onClick={() => setOpen(false)}>
                            <Icon icon={faXmark} />
                        </button>
                    </header>

                    {!config.available && !token ? (
                        <div class="mnjz-empty">{t('unavailable')}</div>
                    ) : !token ? (
                        <div class="mnjz-body">
                            {appearance.welcome_message && <div class="mnjz-welcome">{appearance.welcome_message}</div>}
                            <Prechat config={config} lang={lang} t={t} onSubmit={startSession} />
                        </div>
                    ) : (
                        <>
                            {!config.open_now && <div class="mnjz-banner">{t('offline')}</div>}
                            {notice && (
                                <button type="button" class="mnjz-banner mnjz-banner-error" onClick={() => setNotice(null)}>
                                    {notice}
                                </button>
                            )}
                            <MessageList
                                messages={messages}
                                t={t}
                                lang={lang}
                                hasMore={hasMore}
                                loadingEarlier={loadingEarlier}
                                onEarlier={loadEarlier}
                                onChoose={choose}
                                onRetry={retry}
                                welcome={appearance.welcome_message}
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
