/**
 * نصّ الرسالة مقطّعاً إلى نصّ وروابط — بلا HTML أبداً.
 *
 * الـ widget يعمل داخل موقع التاجر: innerHTML بنصّ كتبه موظّف أو زائر
 * يُنفّذ ما فيه هناك. فالمكوّن يرسم المقاطع عُقداً نصّية، والرابط عنصر <a>
 * لا يُقبل إلا بـ http(s).
 */
export type Segment =
    | { kind: 'text'; text: string }
    | { kind: 'link'; text: string; href: string }

const URL_PATTERN = /\bhttps?:\/\/[^\s<>"']+/gi
const TRAILING_PUNCTUATION = /[.,!?;:)\]}'"،؛؟]+$/

export function safeHref(value: string | null | undefined): string | null {
    if (!value) {
        return null
    }

    try {
        const url = new URL(value)

        return url.protocol === 'http:' || url.protocol === 'https:' ? url.href : null
    } catch {
        return null
    }
}

export function linkify(input: string | null | undefined): Segment[] {
    const text = input ?? ''
    const segments: Segment[] = []
    const pattern = new RegExp(URL_PATTERN.source, 'gi')
    let last = 0
    let match: RegExpExecArray | null

    while ((match = pattern.exec(text)) !== null) {
        let url = match[0]
        const trailing = url.match(TRAILING_PUNCTUATION)

        if (trailing) {
            url = url.slice(0, -trailing[0].length)
        }

        const start = match.index

        if (start > last) {
            segments.push({ kind: 'text', text: text.slice(last, start) })
        }

        const href = safeHref(url)
        segments.push(href ? { kind: 'link', text: url, href } : { kind: 'text', text: url })
        last = start + url.length
        pattern.lastIndex = last
    }

    if (last < text.length) {
        segments.push({ kind: 'text', text: text.slice(last) })
    }

    return segments
}
