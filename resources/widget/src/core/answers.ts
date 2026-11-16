/**
 * التحقّق من إجابة سؤال البداية في المتصفّح، ليعرف الزائر سبب الرفض فوراً.
 *
 * مرآة لـ App\Services\WebChat\WebChatAnswers — والخادم هو الحَكَم: ما يقبله
 * هنا يُرسل، وما يرفضه هناك يعود بسببه. الحالات المشتركة في
 * tests/Fixtures/webchat/answers.json يمرّ بها الطرفان.
 *
 * الجوال: هنا الحروف والطول، والتمييز بين الجوال والأرضي للسعودية وحدها
 * (05xxxxxxxx). ما سواها يحكم فيه الخادم بمكتبة الأرقام الكاملة.
 */
export type Validation = 'text' | 'number' | 'email' | 'phone' | 'date' | 'select' | 'saudi_id'

export type Reason =
    | 'required'
    | 'too_long'
    | 'email'
    | 'number'
    | 'date'
    | 'select'
    | 'saudi_id'
    | 'phone_letters'
    | 'phone_invalid'
    | 'phone_landline'

export interface AnswerRule {
    target: string
    validation: Validation
    required: boolean
    options?: string[]
}

export type Checked = { ok: true; value: string | null } | { ok: false; reason: Reason }

const NAME_MAX = 80
const TEXT_MAX = 255
const EMAIL_MAX = 191

/** أرقام عربية وفارسية إلى لاتينية، بلا علامات الاتجاه، والمسافة غير القاطعة مسافة. */
export function asciiDigits(value: string): string {
    return value
        .replace(/[\u0660-\u0669]/g, (d) => String(d.charCodeAt(0) - 0x0660))
        .replace(/[\u06F0-\u06F9]/g, (d) => String(d.charCodeAt(0) - 0x06f0))
        .replace(/[\u200B-\u200F\u202A-\u202E\u2066-\u2069\uFEFF]/g, '')
        .replace(/\u00A0/g, ' ')
        .trim()
}

export function checkAnswer(rule: AnswerRule, raw: string, country: string): Checked {
    const value = raw.replace(/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/g, '').trim()

    if (value === '') {
        return rule.required ? { ok: false, reason: 'required' } : { ok: true, value: null }
    }

    switch (rule.validation) {
        case 'email': {
            const ok = value.length <= EMAIL_MAX && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)

            return ok ? { ok: true, value: value.toLowerCase() } : { ok: false, reason: 'email' }
        }
        case 'phone':
            return checkPhone(value, country)
        case 'number': {
            const number = asciiDigits(value).replace(/[٫,]/g, '.')

            return /^[+-]?\d+(\.\d+)?$/.test(number) ? { ok: true, value: number } : { ok: false, reason: 'number' }
        }
        case 'date':
            return checkDate(asciiDigits(value))
        case 'select':
            return (rule.options ?? []).includes(value) ? { ok: true, value } : { ok: false, reason: 'select' }
        case 'saudi_id': {
            const digits = asciiDigits(value).replace(/[\s-]/g, '')

            return isSaudiId(digits) ? { ok: true, value: digits } : { ok: false, reason: 'saudi_id' }
        }
        default: {
            const max = rule.target === 'name' ? NAME_MAX : TEXT_MAX

            return [...value].length > max ? { ok: false, reason: 'too_long' } : { ok: true, value }
        }
    }
}

/** الهوية الوطنية أو الإقامة: 10 أرقام تبدأ بـ 1 أو 2، وآخرها تحقّق Luhn. */
export function isSaudiId(digits: string): boolean {
    if (!/^[12]\d{9}$/.test(digits)) {
        return false
    }

    let sum = 0

    for (let i = 0; i < 10; i++) {
        const digit = Number(digits[i])

        if (i % 2 === 0) {
            const double = digit * 2
            sum += Math.floor(double / 10) + (double % 10)
        } else {
            sum += digit
        }
    }

    return sum % 10 === 0
}

/** ما يُرسل: الرقم كما كُتب بأرقام لاتينية. E.164 يحسبه الخادم. */
function checkPhone(value: string, country: string): Checked {
    const normalized = asciiDigits(value)

    if (/[^0-9+().\-\s]/.test(normalized)) {
        return { ok: false, reason: 'phone_letters' }
    }

    let digits = normalized.replace(/[^\d+]/g, '')

    if (digits.startsWith('00')) {
        digits = '+' + digits.slice(2)
    }

    if (digits.replace('+', '').length < 6 || digits.lastIndexOf('+') > 0) {
        return { ok: false, reason: 'phone_invalid' }
    }

    // السعودية (الدولة الافتراضية): الجوال 9 أرقام تبدأ بـ 5 بعد 966 أو الصفر.
    const saudi = digits.startsWith('+966') ? digits.slice(4) : !digits.startsWith('+') && country === 'SA' ? digits.replace(/^0/, '') : null

    if (saudi !== null && !/^5\d{8}$/.test(saudi)) {
        return { ok: false, reason: /^\d{8,9}$/.test(saudi) ? 'phone_landline' : 'phone_invalid' }
    }

    return { ok: true, value: normalized }
}

/** 2026-11-14 أو 14/11/2026 (يوم/شهر/سنة) ← 2026-11-14. */
function checkDate(value: string): Checked {
    let year: number, month: number, day: number
    let match = value.match(/^(\d{4})-(\d{1,2})-(\d{1,2})$/)

    if (match) {
        ;[year, month, day] = [Number(match[1]), Number(match[2]), Number(match[3])]
    } else if ((match = value.match(/^(\d{1,2})[/.\-](\d{1,2})[/.\-](\d{4})$/))) {
        ;[day, month, year] = [Number(match[1]), Number(match[2]), Number(match[3])]
    } else {
        return { ok: false, reason: 'date' }
    }

    const date = new Date(Date.UTC(year, month - 1, day))
    const valid =
        year >= 1900 && year <= 2100 && date.getUTCFullYear() === year && date.getUTCMonth() === month - 1 && date.getUTCDate() === day

    if (!valid) {
        return { ok: false, reason: 'date' }
    }

    return { ok: true, value: `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}` }
}
