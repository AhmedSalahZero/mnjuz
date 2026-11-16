import type { Faq, JourneyContext } from './faq.ts'
import { withLang, type Lang } from './lang.ts'
import type { SessionInfo, WidgetConfig, WidgetMessage } from './types.ts'

/**
 * نداءات الواجهة العامّة (routes/webchat.php). بلا كوكي ولا جلسة: الـ widget
 * يُعرَّف بمفتاحه في المسار، والزائر بتوكنه في ترويسة X-Webchat-Token.
 */
export class ApiError extends Error {
    readonly status: number
    readonly code: string | null
    readonly errors: Record<string, string[]>

    // بلا خصائص في المُنشئ: Node يُشغّل الملف في اختبارات tests/js بحذف الأنواع وحده.
    constructor(message: string, status: number, code: string | null, errors: Record<string, string[]> = {}) {
        super(message)
        this.status = status
        this.code = code
        this.errors = errors
    }
}

export function apiRoot(base: string, widgetKey: string): string {
    return `${base.replace(/\/+$/, '')}/api/webchat/v1/w/${encodeURIComponent(widgetKey)}`
}

/**
 * getLang: لغة الـ widget الآن. تُضاف ?lang= إلى كل طلب يغيّر شيئاً فيعود
 * خطأ التحقّق («رقم جوال غير صحيح») بلغة الزائر. القراءة (GET) بلا لغة:
 * /config يُخبَّأ في المتصفّح، ولا يحمل رسائل.
 */
export function createApi(base: string, widgetKey: string, getToken: () => string | null, preview = false, getLang?: () => Lang) {
    const root = apiRoot(base, widgetKey)

    async function request<T>(method: string, path: string, body?: unknown, form = false): Promise<T> {
        const headers: Record<string, string> = { Accept: 'application/json' }
        const token = getToken()

        if (token) {
            headers['X-Webchat-Token'] = token
        }

        // معاينة صفحة الإعدادات: الـ widget المعطَّل يعمل فيها وحدها.
        if (preview) {
            headers['X-Webchat-Preview'] = '1'
        }

        if (body !== undefined && !form) {
            headers['Content-Type'] = 'application/json'
        }

        let response: Response
        const url = method !== 'GET' && getLang ? withLang(`${root}/${path}`, getLang()) : `${root}/${path}`

        try {
            response = await fetch(url, {
                method,
                headers,
                body: body === undefined ? undefined : form ? (body as FormData) : JSON.stringify(body),
                credentials: 'omit',
                mode: 'cors',
            })
        } catch {
            throw new ApiError('network', 0, 'network')
        }

        let payload: any = null

        try {
            payload = await response.json()
        } catch {
            payload = null
        }

        if (!response.ok) {
            throw new ApiError(
                payload?.message || payload?.error || `HTTP ${response.status}`,
                response.status,
                payload?.error ?? null,
                payload?.errors ?? {},
            )
        }

        return payload as T
    }

    return {
        authEndpoint: `${root}/broadcasting/auth`,

        config: () => request<WidgetConfig>('GET', preview ? 'config?preview=1' : 'config'),

        faqs: () => request<{ faqs: Faq[] }>('GET', preview ? 'faqs?preview=1' : 'faqs'),

        startSession: (body: Record<string, unknown>) =>
            request<{ token: string; visitor: { id: string; name: string }; channel: string | null }>('POST', 'sessions', body),

        session: () => request<SessionInfo>('GET', 'session'),

        messages: (params: { after?: number; before?: number; limit?: number } = {}) => {
            const query = Object.entries(params)
                .filter(([, value]) => value !== undefined && value !== null)
                .map(([key, value]) => `${key}=${encodeURIComponent(String(value))}`)
                .join('&')

            return request<{ messages: WidgetMessage[]; has_more: boolean }>('GET', query ? `messages?${query}` : 'messages')
        },

        // context: ما فعله الزائر قبل أوّل رسالة (core/faq.ts)، مع أوّلها وحدها.
        sendText: (clientId: string, text: string, pageUrl: string, context: JourneyContext | null = null) =>
            request('POST', 'messages', { client_message_id: clientId, type: 'text', text, page_url: pageUrl, ...(context ? { context } : {}) }),

        sendChoice: (clientId: string, messageId: string, optionId: string, pageUrl: string, context: JourneyContext | null = null) =>
            request('POST', 'messages', {
                client_message_id: clientId,
                type: 'interactive',
                reply: { message_id: messageId, option_id: optionId },
                page_url: pageUrl,
                ...(context ? { context } : {}),
            }),

        upload: (clientId: string, file: File, caption: string, context: JourneyContext | null = null) => {
            const form = new FormData()
            form.append('client_message_id', clientId)
            form.append('file', file)

            if (caption) {
                form.append('caption', caption)
            }

            // الحقول نفسها بصيغة النماذج: context[menu] و context[faqs][]…
            if (context) {
                if (context.menu) form.append('context[menu]', context.menu)
                context.faqs.forEach((id) => form.append('context[faqs][]', String(id)))
                form.append('context[not_found]', context.not_found ? '1' : '0')
            }

            return request('POST', 'uploads', form, true)
        },

        read: (seq: number) => request<{ unread_count: number }>('POST', 'read', { seq }),
    }
}

export type WidgetApi = ReturnType<typeof createApi>
