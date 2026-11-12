import { useMemo, useRef, useState } from 'preact/hooks'
import type { WidgetConfig } from '../core/types.ts'
import type { Translate } from '../core/i18n.ts'
import { phoneMode, type PrechatValues } from '../core/prechat.ts'
import { COUNTRIES } from './data.ts'

interface Props {
    config: WidgetConfig
    lang: 'ar' | 'en'
    t: Translate
    onSubmit: (values: PrechatValues) => Promise<Record<string, string[]> | null>
}

/**
 * نموذج الزائر: الاسم إجباري، والجوال والبريد والموافقة بحسب الإعداد.
 *
 * حقل website مخفيّ عن البشر (الروبوت يملؤه)، والوقت منذ ظهور النموذج
 * يُرسل مع الطلب — من يرسله في أقلّ من ثانية ونصف ليس إنساناً.
 */
export function Prechat({ config, lang, t, onSubmit }: Props) {
    const prechat = config.widget.prechat
    const phone = phoneMode(prechat)
    const shownAt = useRef(Date.now())
    const countries = useMemo(() => {
        const list = COUNTRIES.slice()
        const index = list.findIndex((c) => c.code === prechat.default_country)

        if (index > 0) {
            list.unshift(...list.splice(index, 1))
        }

        return list
    }, [prechat.default_country])

    const [values, setValues] = useState({
        name: '',
        phone: '',
        country: countries.some((c) => c.code === prechat.default_country) ? prechat.default_country : 'SA',
        email: '',
        consent: false,
        website: '',
    })
    const [errors, setErrors] = useState<Record<string, string[]>>({})
    const [busy, setBusy] = useState(false)

    const set = (key: keyof typeof values) => (event: Event) => {
        const target = event.currentTarget as HTMLInputElement
        setValues({ ...values, [key]: target.type === 'checkbox' ? target.checked : target.value })
    }

    const submit = async (event: Event) => {
        event.preventDefault()

        if (busy) {
            return
        }

        setBusy(true)
        const result = await onSubmit({ ...values, elapsed_ms: Date.now() - shownAt.current })
        setBusy(false)
        setErrors(result ?? {})
    }

    const error = (key: string) => (errors[key]?.[0] ? <span class="mnjz-error">{errors[key][0]}</span> : null)

    return (
        <form class="mnjz-prechat" onSubmit={submit} noValidate>
            <p class="mnjz-muted">{t('prechat_intro')}</p>

            <label class="mnjz-field">
                <span>{prechat.name_label || t('name')}</span>
                <input type="text" name="name" autoComplete="name" maxLength={80} required value={values.name} onInput={set('name')} />
                {error('name')}
            </label>

            {phone !== 'off' && (
                <label class="mnjz-field">
                    <span>
                        {prechat.phone_label || t('phone')}
                        {phone === 'optional' ? ` (${t('optional')})` : ''}
                    </span>
                    <div class="mnjz-phone" dir="ltr">
                        <select aria-label={t('phone')} value={values.country} onChange={set('country')}>
                            {countries.map((c) => (
                                <option value={c.code} key={c.code} title={c.name[lang]}>
                                    {`+${c.dial} ${c.code}`}
                                </option>
                            ))}
                        </select>
                        <input type="tel" name="phone" autoComplete="tel" inputMode="tel" maxLength={32} required={phone === 'required'} value={values.phone} onInput={set('phone')} />
                    </div>
                    {error('phone')}
                </label>
            )}

            {prechat.email !== 'off' && (
                <label class="mnjz-field">
                    <span>
                        {t('email')}
                        {prechat.email === 'optional' ? ` (${t('optional')})` : ''}
                    </span>
                    <input type="email" name="email" autoComplete="email" maxLength={191} value={values.email} onInput={set('email')} />
                    {error('email')}
                </label>
            )}

            {prechat.consent_text && (
                <label class="mnjz-consent">
                    <input type="checkbox" checked={values.consent} onChange={set('consent')} />
                    <span>{prechat.consent_text}</span>
                    {error('consent')}
                </label>
            )}

            {/* للروبوتات وحدها: مخفيّ عن العين وعن قارئ الشاشة. */}
            <input class="mnjz-hp" type="text" name="website" tabIndex={-1} autoComplete="off" aria-hidden="true" value={values.website} onInput={set('website')} />

            {error('form')}

            <button type="submit" class="mnjz-primary" disabled={busy}>
                {busy ? t('starting') : t('start')}
            </button>
        </form>
    )
}
