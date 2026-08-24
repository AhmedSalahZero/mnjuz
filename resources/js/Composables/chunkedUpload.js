/**
 * رفع ملف على قطع.
 *
 * Cloudflare يقطع أي طلب تجاوز ١٢٥ ثانية — لا أي حجم بعينه. وبسرعة رفع
 * ٠٫٣ ميغابايت/ثانية يعني ذلك أن أي ملف فوق ~٤٠ ميغابايت يفشل مهما أُرسل
 * وحده: يُسجَّل 499 على الخادم و524 عند العميل، ويتجمّد الشريط في منتصفه.
 *
 * القطعة تُقاس بالزمن لا بالحجم: خمسة ميغابايت على أبطأ شبكة معقولة تبقى دون
 * عشرين ثانية، فيبقى الهامش واسعاً مهما تذبذبت السرعة.
 */

/** حجم القطعة الواحدة. */
export const CHUNK_BYTES = 5 * 1024 * 1024

/**
 * حدود القطع لملف بحجم معلوم.
 *
 * @returns {Array<{index: number, start: number, end: number}>}
 */
export function planChunks(size, chunkBytes = CHUNK_BYTES) {
    const total = Number(size)
    const step = Number(chunkBytes)

    if (!Number.isFinite(total) || total <= 0 || !Number.isFinite(step) || step <= 0) {
        return []
    }

    const chunks = []

    for (let start = 0, index = 0; start < total; start += step, index++) {
        chunks.push({ index, start, end: Math.min(start + step, total) })
    }

    return chunks
}

/** عدد القطع اللازمة. */
export function chunkCount(size, chunkBytes = CHUNK_BYTES) {
    return planChunks(size, chunkBytes).length
}

/**
 * رفع ملف واحد على قطع متتابعة.
 *
 * التتابع مقصود لا توازٍ: القطع المتوازية تتقاسم نفس عرض النطاق فلا تُسرِّع
 * شيئاً، وتُصعّب حساب التقدّم، وتُربك الخادم في ترتيب الدمج.
 *
 * @param {{post: Function, file: File, fields: object, chunkBytes?: number,
 *          signal?: AbortSignal, onProgress?: (loaded: number, total: number) => void}} options
 */
export async function uploadInChunks({ post, file, fields, chunkBytes = CHUNK_BYTES, signal, onProgress }) {
    const chunks = planChunks(file.size, chunkBytes)

    if (chunks.length === 0) {
        throw new Error('ملف بلا محتوى.')
    }

    let uploaded = 0
    let last = null

    for (const chunk of chunks) {
        if (signal?.aborted) {
            const aborted = new Error('أُلغي الرفع.')
            aborted.name = 'AbortError'
            throw aborted
        }

        const body = new FormData()
        Object.entries(fields).forEach(([key, value]) => {
            if (value !== null && value !== undefined && value !== '') {
                body.append(key, value)
            }
        })
        body.append('index', String(chunk.index))
        body.append('total', String(chunks.length))
        body.append('chunk', file.slice(chunk.start, chunk.end), 'chunk')

        const base = uploaded

        last = await post('/chats/upload/chunk', body, {
            signal,
            onUploadProgress: (event) => {
                // التقدّم يُقاس على الملف كلّه: نسبة القطعة وحدها تقفز وترتدّ
                // مع كل قطعة، فيبدو الشريط مضطرباً بلا معنى.
                onProgress?.(Math.min(base + (event.loaded ?? 0), file.size), file.size)
            },
        })

        uploaded = chunk.end
        onProgress?.(uploaded, file.size)
    }

    return last
}
