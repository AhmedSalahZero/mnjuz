/**
 * ما يختاره الزائر من ملفات: كم يُرسل منها، وما يُرفض قبل الرفع.
 *
 * الخادم يفحص كل ملف من جديد (النوع من المحتوى والحجم الفعلي) — هذا الفحص
 * لتوفير رفعٍ يُرفض حتماً، وليُقال للزائر السبب باسم ملفه.
 */

export const DEFAULT_FILES_PER_SEND = 3

export type MediaType = 'image' | 'video' | 'audio' | 'document'

export interface FileLimits {
    media: Record<string, number>
    accept: string[]
    files_per_send?: number
}

/** أقلّ ما يحتاجه الفحص من File — فيُختبر بكائنات عادية. */
export interface PickableFile {
    name: string
    type: string
    size: number
}

export interface Picked<F extends PickableFile> {
    accepted: F[]
    rejected: { name: string; reason: 'type' | 'size' }[]
    /** اختار أكثر من الحدّ: أُخذ أوّل الحدّ وتُرك الباقي. */
    overflow: boolean
    max: number
}

export function mediaType(mime: string): MediaType {
    if (mime.startsWith('image/')) return 'image'
    if (mime.startsWith('video/')) return 'video'
    if (mime.startsWith('audio/')) return 'audio'

    return 'document'
}

export function filesPerSend(limits: FileLimits): number {
    const max = Number(limits.files_per_send)

    return Number.isInteger(max) && max > 0 ? max : DEFAULT_FILES_PER_SEND
}

export function pickFiles<F extends PickableFile>(files: ArrayLike<F>, limits: FileLimits): Picked<F> {
    const max = filesPerSend(limits)
    const list = Array.from(files)
    const result: Picked<F> = { accepted: [], rejected: [], overflow: list.length > max, max }

    for (const file of list.slice(0, max)) {
        // نوعٌ لا يعرفه المتصفّح (فارغ) يُترك للخادم: هو يحكم من المحتوى.
        if (file.type && limits.accept.length && !limits.accept.includes(file.type)) {
            result.rejected.push({ name: file.name, reason: 'type' })
            continue
        }

        const limit = limits.media[mediaType(file.type || '')]

        if (limit && file.size > limit) {
            result.rejected.push({ name: file.name, reason: 'size' })
            continue
        }

        result.accepted.push(file)
    }

    return result
}
