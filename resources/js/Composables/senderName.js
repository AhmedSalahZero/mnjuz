/**
 * اسم الموظّف المُرسِل كما يُعرض في الفقاعة.
 *
 * ثلاثة مصادر تُغذّي الفقاعة نفسها بأشكال مختلفة:
 *   • تحميل الصفحة  → first_name و last_name و full_name (نموذج المستخدم كاملاً)
 *   • البثّ الحيّ    → full_name وحده
 *   • الفقاعة التفاؤلية → first_name و last_name
 *
 * وكانت الفقاعة تجمع first_name و last_name مباشرةً، فرسالةٌ وصلت عبر البثّ
 * تعرض «Sent By: undefined undefined» — الحقلان غير موجودين فيها أصلاً. ولا
 * يظهر العطل عند تحميل الصفحة، فيبدو عشوائياً: يُصيب الرسالة التي أرسلتَها
 * للتوّ ولا يُصيب ما قبلها.
 *
 * الاسم يُقرأ هنا من أي شكل، والغياب يُرجع نصّاً فارغاً لا كلمة «undefined».
 */
export function senderName(user) {
    if (!user || typeof user !== 'object') {
        return ''
    }

    const full = String(user.full_name ?? '').trim()
    if (full !== '') {
        return full
    }

    return [user.first_name, user.last_name]
        .map((part) => String(part ?? '').trim())
        .filter((part) => part !== '' && part !== 'undefined' && part !== 'null')
        .join(' ')
}
