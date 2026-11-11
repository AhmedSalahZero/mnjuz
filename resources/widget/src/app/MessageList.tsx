import { useEffect, useRef, useState } from 'preact/hooks'
import { faArrowUpRightFromSquare, faCheck, faListUl, faXmark } from '@fortawesome/free-solid-svg-icons'
import { faClock, faFileLines } from '@fortawesome/free-regular-svg-icons'
import type { Interactive, WidgetMessage } from '../core/types.ts'
import type { Translate } from '../core/i18n.ts'
import { linkify, safeHref } from '../core/linkify.ts'
import { Icon } from './Icon.tsx'

interface Props {
    messages: WidgetMessage[]
    t: Translate
    lang: 'ar' | 'en'
    hasMore: boolean
    loadingEarlier: boolean
    onEarlier: () => void
    onChoose: (message: WidgetMessage, optionId: string, title: string) => void
    onRetry: (message: WidgetMessage) => void
    welcome: string
}

/** نصّ بروابط قابلة للضغط — عُقد نصّية لا HTML (انظر core/linkify). */
function RichText({ text }: { text: string }) {
    return (
        <span class="mnjz-text">
            {linkify(text).map((segment, i) =>
                segment.kind === 'link' ? (
                    <a key={i} href={segment.href} target="_blank" rel="noopener noreferrer nofollow">
                        {segment.text}
                    </a>
                ) : (
                    segment.text
                ),
            )}
        </span>
    )
}

function timeOf(iso: string | null, lang: string): string {
    if (!iso) {
        return ''
    }

    try {
        return new Date(iso).toLocaleTimeString(lang === 'ar' ? 'ar' : 'en', { hour: 'numeric', minute: '2-digit' })
    } catch {
        return ''
    }
}

function Media({ message, t }: { message: WidgetMessage; t: Translate }) {
    const media = message.media
    const url = safeHref(media?.url ?? null)

    if (media?.failed) {
        return <div class="mnjz-file mnjz-file-failed">{t('upload_failed')}</div>
    }

    if (!url) {
        if (message.preview && message.type === 'image') {
            return <img class="mnjz-image mnjz-image-pending" src={message.preview} alt="" />
        }

        return <div class="mnjz-file">{t('uploading')}</div>
    }

    if (message.type === 'image') {
        return (
            <a href={url} target="_blank" rel="noopener noreferrer">
                <img class="mnjz-image" src={url} alt={media?.name ?? ''} loading="lazy" />
            </a>
        )
    }

    if (message.type === 'video') {
        return <video class="mnjz-image" src={url} controls preload="metadata" />
    }

    if (message.type === 'audio') {
        return <audio class="mnjz-audio" src={url} controls preload="none" />
    }

    return (
        <a class="mnjz-file" href={url} target="_blank" rel="noopener noreferrer" download>
            <Icon icon={faFileLines} /> {media?.name || 'file'}
        </a>
    )
}

function Choices({ message, interactive, t, onChoose }: { message: WidgetMessage; interactive: Interactive; t: Translate; onChoose: Props['onChoose'] }) {
    const [sheet, setSheet] = useState(false)

    if (interactive.kind === 'cta_url') {
        const href = safeHref(interactive.url ?? null)

        // داخل .mnjz-choices كالأزرار: الرابط وحده عنصرٌ سطري يجاور النص،
        // فتعلو حشوته السطر الذي فوقه وتغطّيه (زرّ التقييم بعد الإغلاق).
        return href ? (
            <div class="mnjz-choices">
                <a class="mnjz-choice mnjz-choice-link" href={href} target="_blank" rel="noopener noreferrer nofollow">
                    {interactive.display_text || href} <Icon icon={faArrowUpRightFromSquare} class="mnjz-svg-sm" />
                </a>
            </div>
        ) : null
    }

    if (interactive.kind === 'buttons') {
        return (
            <div class="mnjz-choices">
                {(interactive.buttons ?? []).map((button) => (
                    <button type="button" class="mnjz-choice" key={button.id} onClick={() => onChoose(message, button.id, button.title)}>
                        {button.title}
                    </button>
                ))}
            </div>
        )
    }

    return (
        <div class="mnjz-choices">
            <button type="button" class="mnjz-choice" onClick={() => setSheet(true)} aria-haspopup="dialog">
                <Icon icon={faListUl} /> {interactive.button || t('choose')}
            </button>
            {sheet && (
                <div class="mnjz-sheet" role="dialog" aria-label={interactive.button || t('choose')}>
                    <div class="mnjz-sheet-head">
                        <strong>{interactive.button || t('choose')}</strong>
                        <button type="button" class="mnjz-icon" aria-label={t('close')} onClick={() => setSheet(false)}>
                            <Icon icon={faXmark} />
                        </button>
                    </div>
                    {(interactive.sections ?? []).map((section, i) => (
                        <div key={i}>
                            {section.title && <div class="mnjz-sheet-section">{section.title}</div>}
                            {section.rows.map((row) => (
                                <button
                                    type="button"
                                    class="mnjz-sheet-row"
                                    key={row.id}
                                    onClick={() => {
                                        setSheet(false)
                                        onChoose(message, row.id, row.title)
                                    }}
                                >
                                    <span>{row.title}</span>
                                    {row.description && <small>{row.description}</small>}
                                </button>
                            ))}
                        </div>
                    ))}
                </div>
            )}
        </div>
    )
}

function Bubble({ message, t, lang, onChoose, onRetry, isLatest }: { message: WidgetMessage; t: Translate; lang: string; onChoose: Props['onChoose']; onRetry: Props['onRetry']; isLatest: boolean }) {
    const mine = message.direction === 'in'
    const interactive = message.interactive
    const header = interactive?.header
    const hasMedia = ['image', 'video', 'audio', 'document'].includes(message.type)
    const sender = !mine && message.sender.kind === 'agent' && message.sender.name ? message.sender.name : null

    return (
        <div class={`mnjz-row ${mine ? 'mnjz-mine' : 'mnjz-theirs'}`}>
            <div class={`mnjz-bubble${message.local === 'failed' ? ' mnjz-bubble-failed' : ''}`}>
                {sender && <div class="mnjz-sender">{sender}</div>}
                {header?.type === 'text' && header.text && <div class="mnjz-header">{header.text}</div>}
                {header && header.type !== 'text' && safeHref(header.url ?? null) && header.type === 'image' && (
                    <img class="mnjz-image" src={safeHref(header.url ?? null)!} alt="" loading="lazy" />
                )}
                {hasMedia && <Media message={message} t={t} />}
                {message.text && <RichText text={message.text} />}
                {interactive?.footer && <div class="mnjz-footer">{interactive.footer}</div>}
                {interactive && !mine && (isLatest || interactive.kind === 'cta_url') && <Choices message={message} interactive={interactive} t={t} onChoose={onChoose} />}
                <div class="mnjz-meta">
                    {message.local === 'failed' ? (
                        <button type="button" class="mnjz-retry" onClick={() => onRetry(message)}>
                            {t('failed')}
                        </button>
                    ) : (
                        <>
                            <span>{timeOf(message.created_at, lang)}</span>
                            {mine && <span class="mnjz-tick"><Icon icon={message.local === 'pending' ? faClock : faCheck} /></span>}
                        </>
                    )}
                </div>
            </div>
        </div>
    )
}

/** سجلّ المحادثة. الأزرار تُعرض تحت آخر رسالة وحدها: القديمة انتهى وقتها. */
export function MessageList({ messages, t, lang, hasMore, loadingEarlier, onEarlier, onChoose, onRetry, welcome }: Props) {
    const end = useRef<HTMLDivElement>(null)
    const count = messages.length
    const lastId = [...messages].reverse().find((m) => m.direction === 'out')?.id ?? null

    useEffect(() => {
        end.current?.scrollIntoView({ block: 'end' })
    }, [count])

    return (
        <div class="mnjz-messages" aria-live="polite" aria-relevant="additions">
            {hasMore && (
                <button type="button" class="mnjz-earlier" disabled={loadingEarlier} onClick={onEarlier}>
                    {t('earlier')}
                </button>
            )}
            {welcome && (
                <div class="mnjz-row mnjz-theirs">
                    <div class="mnjz-bubble">
                        <RichText text={welcome} />
                    </div>
                </div>
            )}
            {messages.map((message) => (
                <Bubble
                    key={message.id ?? message.client_id ?? ''}
                    message={message}
                    t={t}
                    lang={lang}
                    onChoose={onChoose}
                    onRetry={onRetry}
                    isLatest={message.id !== null && message.id === lastId && messages[messages.length - 1] === message}
                />
            ))}
            <div ref={end} />
        </div>
    )
}
