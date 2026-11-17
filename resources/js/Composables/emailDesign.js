/**
 * منطق builder قالب البريد بلا واجهة: أنواع العناصر وقيمها الافتراضية،
 * وإضافتها وتكرارها ونقلها، وملخّصها في «ترتيب البريد»، والمحاذاة.
 *
 * يطابق شكل app/Services/Email/EmailDesign.php — الخادم هو الحَكَم (قائمة
 * سماح وحدود)، وهذا ما تبدأ به الواجهة.
 */

export const BLOCK_TYPES = ['header', 'heading', 'text', 'image', 'button', 'image_text', 'divider', 'spacer', 'social', 'footer']

/** أسماء العناصر (مفاتيح ترجمة). */
export const BLOCK_LABELS = {
    header: 'Email header',
    heading: 'Heading',
    text: 'Text',
    image: 'Image',
    button: 'Button',
    image_text: 'Image and text',
    divider: 'Divider',
    spacer: 'Spacer',
    social: 'Social links',
    footer: 'Footer',
}

export const FONTS = [
    { value: 'tahoma', label: 'Tahoma', stack: 'Tahoma, Arial, sans-serif' },
    { value: 'arial', label: 'Arial', stack: 'Arial, Helvetica, sans-serif' },
    { value: 'verdana', label: 'Verdana', stack: 'Verdana, Geneva, sans-serif' },
    { value: 'georgia', label: 'Georgia', stack: 'Georgia, Times New Roman, serif' },
]

export const SOCIAL_NETWORKS = ['website', 'instagram', 'x', 'facebook', 'tiktok', 'snapchat', 'youtube', 'linkedin', 'whatsapp']

/** أسماء الشبكات (مفاتيح ترجمة؛ X تبقى كما هي). */
export const NETWORK_LABELS = {
    website: 'Website',
    instagram: 'Instagram',
    x: 'X',
    facebook: 'Facebook',
    tiktok: 'TikTok',
    snapchat: 'Snapchat',
    youtube: 'YouTube',
    linkedin: 'LinkedIn',
    whatsapp: 'WhatsApp',
}

export const MAX_BLOCKS = 60

export const defaultSettings = (direction = 'rtl') => ({
    page_bg: '#f4f5f7',
    content_bg: '#ffffff',
    text_color: '#1f2937',
    font: 'tahoma',
    direction,
})

export const emptyDesign = (direction = 'rtl') => ({ version: 1, settings: defaultSettings(direction), blocks: [] })

/**
 * الخصائص الافتراضية لكل عنصر — الألوان والأحجام كما في الخادم، والنصوص
 * مترجمة بـ t (الترجمة لا تحوي {first_name} حرفياً: تُمرَّر معاملاً).
 */
export const blockDefaults = (type, t = (key) => key) => {
    switch (type) {
        case 'header':
            return { text: '{organization_name}', logo: '', bg_color: '#034737', color: '#ffffff', align: 'center', font_size: 22 }
        case 'heading':
            return { text: t('Your headline here'), color: '#111827', align: 'center', font_size: 28 }
        case 'text':
            return { text: t('Write your message here.'), color: '#374151', align: 'start', font_size: 16 }
        case 'image':
            return { src: '', alt: '', link: '', width: 100, align: 'center' }
        case 'button':
            return { label: t('Book now'), url: '', bg_color: '#034737', color: '#ffffff', align: 'center' }
        case 'image_text':
            return { src: '', alt: '', text: t('Describe your offer or service here.'), image_position: 'start', color: '#374151', font_size: 16 }
        case 'divider':
            return { color: '#e5e7eb' }
        case 'spacer':
            return { height: 24 }
        case 'social':
            return { links: [], color: '#034737', align: 'center' }
        case 'footer':
            return { text: '{organization_name}', color: '#6b7280', align: 'center', font_size: 13 }
        default:
            return {}
    }
}

let sequence = 0

/** معرّف يقبله الخادم (/^[A-Za-z0-9_-]{1,40}$/) ويبقى فريداً في الجلسة. */
export const newBlockId = () => {
    sequence += 1

    return `b${Date.now().toString(36)}${sequence.toString(36)}`
}

const clone = (value) => JSON.parse(JSON.stringify(value))

export const createBlock = (type, t, props = {}) => ({
    id: newBlockId(),
    type,
    props: { ...blockDefaults(type, t), ...props },
})

export const duplicateBlock = (block) => ({ id: newBlockId(), type: block.type, props: clone(block.props) })

/** نقل عنصر خطوة لأعلى (-1) أو لأسفل (+1)؛ خارج الحدود لا يتغيّر شيء. */
export const moveBlock = (blocks, index, delta) => {
    const target = index + delta

    if (index < 0 || index >= blocks.length || target < 0 || target >= blocks.length) {
        return blocks
    }

    const copy = [...blocks]
    const [item] = copy.splice(index, 1)
    copy.splice(target, 0, item)

    return copy
}

/** إضافة بعد المحدد، أو في الآخر. */
export const insertBlockAfter = (blocks, selectedId, block) => {
    const index = blocks.findIndex((item) => item.id === selectedId)

    if (index === -1) {
        return [...blocks, block]
    }

    const copy = [...blocks]
    copy.splice(index + 1, 0, block)

    return copy
}

/** المتغيّرات بأسمائها: «{first_name}» ← «[اسم العميل]». */
export const labelPlaceholders = (text, placeholders = []) => {
    const labels = Object.fromEntries(placeholders.map((item) => [item.value, item.label]))

    return String(text ?? '').replace(/\{(url:)?[\w\u0600-\u06FF]+\}/g, (token) => (labels[token] ? `[${labels[token]}]` : token))
}

/** سطر الملخّص تحت اسم العنصر في «ترتيب البريد». */
export const blockSummary = (block, placeholders = [], t = (key) => key) => {
    const props = block?.props ?? {}
    let text = ''

    switch (block?.type) {
        case 'header':
        case 'heading':
        case 'text':
        case 'image_text':
        case 'footer':
            text = props.text
            break
        case 'button':
            text = props.label
            break
        case 'image':
            text = props.alt || (props.src ? String(props.src).split('/').pop() : t('No image chosen'))
            break
        case 'spacer':
            text = `${props.height ?? 0}px`
            break
        case 'social':
            text = (props.links ?? []).map((link) => t(NETWORK_LABELS[link.network] ?? link.network)).join('، ')
            break
        default:
            text = ''
    }

    const line = labelPlaceholders(text, placeholders).replace(/\s+/g, ' ').trim()

    return line.length > 60 ? `${line.slice(0, 60)}…` : line
}

/** start/end حسب اتجاه الرسالة — كما يكتبها الخادم لبرامج البريد. */
export const alignToCss = (align, direction = 'rtl') => {
    if (align === 'center') return 'center'
    if (align === 'end') return direction === 'rtl' ? 'left' : 'right'

    return direction === 'rtl' ? 'right' : 'left'
}

export const fontStack = (font) => (FONTS.find((item) => item.value === font) ?? FONTS[0]).stack

/** هل في التصميم ما يُرسل؟ (الخادم يرفض الرسالة الفارغة.) */
export const hasContent = (design) => (design?.blocks ?? []).some((block) => {
    const props = block.props ?? {}

    return [props.text, props.label, props.src, props.logo].some((value) => String(value ?? '').trim() !== '')
        || (props.links ?? []).some((link) => String(link.url ?? '').trim() !== '')
})

/** أخطاء الخادم لعنصر بعينه: design.blocks.{index}.props.{name} ← {name: message}. */
export const blockErrors = (errors = {}, index) => {
    const prefix = `design.blocks.${index}.`
    const found = {}

    for (const [key, message] of Object.entries(errors ?? {})) {
        if (key.startsWith(prefix)) {
            found[key.slice(prefix.length).replace(/^props\./, '')] = Array.isArray(message) ? message[0] : message
        }
    }

    return found
}
