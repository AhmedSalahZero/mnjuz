/**
 * نبضة حضور التبويب: «أنا مفتوح» كل نصف دقيقة، و«غادرتُ» عند إغلاقه.
 *
 * كانت النبضة في الـ layout نفسه، والـ layout يُركَّب من جديد مع كل تنقّل في
 * Inertia، ولا شيء يُعلن الإغلاق — فيبقى الموظف «متصلاً» خمس دقائق بعد أن
 * يغادر. هنا مؤقّت واحد للتبويب كلّه مهما تنقّل، وإشارة مغادرة صريحة.
 *
 * معرّف التبويب جديد مع كل تحميل للصفحة (لا تخزين): إعادة التحميل تُغادر
 * بالقديم وتنبض بالجديد في اللحظة نفسها، والخادم يتريّث قبل إعلان المغادرة —
 * فلا وميض. ونسخ التبويب لا ينسخ معرّفه، فإغلاق أحدهما لا يُسقط الآخر.
 */
import axios from 'axios'

export const HEARTBEAT_MS = 30000

const HEARTBEAT_URL = '/performance/heartbeat'
const OFFLINE_URL = '/performance/offline'

const makeId = () => {
    try {
        if (globalThis.crypto?.randomUUID) return globalThis.crypto.randomUUID().replace(/-/g, '')
    } catch (e) {
        // سياق غير آمن (http): نكمل بالبديل.
    }
    return Date.now().toString(36) + Math.random().toString(36).slice(2, 12)
}

export const clientId = makeId()

/** @type {{organizationId: number, timer: ReturnType<typeof setInterval>|null, warned: boolean} | null} */
let state = null
let listenersBound = false

const payload = () => ({
    client_id: clientId,
    organization_id: state?.organizationId,
    visible: document.visibilityState === 'visible',
})

const beat = () => {
    if (!state) return

    axios.post(HEARTBEAT_URL, payload())
        .then(() => {
            if (state) state.warned = false
        })
        .catch((error) => {
            const status = error?.response?.status

            // الجلسة انتهت أو لم يعد عضواً: لا فائدة من الاستمرار.
            if (status === 401 || status === 403 || status === 419) {
                stopAgentHeartbeat({ announce: false })
                return
            }

            // كانت الأخطاء تُبتلع، فينقطع الحضور ولا يدري أحد لماذا.
            if (state && !state.warned) {
                state.warned = true
                console.warn('[Presence] تعذّرت نبضة الحضور', status ?? error?.message)
            }
        })
}

const xsrfToken = () => {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/)
    return match ? decodeURIComponent(match[1]) : null
}

/**
 * «غادرتُ» ولو كانت الصفحة تُغلق: fetch مع keepalive يكمل بعد الإغلاق ويقبل
 * ترويسة CSRF (sendBeacon لا يقبلها، ورمز الـ meta قد يكون قديماً بعد الدخول).
 */
const announceOffline = (organizationId) => {
    if (!organizationId) return

    const body = JSON.stringify({ client_id: clientId, organization_id: organizationId })
    const token = xsrfToken()

    try {
        fetch(OFFLINE_URL, {
            method: 'POST',
            keepalive: true,
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                ...(token ? { 'X-XSRF-TOKEN': token } : {}),
            },
            body,
        }).catch(() => { })
    } catch (e) {
        // متصفّح بلا keepalive: يكفي أن تنقضي النافذة.
    }
}

const onVisibilityChange = () => beat()
const onPageShow = (event) => {
    // عاد من ذاكرة الرجوع (bfcache) بعد أن أعلن مغادرته عند pagehide.
    if (event.persisted) beat()
}
const onPageHide = () => {
    if (state) announceOffline(state.organizationId)
}

const bindListeners = () => {
    if (listenersBound) return
    listenersBound = true

    document.addEventListener('visibilitychange', onVisibilityChange)
    // تبويب جمّده المتصفّح ثم أيقظه، أو شبكة عادت: نبضة فورية لا بعد نصف دقيقة.
    document.addEventListener('resume', beat)
    window.addEventListener('online', beat)
    window.addEventListener('pageshow', onPageShow)
    window.addEventListener('pagehide', onPageHide)
}

const unbindListeners = () => {
    if (!listenersBound) return
    listenersBound = false

    document.removeEventListener('visibilitychange', onVisibilityChange)
    document.removeEventListener('resume', beat)
    window.removeEventListener('online', beat)
    window.removeEventListener('pageshow', onPageShow)
    window.removeEventListener('pagehide', onPageHide)
}

/**
 * يبدأ النبضة لمنظمة التبويب. استدعاؤه ثانيةً بالمنظمة نفسها لا يفعل شيئاً
 * (تركيب الـ layout مع كل تنقّل)، وبمنظمة أخرى يُغادر الأولى وينبض للثانية.
 */
export function startAgentHeartbeat(organizationId) {
    organizationId = Number(organizationId)
    if (!organizationId) return

    if (state?.organizationId === organizationId) return

    if (state) stopAgentHeartbeat()

    state = { organizationId, timer: setInterval(beat, HEARTBEAT_MS), warned: false }
    bindListeners()
    beat()
}

export function stopAgentHeartbeat({ announce = true } = {}) {
    if (!state) return

    if (state.timer) clearInterval(state.timer)
    if (announce) announceOffline(state.organizationId)
    state = null
    unbindListeners()
}
