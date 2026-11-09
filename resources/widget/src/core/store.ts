import type { WidgetMessage } from './types.ts'

/**
 * دمج الرسائل في قائمة الـ widget.
 *
 * الرسائل تصل من ثلاثة طرق — البثّ، والمزامنة، والفقاعة المتفائلة — وقد
 * تصل بغير ترتيبها (عمّال طابور متوازون). القاعدة:
 *  - الصفّ المحفوظ يُعرف بمعرّفه (id) ويُرتَّب بـ seq، لا بترتيب الوصول.
 *  - الفقاعة المتفائلة تُعرف بـ client_id، وتختفي حين يصل صفّها المحفوظ.
 *  - ما لم يؤكَّد بعد يبقى في الآخر بترتيب كتابته.
 */
export function mergeMessages(current: WidgetMessage[], incoming: WidgetMessage[]): WidgetMessage[] {
    const saved = new Map<string, WidgetMessage>()
    const pending = new Map<string, WidgetMessage>()

    for (const message of current) {
        if (message.id) {
            saved.set(message.id, message)
        } else if (message.client_id) {
            pending.set(message.client_id, message)
        }
    }

    for (const message of incoming) {
        if (!message || !message.id) {
            continue
        }

        if (message.client_id) {
            pending.delete(message.client_id)
        }

        saved.set(message.id, message)
    }

    const ordered = Array.from(saved.values()).sort((a, b) => (a.seq ?? 0) - (b.seq ?? 0))

    return ordered.concat(Array.from(pending.values()))
}

/** فقاعة متفائلة: تظهر فور الإرسال وتُستبدل بصفّها حين يصل. */
export function addPending(current: WidgetMessage[], message: WidgetMessage): WidgetMessage[] {
    return current.concat([{ ...message, local: 'pending' }])
}

export function markFailed(current: WidgetMessage[], clientId: string): WidgetMessage[] {
    return current.map((message) => (!message.id && message.client_id === clientId ? { ...message, local: 'failed' } : message))
}

export function removePending(current: WidgetMessage[], clientId: string): WidgetMessage[] {
    return current.filter((message) => message.id || message.client_id !== clientId)
}

/** آخر صفّ محفوظ — منه تبدأ المزامنة (?after=). */
export function lastSeq(messages: WidgetMessage[]): number {
    return messages.reduce((max, message) => Math.max(max, message.seq ?? 0), 0)
}

/** أقدم صفّ محفوظ — منه يبدأ تحميل ما قبله (?before=). */
export function firstSeq(messages: WidgetMessage[]): number | null {
    const seqs = messages.map((m) => m.seq).filter((seq): seq is number => typeof seq === 'number')

    return seqs.length ? Math.min(...seqs) : null
}

/** ردود لم يرها الزائر: الصادر وحده (رسائله هو مقروءة بالطبع). */
export function unreadCount(messages: WidgetMessage[], lastReadSeq: number): number {
    return messages.filter((m) => m.direction === 'out' && (m.seq ?? 0) > lastReadSeq).length
}

/** آخر ردّ صادر — علامة القراءة تُرسَل حتى هنا. */
export function lastOutboundSeq(messages: WidgetMessage[]): number {
    return messages.reduce((max, m) => (m.direction === 'out' ? Math.max(max, m.seq ?? 0) : max), 0)
}
