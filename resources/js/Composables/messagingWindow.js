/**
 * نافذة الأربع والعشرين ساعة في واتساب.
 *
 * بعد آخر رسالة واردة من العميل تُغلَق الردود الحرّة بعد 24 ساعة، فيُعرض
 * للموظّف الوقتُ المتبقّي. الحساب هنا دالّة نقيّة عمداً كي يُختبر بلا واجهة،
 * ولأن ChatForm (يقرّر هل يُسمح بالإرسال) وعدّاد الترويسة (يعرض المتبقّي)
 * يجب أن يتّفقا دائماً — فمصدرهما واحد.
 */

export const WINDOW_MS = 24 * 60 * 60 * 1000
const WARNING_MS = 6 * 60 * 60 * 1000
const CRITICAL_MS = 60 * 60 * 1000

/**
 * الخادم يُرجع أحياناً «2026-01-01 10:00:00» بلا منطقة زمنية، وهو UTC —
 * وبلا تطبيعٍ يفسّره المتصفّح بتوقيت الجهاز فيُزاح العدّاد ساعاتٍ.
 */
export const parseUtcDate = (value) => {
    if (!value) return null
    if (value instanceof Date) return value
    const normalized = typeof value === 'string' && !value.endsWith('Z') && !value.includes('+')
        ? value.replace(' ', 'T') + 'Z'
        : value
    const parsed = new Date(normalized)

    return Number.isNaN(parsed.getTime()) ? null : parsed
}

/** آخر رسالة واردة من العميل كما تصل في بيانات المحادثة. */
export const lastInboundOf = (contact) =>
    contact?.last_inbound_chat_created_at_iso
    ?? contact?.last_inbound_chat?.created_at_iso
    ?? contact?.last_inbound_chat?.created_at
    ?? null

/**
 * الوقت المتبقّي من النافذة.
 *
 * @param {string|Date|null} lastInbound وقت آخر رسالة واردة (UTC)
 * @param {number} nowMs اللحظة الحالية — تُمرَّر كي يبقى الحساب قابلاً للاختبار
 * @returns {{open: boolean, remainingMs: number, hours: number, minutes: number, fraction: number, level: 'ok'|'warning'|'critical'|'closed'}}
 */
export function windowRemaining(lastInbound, nowMs = Date.now()) {
    const closed = { open: false, remainingMs: 0, hours: 0, minutes: 0, fraction: 0, level: 'closed' }
    const last = parseUtcDate(lastInbound)

    if (!last) return closed

    // ساعة جهاز متقدّمة عن الخادم تجعل الرسالة «في المستقبل» فيزيد المتبقّي
    // على 24 ساعة؛ لا نعرض أكثر من النافذة كاملة.
    const elapsed = Math.max(0, nowMs - last.getTime())
    const remainingMs = Math.min(WINDOW_MS, WINDOW_MS - elapsed)

    if (remainingMs <= 0) return closed

    // ceil: النافذة المفتوحة لا تعرض «0 دقيقة» أبداً، فهي مفتوحة فعلاً.
    const totalMinutes = Math.ceil(remainingMs / 60000)

    return {
        open: true,
        remainingMs,
        hours: Math.floor(totalMinutes / 60),
        minutes: totalMinutes % 60,
        fraction: remainingMs / WINDOW_MS,
        level: remainingMs <= CRITICAL_MS ? 'critical' : remainingMs <= WARNING_MS ? 'warning' : 'ok',
    }
}

/**
 * هل النافذة مفتوحة؟ يُفضَّل وقت آخر رسالة واردة، وإلا فالعلم القادم من الخادم.
 */
export function isWindowOpen(contact, nowMs = Date.now()) {
    const last = lastInboundOf(contact)

    if (last && parseUtcDate(last)) {
        return windowRemaining(last, nowMs).open
    }

    if (typeof contact?.is_messaging_window_open === 'boolean') {
        return contact.is_messaging_window_open
    }

    return false
}
