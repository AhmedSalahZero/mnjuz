import { useEffect, useMemo, useRef, useState } from 'preact/hooks'
import { faPaperPlane } from '@fortawesome/free-solid-svg-icons'
import { BRAND_URL } from '../core/brand.ts'
import type { Translate } from '../core/i18n.ts'
import type { IntakeQuestion } from '../core/types.ts'
import { COUNTRIES } from './data.ts'
import { Icon } from './Icon.tsx'

interface Props {
    /** السؤال الحالي؛ null حين يُختار من فقاعة (الموافقة) أو تُرسل الإجابات. */
    question: IntakeQuestion | null
    t: Translate
    defaultCountry: string
    busy: boolean
    onAnswer: (value: string, country: string) => void
    onSkip: () => void
    onHoneypot: (value: string) => void
}

/** لوحة مفاتيح كل نوع: أرقام للجوال والهوية، وبريد للبريد، واتجاهها من اليسار. */
const INPUTS: Record<string, { type: string; inputMode?: string; autoComplete?: string; ltr?: boolean }> = {
    name: { type: 'text', autoComplete: 'name' },
    email: { type: 'email', inputMode: 'email', autoComplete: 'email', ltr: true },
    phone: { type: 'tel', inputMode: 'tel', autoComplete: 'tel-national', ltr: true },
    number: { type: 'text', inputMode: 'decimal', ltr: true },
    saudi_id: { type: 'text', inputMode: 'numeric', ltr: true },
    date: { type: 'date', ltr: true },
    text: { type: 'text' },
}

/**
 * خانة الإجابة أثناء أسئلة البداية: مكان خانة الكتابة، بلوحة مفاتيح السؤال.
 * سؤال الجوال معه الدولة، والاختياري معه «تخطٍّ»، والقائمة تُختار من فقاعتها.
 * حقل website المخفيّ للروبوتات وحدها.
 */
export function IntakeInput({ question, t, defaultCountry, busy, onAnswer, onSkip, onHoneypot }: Props) {
    const [value, setValue] = useState('')
    const countries = useMemo(() => {
        const list = COUNTRIES.slice()
        const index = list.findIndex((c) => c.code === defaultCountry)

        if (index > 0) {
            list.unshift(...list.splice(index, 1))
        }

        return list
    }, [defaultCountry])
    const [country, setCountry] = useState(countries.some((c) => c.code === defaultCountry) ? defaultCountry : 'SA')
    const input = useRef<HTMLInputElement>(null)

    // سؤال جديد: خانة فارغة والمؤشّر فيها.
    useEffect(() => {
        setValue('')
        input.current?.focus()
    }, [question?.id])

    const typing = question !== null && question.validation !== 'select'
    const spec = question ? INPUTS[question.target === 'name' ? 'name' : question.validation] ?? INPUTS.text : INPUTS.text

    const submit = (event: Event) => {
        event.preventDefault()

        if (question && !busy && value.trim()) {
            onAnswer(value, country)
            // تُفرَّغ دائماً: الإجابة المرفوضة باقية فقاعةً فوق، ولو بقيت هنا
            // لالتصقت بها الكتابة التالية.
            setValue('')
        }
    }

    return (
        <div class="mnjz-composer">
            {question && !question.required && !busy && (
                <button type="button" class="mnjz-skip" onClick={onSkip}>
                    {t('skip')}
                </button>
            )}
            {typing ? (
                <form class="mnjz-compose" onSubmit={submit}>
                    {question.validation === 'phone' && (
                        <select
                            class="mnjz-country"
                            aria-label={t('phone')}
                            dir="ltr"
                            value={country}
                            onChange={(event) => setCountry((event.currentTarget as HTMLSelectElement).value)}
                        >
                            {countries.map((c) => (
                                <option key={c.code} value={c.code}>
                                    +{c.dial} {c.code}
                                </option>
                            ))}
                        </select>
                    )}
                    <input
                        ref={input}
                        class="mnjz-answer"
                        type={spec.type}
                        inputMode={spec.inputMode as any}
                        autoComplete={spec.autoComplete}
                        dir={spec.ltr ? 'ltr' : undefined}
                        value={value}
                        placeholder={t('answer')}
                        aria-label={t('answer')}
                        disabled={busy}
                        onInput={(event) => setValue((event.currentTarget as HTMLInputElement).value)}
                    />
                    <button type="submit" class="mnjz-send" aria-label={t('send')} title={t('send')} disabled={busy || !value.trim()}>
                        <Icon icon={faPaperPlane} />
                    </button>
                </form>
            ) : (
                <p class="mnjz-hint">{busy ? t('starting') : t('choose_above')}</p>
            )}
            <input
                class="mnjz-hp"
                type="text"
                name="website"
                tabIndex={-1}
                autoComplete="off"
                aria-hidden="true"
                onInput={(event) => onHoneypot((event.currentTarget as HTMLInputElement).value)}
            />
            <a class="mnjz-powered" href={BRAND_URL} target="_blank" rel="noopener noreferrer">
                {t('powered_by')} <strong>{t('brand')}</strong>
            </a>
        </div>
    )
}
