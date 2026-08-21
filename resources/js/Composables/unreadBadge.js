/**
 * قرار عدّاد غير المقروءة عند وصول رسالة عبر البثّ.
 *
 * دالّة نقيّة عمداً: القرار كان موزّعاً بين مكوّنين — App.vue يزيد العدّاد،
 * وChat/Index.vue يعوّضه عبر inject. لكن App.vue يُستعمل داخل قالب صفحة الشات
 * (‎<AppLayout>‎)، أي أنه ابنٌ لا سلف، وinject لا يرى ما يوفّره ابن. فالتعويض
 * لم يقع قطّ: تصل الرسالة ومحادثتها مفتوحة، تُعلَّم مقروءة على الخادم، ويظلّ
 * العدّاد يعرض غير مقروءة لا وجود لها حتى إعادة تحميل الصفحة.
 *
 * القرار كلّه هنا الآن، ويعتمد على ما في الحمولة والمسار وحدهما.
 */

/** معرّف المحادثة المفتوحة من المسار: /chats/{uuid} */
export function openConversationUuid(pathname) {
    const match = String(pathname ?? '').match(/\/chats\/([^/?#]+)/);

    return match ? match[1] : null;
}

/**
 * هل تُحتسب هذه الرسالة غير مقروءة في العدّاد العام؟
 *
 * @param {Array<{contact_uuid?: string, value?: {type?: string, deleted_at?: any}}>} chat حمولة البثّ
 * @param {string} pathname مسار الصفحة الحالي
 */
export function shouldCountAsUnread(chat, pathname) {
    const entry = Array.isArray(chat) ? chat[0] : null;
    const value = entry?.value;

    // الصادر والمحذوف لا يُحتسبان، وحمولة بلا رسالة لا تُقرأ أصلاً.
    if (!value || value.type !== 'inbound' || value.deleted_at != null) {
        return false;
    }

    // محادثتها مفتوحة الآن ⇒ تُقرأ فور وصولها، فاحتسابها يُنشئ عدّاداً كاذباً.
    const open = openConversationUuid(pathname);

    return !(entry.contact_uuid && open && entry.contact_uuid === open);
}

/** هل نُشغّل صوت التنبيه؟ الوارد غير المحذوف حتى لو كانت محادثته مفتوحة. */
export function shouldPlaySound(chat) {
    const value = (Array.isArray(chat) ? chat[0] : null)?.value;

    return !!value && value.type === 'inbound' && value.deleted_at == null;
}
