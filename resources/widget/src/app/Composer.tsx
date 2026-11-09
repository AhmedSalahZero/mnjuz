import { useEffect, useRef, useState } from 'preact/hooks'
import { faBox, faMugHot, faPaperclip, faPaperPlane } from '@fortawesome/free-solid-svg-icons'
import { faClock, faFaceSmile, faHeart, faThumbsUp } from '@fortawesome/free-regular-svg-icons'
import type { IconDefinition } from '@fortawesome/free-solid-svg-icons'
import { BRAND_URL } from '../core/brand.ts'
import type { TextKey, Translate } from '../core/i18n.ts'
import { EMOJI_GROUPS, RECENT_EMOJI_LIMIT, type EmojiGroupId } from './data.ts'
import { Icon } from './Icon.tsx'

interface Props {
    t: Translate
    maxLength: number
    uploads: boolean
    accept: string[]
    /** يختار عدّة ملفات مرّة واحدة؛ الحدّ يطبّقه من يستقبلها (pickFiles). */
    multiple: boolean
    disabled: boolean
    onSend: (text: string) => void
    onFiles: (files: File[]) => void
    recentEmoji: string[]
    onEmojiUsed: (emoji: string) => void
    autoFocus: boolean
}

const GROUP_ICONS: Record<EmojiGroupId, IconDefinition> = {
    smileys: faFaceSmile,
    gestures: faThumbsUp,
    hearts: faHeart,
    objects: faBox,
    food: faMugHot,
}

/**
 * خانة الكتابة: form واحدة فيها الإيموجي والإرفاق والنصّ وزرّ الإرسال،
 * وتحتها «بواسطة منجز شات». Enter يرسل، وShift+Enter سطر جديد.
 */
export function Composer({ t, maxLength, uploads, accept, multiple, disabled, onSend, onFiles, recentEmoji, onEmojiUsed, autoFocus }: Props) {
    const [text, setText] = useState('')
    const [emojiOpen, setEmojiOpen] = useState(false)
    const [group, setGroup] = useState<'recent' | EmojiGroupId>(recentEmoji.length ? 'recent' : EMOJI_GROUPS[0].id)
    const input = useRef<HTMLTextAreaElement>(null)
    const file = useRef<HTMLInputElement>(null)

    useEffect(() => {
        if (autoFocus) {
            input.current?.focus()
        }
    }, [autoFocus])

    const resize = () => {
        const el = input.current

        if (el) {
            el.style.height = 'auto'
            el.style.height = Math.min(el.scrollHeight, 120) + 'px'
        }
    }

    const submit = (event?: Event) => {
        event?.preventDefault()
        const value = text.trim()

        if (!value || disabled) {
            return
        }

        onSend(value)
        setText('')
        setEmojiOpen(false)
        requestAnimationFrame(resize)
    }

    const insertEmoji = (emoji: string) => {
        const el = input.current
        const start = el?.selectionStart ?? text.length
        const end = el?.selectionEnd ?? text.length
        const next = (text.slice(0, start) + emoji + text.slice(end)).slice(0, maxLength)

        setText(next)
        onEmojiUsed(emoji)

        requestAnimationFrame(() => {
            if (el) {
                el.focus()
                el.selectionStart = el.selectionEnd = start + emoji.length
            }
        })
    }

    const items = group === 'recent' ? recentEmoji : EMOJI_GROUPS.find((g) => g.id === group)?.items ?? []

    return (
        <div class="mnjz-composer">
            {emojiOpen && (
                <div class="mnjz-emoji" role="dialog" aria-label={t('emoji')}>
                    <div class="mnjz-emoji-tabs" role="tablist">
                        {recentEmoji.length > 0 && (
                            <button type="button" role="tab" aria-selected={group === 'recent'} aria-label={t('recent')} title={t('recent')} onClick={() => setGroup('recent')}>
                                <Icon icon={faClock} />
                            </button>
                        )}
                        {EMOJI_GROUPS.map((g) => {
                            const label = t(`emoji_${g.id}` as TextKey)

                            return (
                                <button type="button" role="tab" key={g.id} aria-selected={group === g.id} aria-label={label} title={label} onClick={() => setGroup(g.id)}>
                                    <Icon icon={GROUP_ICONS[g.id]} />
                                </button>
                            )
                        })}
                    </div>
                    <div class="mnjz-emoji-grid">
                        {items.map((emoji) => (
                            <button type="button" key={emoji} onClick={() => insertEmoji(emoji)} aria-label={emoji}>
                                {emoji}
                            </button>
                        ))}
                    </div>
                </div>
            )}
            <form class="mnjz-compose" onSubmit={submit}>
                <button type="button" class="mnjz-tool" aria-label={t('emoji')} title={t('emoji')} aria-expanded={emojiOpen} onClick={() => setEmojiOpen(!emojiOpen)}>
                    <Icon icon={faFaceSmile} />
                </button>
                {uploads && (
                    <>
                        <button type="button" class="mnjz-tool" aria-label={t('attach')} title={t('attach')} disabled={disabled} onClick={() => file.current?.click()}>
                            <Icon icon={faPaperclip} />
                        </button>
                        <input
                            ref={file}
                            type="file"
                            hidden
                            multiple={multiple}
                            accept={accept.join(',')}
                            onChange={(event) => {
                                const target = event.currentTarget as HTMLInputElement
                                const chosen = Array.from(target.files ?? [])

                                if (chosen.length) {
                                    onFiles(chosen)
                                }

                                target.value = ''
                            }}
                        />
                    </>
                )}
                <textarea
                    ref={input}
                    rows={1}
                    value={text}
                    maxLength={maxLength}
                    placeholder={t('placeholder')}
                    aria-label={t('placeholder')}
                    disabled={disabled}
                    onInput={(event) => {
                        setText((event.currentTarget as HTMLTextAreaElement).value)
                        resize()
                    }}
                    onKeyDown={(event) => {
                        if (event.key === 'Enter' && !event.shiftKey && !(event as KeyboardEvent).isComposing) {
                            submit(event)
                        }
                    }}
                />
                <button type="submit" class="mnjz-send" aria-label={t('send')} title={t('send')} disabled={disabled || !text.trim()}>
                    <Icon icon={faPaperPlane} />
                </button>
            </form>
            <a class="mnjz-powered" href={BRAND_URL} target="_blank" rel="noopener noreferrer">
                {t('powered_by')} <strong>{t('brand')}</strong>
            </a>
        </div>
    )
}

export function rememberEmoji(recent: string[], emoji: string): string[] {
    return [emoji].concat(recent.filter((e) => e !== emoji)).slice(0, RECENT_EMOJI_LIMIT)
}
