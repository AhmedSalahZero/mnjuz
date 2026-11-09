/**
 * دمج حالة الاتصال الآنية في جدول الأداء.
 *
 * صفحة الأداء تحمل المقاييس كلّها مرة واحدة، ثم تصلها الحالة من مصدرين: حدث
 * لحظي عند كل تغيّر، واستعلام مصالحة دوري. الدمج دالّة نقيّة عمداً لأنه يقرّر
 * ما يُكتب فوق ما في الجدول:
 *  - «آخر نشاط» لا يرجع إلى الوراء — نقطة الحالة لا ترى إلا نافذة قصيرة، وقد
 *    يكون للجدول آخر نشاط أقدم منها وصحيح.
 *  - الحالة تُستبدل، إلا بما هو أقدم ممّا طُبّق (`at` بمللي ثانية الخادم): ردّ
 *    استعلامٍ قُرئ قبل حدثٍ ووصل بعده كان سيُرجع الموظف إلى حالته السابقة.
 */
import { parseUtcDate } from './messagingWindow.js'

const timeOf = (value) => parseUtcDate(value)?.getTime() ?? null

const stampOf = (value) => {
    if (value === null || value === undefined || value === '') return null
    const n = Number(value)
    return Number.isFinite(n) ? n : null
}

/**
 * @param {Array<{user_id: number, online: boolean, last_activity_at: ?string, at?: ?number}>} agents صفوف الجدول الحالية
 * @param {Array<{user_id: number, online: boolean, last_activity_at: ?string, at?: ?number}>} statuses حالة الاتصال الواردة
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

        const statusAt = stampOf(status.at)
        const agentAt = stampOf(agent.at)
        const outdated = statusAt !== null && agentAt !== null && statusAt < agentAt

        return {
            ...agent,
            online: outdated ? agent.online : !!status.online,
            at: outdated ? agent.at : (statusAt ?? agent.at ?? null),
            last_activity_at: newer ? status.last_activity_at : agent.last_activity_at,
        }
    })
}
