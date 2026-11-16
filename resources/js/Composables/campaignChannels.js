/**
 * منتقي قناة الحملة: واتساب، تيليجرام، البريد، وSMS (قريباً). اختيار واحد،
 * ولكل قناة شكل رسالتها — الحدود تأتي من الخادم (CampaignChannels::rules)
 * وتُفحص هنا مبكّراً فقط؛ الخادم يُعيد الفحص.
 *
 * منطق خالص ليُختبر في node.
 */

/** قناة تُختار الآن: مربوطة وليست «قريباً». */
export const isSelectableChannel = (option) => Boolean(option?.connected) && !option?.coming_soon

/** واتساب إن كانت مربوطة، وإلّا أوّل قناة مربوطة، وإلّا واتساب. */
export const initialCampaignChannel = (options = []) => {
    const whatsapp = options.find((o) => o.value === 'whatsapp')
    if (whatsapp && isSelectableChannel(whatsapp)) return 'whatsapp'

    return options.find(isSelectableChannel)?.value ?? 'whatsapp'
}

/** أقصى طول للنصّ: تعليق تيليجرام تحت مرفق 1024، والرسالة وحدها 4096. */
export const messageLimit = (rules = {}, hasMedia = false) => {
    if (hasMedia && rules.caption_max) return rules.caption_max

    return rules.text_max ?? null
}

/** نوع المرفق كما يفهمه الخادم من MIME. */
export const mediaTypeOf = (mime = '') => {
    if (mime.startsWith('image/')) return 'image'
    if (mime.startsWith('video/')) return 'video'
    if (mime.startsWith('audio/')) return 'audio'

    return 'document'
}

/** قيمة accept لحقل الملف: بلا قيد إن قُبلت المستندات (كل نوع مستند). */
export const mediaAccept = (rules = {}) => {
    const types = Object.keys(rules.media_limits ?? {})

    if (types.includes('document') || types.length === 0) return ''

    return types.map((type) => `${type}/*`).join(',')
}

/** هل تقبل القناة مرفقات أصلاً؟ */
export const acceptsMedia = (rules = {}) => Object.keys(rules.media_limits ?? {}).length > 0

/**
 * ما يمنع إرفاق الملف قبل رفعه: امتداد ممنوع، نوع لا تقبله القناة، أو حجم
 * أكبر من حدّها. null = مقبول.
 *
 * @returns {null | {key: string, params?: object}}
 */
export const fileProblem = (rules = {}, file = null) => {
    if (!file) return null

    const name = String(file.name ?? '')
    const extension = name.includes('.') ? name.split('.').pop().toLowerCase() : ''

    if ((rules.blocked_extensions ?? []).includes(extension)) {
        return { key: 'This file type cannot be sent as an attachment.' }
    }

    const limits = rules.media_limits ?? {}
    const type = mediaTypeOf(String(file.type ?? ''))

    if (!(type in limits)) {
        return { key: 'This channel does not accept this type of file.' }
    }

    const limit = limits[type]

    if (limit && file.size > limit) {
        return { key: 'The file is larger than {limit} MB.', params: { limit: Math.round((limit / 1024 / 1024) * 10) / 10 } }
    }

    return null
}

/** قوالب القناة المحفوظة كخيارات قائمة. */
export const savedTemplateOptions = (channelTemplates = {}, channel = '') => (channelTemplates?.[channel] ?? [])
    .map((template) => ({ value: template.uuid, label: template.name }))

/** القالب المحفوظ المختار، أو null. */
export const findSavedTemplate = (channelTemplates = {}, channel = '', uuid = '') => (channelTemplates?.[channel] ?? [])
    .find((template) => template.uuid === uuid) ?? null
