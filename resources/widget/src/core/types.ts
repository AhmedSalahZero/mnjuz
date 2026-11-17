/** أشكال ما يردّه الخادم (WebChatMessagePresenter و WidgetController). */

/** حقل في نموذج الزائر كما ضبطه التاجر. */
export type FieldMode = 'off' | 'optional' | 'required'

/** سؤال بداية كما يرسله الخادم (WebChatQuestions::publicView). */
export interface IntakeQuestion {
    id: string
    target: 'name' | 'email' | 'phone' | 'field'
    validation: 'text' | 'number' | 'email' | 'phone' | 'date' | 'select' | 'saudi_id'
    required: boolean
    label_ar: string | null
    label_en: string | null
    options?: string[]
}

export interface WidgetMedia {
    url: string | null
    name: string | null
    mime: string | null
    size: number | null
    failed?: boolean
}

export interface InteractiveHeader {
    type: 'text' | 'image' | 'video' | 'document'
    text?: string
    url?: string
}

export interface InteractiveRow {
    id: string
    title: string
    description?: string
}

export interface Interactive {
    kind: 'buttons' | 'list' | 'cta_url'
    buttons?: { id: string; title: string }[]
    button?: string
    sections?: { title: string; rows: InteractiveRow[] }[]
    display_text?: string
    url?: string
    header?: InteractiveHeader
    footer?: string
}

export interface WidgetMessage {
    id: string | null
    seq: number | null
    client_id: string | null
    direction: 'in' | 'out'
    type: string
    text: string | null
    media: WidgetMedia | null
    interactive: Interactive | null
    status: string
    created_at: string | null
    sender: { kind: 'visitor' | 'agent' | 'bot'; name?: string }
    /** فقاعة لم يؤكّدها الخادم بعد. */
    local?: 'pending' | 'failed'
    /** ملف محلّي يُعرض ريثما يُرفع. */
    preview?: string | null
}

export interface RealtimeConfig {
    provider: string
    key: string
    cluster: string | null
    host: string | null
    port: number | null
    scheme: string | null
    force_tls: boolean
}

export interface WidgetConfig {
    widget: {
        name: string
        appearance: {
            color: string
            position: 'right' | 'left'
            offset_x: number
            offset_y: number
            logo_url: string | null
            title: string
            subtitle: string
            welcome_message: string
            /** النسخ الإنجليزية اختيارية: الفارغة تعني العربية (pick). غائبة عند خادم أقدم. */
            title_en?: string
            subtitle_en?: string
            welcome_message_en?: string
            language: 'auto' | 'ar' | 'en'
        }
        prechat: {
            default_country: string
            /** غائب عند خادم أقدم من الإعداد: يُعامل إجبارياً (phoneMode). */
            phone?: FieldMode
            email: FieldMode
            name_label: string | null
            phone_label: string | null
            consent_text: string | null
            consent_text_en?: string | null
            /**
             * رسالة بعد أسئلة البداية حين لا قائمة أسئلة شائعة («سنحوّل
             * محادثتك…»). فارغة أو غائبة (خادم أقدم): لا شيء.
             */
            handoff_message?: string | null
            handoff_message_en?: string | null
            /** أسئلة البداية الفعلية. غائبة عند خادم أقدم: تُشتقّ من المفاتيح أعلاه. */
            questions?: IntakeQuestion[]
        }
        behavior: { show_agent_names: boolean; sound: boolean; uploads: boolean }
    }
    available: boolean
    open_now: boolean
    /** عدد الأسئلة الشائعة المفعّلة. غائب عند خادم أقدم: لا قائمة. */
    faqs?: number
    realtime: RealtimeConfig | null
    bundle: string | null
    limits: { text: number; media: Record<string, number>; accept: string[]; files_per_send?: number }
}

export interface SessionInfo {
    visitor: { id: string; name: string }
    channel: string | null
    unread_count: number
    last_message: WidgetMessage | null
}
