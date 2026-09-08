/**
 * معرض الألبوم: الوصول إلى كل ملفات المجموعة، لا الأربعة الظاهرة فقط.
 *
 * الشبكة تعرض أربع بلاطات والباقي يختفي خلف «N+». وكانت آخر بلاطة تفتح
 * صورتها هي وحدها، فالصورة الخامسة فما فوق لا سبيل إليها إطلاقاً — شكا
 * العملاء أنهم يرون أربع صور من عشر أُرسلت إليهم. المعرض يفتح المجموعة
 * كاملة ويتنقّل بينها.
 */

const parseMetadata = (raw) => {
    if (raw && typeof raw === 'object') {
        return raw
    }

    if (typeof raw !== 'string') {
        return {}
    }

    try {
        return JSON.parse(raw) ?? {}
    } catch {
        return {}
    }
}

/**
 * عناصر المعرض: كل رسالة لها ملف، صورةً كانت أو فيديو.
 *
 * ما لا ملف له يُستبعد: المعرض لا يعرض «المحتوى غير متاح»، ووجوده بينها
 * يجعل السهم يقف على فراغ.
 *
 * @param {Array<object>} messages رسائل الألبوم بترتيبها
 * @returns {Array<{id: *, src: string, type: 'image'|'video'}>}
 */
export function galleryItems(messages) {
    if (!Array.isArray(messages)) {
        return []
    }

    const items = []

    for (const message of messages) {
        const src = message?.media?.path

        if (typeof src !== 'string' || src === '') {
            continue
        }

        const type = parseMetadata(message?.metadata).type === 'video' ? 'video' : 'image'

        items.push({ id: message?.id ?? null, src, type })
    }

    return items
}

/**
 * موضع رسالة في المعرض. غير الموجودة تفتح من أولها لا من فراغ.
 */
export function galleryIndexOf(items, messageId) {
    if (!Array.isArray(items)) {
        return 0
    }

    const index = items.findIndex((item) => item.id === messageId)

    return index < 0 ? 0 : index
}

/**
 * الموضع التالي أو السابق.
 *
 * يدور: آخر صورة ثم «التالي» يعود إلى الأولى. المجموعة صغيرة (عشر صور على
 * الأكثر)، والدوران يجنّب سهماً معطّلاً لا يُفهم سبب تعطّله.
 */
export function stepIndex(index, length, delta) {
    const total = Number(length)

    if (!Number.isFinite(total) || total <= 0) {
        return 0
    }

    const current = Number.isFinite(Number(index)) ? Number(index) : 0

    return ((current + delta) % total + total) % total
}
