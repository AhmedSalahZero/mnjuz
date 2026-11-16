/**
 * عرض حملة لا تحمل قالب واتساب (تيليجرام، البريد…).
 *
 * كانت القائمة وصفحة الحملة تقرآن template.name و metadata قالب واتساب
 * مباشرةً، فتسقطان مع أوّل حملة تيليجرام (template = null، metadata = "[]").
 * منطق خالص ليُختبر في node.
 */

export const WHATSAPP = 'whatsapp'

/** حملة قالب واتساب؟ الحملة القديمة بلا قناة واتساب. */
export const isWhatsappCampaign = (channel) => (channel || WHATSAPP) === WHATSAPP

/**
 * القنوات التي تُبلغ عن التسليم والقراءة. تيليجرام لا تُبلغ البوت بشيء،
 * والبريد لا نتتبّع فتحه.
 */
export const tracksDelivery = (channel) => !['telegram', 'email', 'sms'].includes(channel || WHATSAPP)

/**
 * ما يُعرض في عمود «القالب»: قالب واتساب، أو القالب المحفوظ الذي أُنشئت
 * منه الحملة، أو اسم القناة (رسالة كُتبت مباشرة).
 */
export const campaignTemplateLabel = (item, channelLabel = '') => {
    if (item?.template?.name) return item.template.name
    if (item?.message_template_name) return item.message_template_name

    return channelLabel || '—'
}

/**
 * حمولة الرسالة الحرّة كما خُزّنت (نصّ JSON، أو "[]" في الحملات القديمة).
 *
 * @returns {{text: string, media: ?object, subject: string, template: ?object}}
 */
export const parseMessagePayload = (raw) => {
    let decoded = raw

    if (typeof raw === 'string') {
        try {
            decoded = JSON.parse(raw)
        } catch {
            decoded = null
        }
    }

    if (!decoded || typeof decoded !== 'object' || Array.isArray(decoded)) {
        return { text: '', media: null, subject: '', template: null }
    }

    return {
        text: typeof decoded.text === 'string' ? decoded.text : '',
        media: decoded.media && typeof decoded.media === 'object' ? decoded.media : null,
        subject: typeof decoded.subject === 'string' ? decoded.subject : '',
        template: decoded.template && typeof decoded.template === 'object' ? decoded.template : null,
    }
}

/** metadata قالب واتساب، بأمان: "[]" والقيم التالفة تصبح كائناً فارغاً. */
export const parseTemplateMetadata = (raw) => {
    try {
        const decoded = typeof raw === 'string' ? JSON.parse(raw) : raw

        return decoded && typeof decoded === 'object' && !Array.isArray(decoded) ? decoded : {}
    } catch {
        return {}
    }
}
