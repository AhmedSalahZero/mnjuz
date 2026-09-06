/**
 * تجميع الصور المرسَلة دفعةً واحدة في فقاعة واحدة — كما يفعل واتساب.
 *
 * السحب والإفلات يرسل كل صورة رسالةً مستقلّة (وواجهة واتساب السحابية لا
 * تعرف «الألبوم» أصلاً)، فكانت عشر صور تُنتج عشر فقاعات متراصّة تبتلع
 * المحادثة. الحلّ عرضٌ لا تخزين: نجمع المتتاليات في شبكة واحدة، وتبقى كل
 * صورة رسالةً قائمةً بذاتها في قاعدة البيانات وعند العميل.
 *
 * ما يجمعه: صورٌ متتالية، بالاتجاه نفسه، غير محذوفة، بين كل صورة والتي
 * تليها فجوة قصيرة. أي شيء آخر بينها — نصّ أو ملف أو رسالة من الطرف
 * الآخر — يقطع المجموعة، لأنه يقطعها فعلاً في نظر القارئ.
 */

/** أقصى فجوة بين صورتين متتاليتين لتُعدّا من دفعة واحدة (بالثواني). */
export const ALBUM_GAP_SECONDS = 60

/** أقلّ عدد يصنع ألبوماً — الصورة وحدها تبقى فقاعةً عادية. */
export const ALBUM_MIN_SIZE = 2

/** ما يُعرض من البلاطات قبل أن يصير الباقي «+N». */
export const ALBUM_MAX_TILES = 4

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
 * التعليق بعد تنظيفه. صفوف قديمة تحمل السلسلة "null" نصّاً، وهي غياب لا قيمة.
 */
export function captionOf(value) {
    if (value == null) {
        return ''
    }

    const text = String(value).trim()

    return text === '' || text.toLowerCase() === 'null' ? '' : text
}

/** تاريخ الرسالة بالثواني، أو null إن تعذّر فهمه. */
const timeOf = (value) => {
    const raw = value?.created_at

    if (typeof raw !== 'string' || raw === '') {
        return null
    }

    // 'Y-m-d H:i:s' كما يصل من الخادم؛ Safari لا يقبل المسافة فنحوّلها.
    const parsed = Date.parse(raw.replace(' ', 'T'))

    return Number.isNaN(parsed) ? null : parsed / 1000
}

/** هل هذه الرسالة صورة تصلح للضمّ؟ */
const isAlbumCandidate = (entry) => {
    if (!entry || entry.type !== 'chat') {
        return false
    }

    const value = entry.value

    if (!value || value.deleted_at) {
        return false
    }

    return parseMetadata(value.metadata).type === 'image'
}

/**
 * هل تنتمي الصورة الثانية إلى دفعة الأولى؟
 *
 * التاريخ المجهول لا يجمع: الضمّ الخاطئ يُخفي رسالةً في شبكة لا يتوقّعها
 * القارئ، والفصل الخاطئ لا يزيد على أن يترك الشكل كما كان.
 */
const belongsToSameBatch = (previous, next) => {
    if (previous.value.type !== next.value.type) {
        return false
    }

    const before = timeOf(previous.value)
    const after = timeOf(next.value)

    if (before === null || after === null) {
        return false
    }

    return Math.abs(after - before) <= ALBUM_GAP_SECONDS
}

/**
 * تحويل قائمة المحادثة إلى عناصر عرض: مفردة أو ألبوم.
 *
 * @param {Array<Array<object>>} chats قائمة كما تصل الواجهة: كل عنصر مصفوفة أوّلها {type, value}
 * @returns {Array<{kind: 'single'|'album', chat?: object, direction?: string, messages?: Array<object>, key: string}>}
 */
export function groupImageAlbums(chats) {
    if (!Array.isArray(chats)) {
        return []
    }

    const items = []
    let batch = []

    const flush = () => {
        if (batch.length === 0) {
            return
        }

        if (batch.length < ALBUM_MIN_SIZE) {
            for (const entry of batch) {
                items.push({ kind: 'single', chat: entry.chat, key: keyOf(entry.chat, items.length) })
            }
        } else {
            items.push({
                kind: 'album',
                direction: batch[0].entry.value.type,
                messages: batch.map((item) => item.entry.value),
                key: 'album-' + batch.map((item) => item.entry.value.id ?? '?').join('-'),
            })
        }

        batch = []
    }

    for (const chat of chats) {
        const entry = Array.isArray(chat) ? chat[0] : chat

        if (!isAlbumCandidate(entry)) {
            flush()
            items.push({ kind: 'single', chat, key: keyOf(chat, items.length) })
            continue
        }

        if (batch.length > 0 && !belongsToSameBatch(batch[batch.length - 1].entry, entry)) {
            flush()
        }

        batch.push({ chat, entry })
    }

    flush()

    return items
}

const keyOf = (chat, index) => {
    const entry = Array.isArray(chat) ? chat[0] : chat
    const id = entry?.value?.id

    return id ? entry.type + '-' + id : 'row-' + index
}

/**
 * حالة الألبوم الواحدة من حالات رسائله.
 *
 * الأدنى يحكم: ألبوم فيه صورة لم تُقرأ بعد ليس «مقروءاً». والفشل يعلو الكلّ
 * لأنه وحده ما يستدعي تصرّفاً من الموظّف.
 */
export function albumStatus(messages) {
    const order = ['sent', 'delivered', 'read']
    let lowest = null

    for (const message of messages ?? []) {
        const status = statusOf(message)

        if (status === 'failed') {
            return 'failed'
        }

        const rank = order.indexOf(status)

        if (rank === -1) {
            continue
        }

        if (lowest === null || rank < lowest) {
            lowest = rank
        }
    }

    return lowest === null ? 'sent' : order[lowest]
}

/**
 * حالة رسالة واحدة من سجلّاتها — بنفس قاعدة الفقاعة: آخر ما بلغته.
 */
export function statusOf(message) {
    const logs = Array.isArray(message?.logs) ? message.logs : []
    let status = message?.status ?? 'sent'

    for (const log of logs) {
        const value = parseMetadata(log?.metadata).status

        if (value === 'failed') {
            return 'failed'
        }

        if (value === 'read') {
            status = 'read'
        } else if (value === 'delivered' && status !== 'read') {
            status = 'delivered'
        } else if (value === 'sent' && status === 'failed') {
            status = 'sent'
        }
    }

    return status
}

/**
 * تعليق الألبوم: الإرسال الدفعي يُرفق التعليق بالصورة الأولى وحدها، فنعرض
 * أوّل تعليق موجود لا تعليق كل صورة.
 */
export function albumCaption(messages) {
    for (const message of messages ?? []) {
        const caption = captionOf(parseMetadata(message?.metadata).image?.caption)

        if (caption !== '') {
            return caption
        }
    }

    return ''
}

/** البلاطات المعروضة وعدد ما زاد عنها. */
export function albumTiles(messages) {
    const all = Array.isArray(messages) ? messages : []
    const tiles = all.slice(0, ALBUM_MAX_TILES)

    return { tiles, hidden: Math.max(0, all.length - tiles.length) }
}
