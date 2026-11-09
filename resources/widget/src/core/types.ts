/** أشكال ما يردّه الخادم (WebChatMessagePresenter و WidgetController). */

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
            language: 'auto' | 'ar' | 'en'
        }
        prechat: {
            default_country: string
            email: 'off' | 'optional' | 'required'
            name_label: string | null
            phone_label: string | null
            consent_text: string | null
        }
        behavior: { show_agent_names: boolean; sound: boolean; uploads: boolean }
    }
    available: boolean
    open_now: boolean
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
