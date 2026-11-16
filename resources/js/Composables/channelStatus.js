/**
 * سطر الحالة في بطاقات صفحة القنوات.
 *
 * منطق خالص بلا Vue ليُختبر في node: البطاقة تأتي من
 * ChannelAccountController::channelCards كما هي.
 *
 * tone: success | warning | muted — لون النقطة بجانب الحالة.
 */

/** حالة قناة تُربط من الإعدادات (تيليجرام، ماسنجر، إنستغرام، تيك توك). */
export const channelCardStatus = (card) => {
    const account = card?.account ?? null

    if (account) {
        // الحساب المربوط الذي انتهت صلاحيته أو عُطّل: يحتاج تدخّلاً.
        if (account.needs_reauthorization || !account.is_active) {
            return { key: 'Needs attention', tone: 'warning', detail: account.last_error || null }
        }

        const detail = account.username ? `@${account.username}` : (account.name || null)

        return { key: 'Connected', tone: 'success', detail }
    }

    // منصّة تُربط بالتفويض ولم يُضبط تطبيقها على الخادم: زرّ الربط لن يعمل.
    if (card?.uses_oauth && !card?.platform_ready) {
        return { key: 'Not set up on the server', tone: 'muted', detail: null }
    }

    return { key: 'Not connected', tone: 'muted', detail: null }
}

/** حالة المحادثة المباشرة: مفعّلة، موقوفة، أو لم تُنشأ بعد. */
export const webChatStatus = (summary) => {
    if (!summary?.exists) {
        return { key: 'Not set up yet', tone: 'muted', detail: null }
    }

    return summary.enabled
        ? { key: 'Enabled', tone: 'success', detail: null }
        : { key: 'Disabled', tone: 'muted', detail: null }
}

/** حالة واتساب: مربوط أو لا — بياناتها في المنشأة لا في channel_accounts. */
export const whatsappStatus = (connected) => (connected
    ? { key: 'Connected', tone: 'success', detail: null }
    : { key: 'Not connected', tone: 'muted', detail: null })

/** حالة بريد الحملات (SMTP التاجر). */
export const emailStatus = (summary) => {
    if (summary?.connected) {
        return { key: 'Connected', tone: 'success', detail: summary.from_address || null }
    }

    if (summary?.needs_attention) {
        return { key: 'Needs attention', tone: 'warning', detail: summary.from_address || null }
    }

    return { key: 'Not connected', tone: 'muted', detail: null }
}

/** لون النقطة لكل نبرة. */
export const toneDotClass = (tone) => ({
    success: 'bg-green-500',
    warning: 'bg-amber-400',
    muted: 'bg-slate-300',
}[tone] ?? 'bg-slate-300')
