/**
 * تحديثات تصل عن جهة الاتصال المفتوحة دون أن يضغط أحد شيئاً.
 *
 * الخادم يغيّر مساعد الذكاء الاصطناعي بنفسه (كلمة بدء أو إيقاف من العميل، أو
 * تفعيل تلقائي)، ويبثّ ContactAiAssistanceUpdated. ما يصل لمحادثةٍ غير المفتوحة
 * يُهمَل: الصفحة لا تعرض غير حالة المحادثة المفتوحة.
 *
 * دالّة نقيّة بلا واجهة ليُختبر قرار «هل ينطبق هذا الحدث على ما أمامي؟».
 */

/**
 * @param {?{id?: number|string, uuid?: string, ai_assistance_enabled?: boolean}} contact المحادثة المفتوحة (تُعدَّل في مكانها)
 * @param {?{contact_id?: number|string, contact_uuid?: string, ai_assistance_enabled?: boolean}} event حمولة البثّ
 * @returns {boolean} هل طُبّق التحديث
 */
export function applyAiAssistanceUpdate(contact, event) {
    if (!contact || !event || typeof event.ai_assistance_enabled !== 'boolean') return false

    // المعرّف الرقمي أولاً، وإلا الـ uuid: أحدهما قد يغيب عن حمولةٍ قديمة.
    const sameById = event.contact_id != null && contact.id != null && Number(contact.id) === Number(event.contact_id)
    const sameByUuid = !!event.contact_uuid && !!contact.uuid && contact.uuid === event.contact_uuid

    if (!sameById && !sameByUuid) return false

    contact.ai_assistance_enabled = event.ai_assistance_enabled

    return true
}
