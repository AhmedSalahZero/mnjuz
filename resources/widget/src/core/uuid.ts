/**
 * معرّف الرسالة من جهة الزائر (client_message_id).
 *
 * crypto.randomUUID لا يوجد إلا في الصفحات الآمنة (https)، ومواقع تجار كثيرة
 * ما زالت على http. getRandomValues متاح في الحالتين، والخادم يشترط شكل
 * uuid (٣٦ حرفاً) ليطابق الفقاعة المتفائلة بالصفّ المحفوظ.
 */
export function uuidv4(): string {
    const cryptoApi = (globalThis as { crypto?: Crypto }).crypto

    if (cryptoApi && typeof cryptoApi.randomUUID === 'function') {
        try {
            return cryptoApi.randomUUID()
        } catch {
            // سياق غير آمن: نكمل بالطريقة اليدوية.
        }
    }

    const bytes = new Uint8Array(16)

    if (cryptoApi && typeof cryptoApi.getRandomValues === 'function') {
        cryptoApi.getRandomValues(bytes)
    } else {
        for (let i = 0; i < 16; i++) {
            bytes[i] = Math.floor(Math.random() * 256)
        }
    }

    bytes[6] = (bytes[6] & 0x0f) | 0x40
    bytes[8] = (bytes[8] & 0x3f) | 0x80

    const hex = Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('')

    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`
}
