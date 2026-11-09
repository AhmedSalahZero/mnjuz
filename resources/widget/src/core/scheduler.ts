/**
 * متى نسأل الخادم، ومتى نفتح الاتصال اللحظي، ومن يسأل بين التابات.
 *
 * كل زائر بموقع مفتوح هو اتصال عند Pusher وطلبات عند الخادم، فالقواعد هنا
 * تقتصد: لا اتصال ولا مزامنة لتاب مخفي، والمزامنة لتاب واحد (القائد)،
 * والاتصال اللحظي يغني عن المزامنة متى كان قائماً.
 */
export const POLL_OPEN_MS = 4000
export const POLL_CLOSED_MS = 30000
export const LEADER_TTL_MS = 12000
export const REALTIME_IDLE_MS = 10 * 60 * 1000
export const POLL_IDLE_MS = 30 * 60 * 1000

export interface PollState {
    open: boolean
    visible: boolean
    realtimeConnected: boolean
    hasConversation: boolean
    leader: boolean
    idleMs: number
}

/** الفاصل بين مزامنتين، أو null = لا مزامنة الآن. */
export function pollInterval(state: PollState): number | null {
    if (!state.visible || !state.hasConversation || !state.leader) {
        return null
    }

    if (state.realtimeConnected) {
        return null
    }

    if (state.idleMs > POLL_IDLE_MS) {
        return null
    }

    return state.open ? POLL_OPEN_MS : POLL_CLOSED_MS
}

/** الاتصال اللحظي: والنافذة مفتوحة، أو التاب ظاهر ولدى الزائر محادثة. */
export function shouldConnectRealtime(state: { open: boolean; visible: boolean; hasConversation: boolean; idleMs: number }): boolean {
    if (!state.hasConversation || state.idleMs > REALTIME_IDLE_MS) {
        return false
    }

    return state.open || state.visible
}

export interface LeaderLock {
    id: string
    ts: number
}

/** التاب القائد: صاحب القفل، أو أي تاب إن خلا القفل أو انتهت صلاحيته. */
export function isLeader(lock: LeaderLock | null, me: string, now: number): boolean {
    return !lock || lock.id === me || now - lock.ts > LEADER_TTL_MS
}

export function parseLock(raw: string | null): LeaderLock | null {
    if (!raw) {
        return null
    }

    try {
        const value = JSON.parse(raw)

        return typeof value?.id === 'string' && typeof value?.ts === 'number' ? value : null
    } catch {
        return null
    }
}
