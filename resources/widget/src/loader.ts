import { faCommentDots } from '@fortawesome/free-solid-svg-icons'
import { apiRoot } from './core/api.ts'
import { createStorage } from './core/storage.ts'

/**
 * ما يلصقه التاجر في موقعه:
 *   <script async src="https://app/widget/v1/loader.js" data-mnjz-key="wc_…"></script>
 *
 * صغير عمداً: يُحمَّل في كل صفحة من موقع التاجر. يرسم الزرّ العائم وشارة
 * غير المقروء فقط، ولا يُحمّل الواجهة الكاملة (Preact وpusher-js) إلا حين
 * يضغط الزائر — أو حين ينتظره ردٌّ لم يقرأه.
 */
interface LoaderConfig {
    available: boolean
    bundle: string | null
    widget: { appearance: { color: string; position: 'right' | 'left'; offset_x: number; offset_y: number; language: string } }
}

declare global {
    interface Window {
        MnjzWebchatApp?: { mount(ctx: Record<string, unknown>): void }
        __mnjzWebchat?: Record<string, boolean>
    }
}

const BADGE_POLL_MS = 30000
const BADGE_IDLE_MS = 30 * 60 * 1000

const LAUNCHER_CSS = `
:host{all:initial}
.l{position:fixed;bottom:var(--y);z-index:2147483000;width:56px;height:56px;border-radius:50%;border:0;cursor:pointer;
background:var(--c);color:#fff;display:flex;align-items:center;justify-content:center;box-shadow:0 6px 20px rgba(0,0,0,.2);
transition:transform .15s}
.l:hover{transform:scale(1.06)}.l:focus-visible{outline:3px solid #fff;outline-offset:2px}
.r{right:var(--x)}.f{left:var(--x)}
.b{position:absolute;top:-4px;inset-inline-end:-4px;min-width:20px;height:20px;padding:0 5px;border-radius:10px;
background:#ef4444;color:#fff;font:600 12px/20px system-ui,sans-serif;text-align:center;box-sizing:border-box}
`

// أيقونة Font Awesome (comment-dots) — نفسها التي يرسمها التطبيق بعد التحميل.
const ICON = `<svg width="26" height="26" viewBox="0 0 ${faCommentDots.icon[0]} ${faCommentDots.icon[1]}" fill="currentColor" aria-hidden="true"><path d="${faCommentDots.icon[4]}"/></svg>`

;(function () {
    const script = document.currentScript as HTMLScriptElement | null
    const key = script?.dataset.mnjzKey

    if (!script || !key) {
        return
    }

    // الكود لُصق مرّتين (القالب والإضافة معاً): نسخة واحدة تكفي.
    const loaded = (window.__mnjzWebchat ??= {})

    if (loaded[key]) {
        return
    }

    loaded[key] = true

    const base = new URL(script.src, location.href).origin
    const preview = script.dataset.mnjzPreview === '1'
    const root = apiRoot(base, key)
    const storage = createStorage(key)
    const startedAt = Date.now()

    const headers = (token?: string): Record<string, string> => {
        const h: Record<string, string> = { Accept: 'application/json' }

        if (token) h['X-Webchat-Token'] = token
        if (preview) h['X-Webchat-Preview'] = '1'

        return h
    }

    const getJson = (path: string, token?: string) =>
        fetch(`${root}/${path}`, { headers: headers(token), credentials: 'omit', mode: 'cors' }).then((response) => {
            if (response.status === 401) {
                storage.clear()
            }

            return response.ok ? response.json() : null
        })

    const start = () => {
        getJson(preview ? 'config?preview=1' : 'config')
            .then((config: LoaderConfig | null) => {
                if (!config) return

                // الاشتراك منتهٍ ولا محادثة قائمة: لا زرّ لما لا يعمل.
                if (!config.available && !storage.read().token) return

                launcher(config)
            })
            .catch(() => {
                // الـ widget لا يُفسد صفحة التاجر أبداً.
            })
    }

    const launcher = (config: LoaderConfig) => {
        const appearance = config.widget.appearance
        const host = document.createElement('div')
        host.id = `mnjz-webchat-${key}`
        document.body.appendChild(host)

        const shadow = host.attachShadow({ mode: 'open' })
        const style = document.createElement('style')
        style.textContent = LAUNCHER_CSS

        const button = document.createElement('button')
        button.type = 'button'
        button.className = `l ${appearance.position === 'left' ? 'f' : 'r'}`
        button.style.setProperty('--c', appearance.color)
        button.style.setProperty('--x', `${appearance.offset_x}px`)
        button.style.setProperty('--y', `${appearance.offset_y}px`)
        button.setAttribute('aria-label', (document.documentElement.lang || navigator.language).startsWith('ar') ? 'افتح المحادثة' : 'Open chat')
        button.innerHTML = ICON

        const badge = document.createElement('span')
        badge.className = 'b'
        badge.hidden = true
        button.appendChild(badge)

        shadow.appendChild(style)
        shadow.appendChild(button)

        let loading = false
        let wantOpen = false
        let timer: ReturnType<typeof setInterval> | null = null

        const mount = () => {
            if (timer) clearInterval(timer)

            window.MnjzWebchatApp?.mount({ root: shadow, base, key, config, preview, open: wantOpen })
        }

        const loadApp = (open: boolean) => {
            wantOpen = wantOpen || open

            if (window.MnjzWebchatApp) {
                mount()

                return
            }

            if (loading) return

            loading = true
            const app = document.createElement('script')
            app.async = true
            app.src = config.bundle || `${base}/widget/v1/app.js`
            app.onload = mount
            app.onerror = () => {
                loading = false
            }
            document.head.appendChild(app)
        }

        button.addEventListener('click', () => loadApp(true))

        // شارة غير المقروء لزائر عائد: ردٌّ ينتظره يُحمّل الواجهة فوراً.
        const refreshBadge = () => {
            const token = storage.read().token

            if (!token || document.hidden || Date.now() - startedAt > BADGE_IDLE_MS) return

            getJson('session', token)
                .then((session: { unread_count?: number } | null) => {
                    const unread = session?.unread_count ?? 0

                    if (unread > 0) {
                        badge.textContent = unread > 9 ? '9+' : String(unread)
                        badge.hidden = false
                        loadApp(false)
                    }
                })
                .catch(() => {})
        }

        if (storage.read().token) {
            refreshBadge()
            timer = setInterval(refreshBadge, BADGE_POLL_MS)
        }

        if (preview) {
            loadApp(true)
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start)
    } else {
        start()
    }
})()
