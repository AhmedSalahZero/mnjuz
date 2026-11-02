/**
 * دمج حالة الاتصال الآنية في جدول الأداء.
 *
 * صفحة الأداء تحمل المقاييس كلّها مرة واحدة، وتسأل بعدها نقطة الحالة الخفيفة
 * كل بضع ثوانٍ. الدمج دالّة نقيّة عمداً لأنه يقرّر ما يُكتب فوق ما في الجدول:
 * الحالة تُستبدل دائماً، أمّا «آخر نشاط» فلا يرجع إلى الوراء — نقطة الحالة لا ترى
 * إلا نافذة قصيرة، وقد يكون للجدول آخر نشاط أقدم منها وصحيح.
 */
import { parseUtcDate } from './messagingWindow.js'

const timeOf = (value) => parseUtcDate(value)?.getTime() ?? null

/**
 * @param {Array<{user_id: number, online: boolean, last_activity_at: ?string}>} agents صفوف الجدول الحالية
 * @param {Array<{user_id: number, online: boolean, last_activity_at: ?string}>} statuses حالة الاتصال الواردة
 * @returns {Array} صفوف جديدة؛ المدخلات لا تُعدَّل
 */
export function applyPresence(agents, statuses) {
    if (!Array.isArray(agents)) return []
    if (!Array.isArray(statuses)) return agents

    const byUser = new Map(statuses.map((status) => [Number(status.user_id), status]))

    return agents.map((agent) => {
        const status = byUser.get(Number(agent.user_id))

        // موظف غائب عن الرد (أُزيل من الفريق للتوّ): يبقى كما هو حتى إعادة التحميل.
        if (!status) return agent

        const incoming = timeOf(status.last_activity_at)
        const current = timeOf(agent.last_activity_at)
        const newer = incoming !== null && (current === null || incoming > current)

        return {
            ...agent,
            online: !!status.online,
            last_activity_at: newer ? status.last_activity_at : agent.last_activity_at,
        }
    })
}
