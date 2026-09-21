/**
 * المحادثات التي تصل أثناء بحثٍ نشط.
 *
 * العطل: قائمة المحادثات تُدرج كل جهة اتصال تصل منها رسالة جديدة، ولا تعرف
 * أن هناك بحثاً نشطاً. فالموظّفة تبحث عن رقم، تُصفّى القائمة إلى محادثة
 * واحدة، ثم تصل رسالة من عميل آخر فتظهر داخل نتائج بحثها — فتظنّ أن للرقم
 * محادثتين، أو أن البحث لا يعمل.
 *
 * ولا نُسقط الرسالة صامتين: تُحجَز ويُعرض شريط يقول إنها وصلت، فلا تفوت ولا
 * تُفسد النتيجة.
 */

/**
 * نصّ البحث النشط من عنوان الصفحة، أو '' إن لم يكن هناك بحث.
 *
 * العنوان هو المصدر: البحث يتمّ بزيارة Inertia تضع `search` في العنوان،
 * فيبقى صحيحاً بعد إعادة التحميل وعند فتح رابط محفوظ.
 *
 * @param {string} locationSearch  مثل '?search=0556149709&status=open'
 * @returns {string}
 */
export function activeChatSearch(locationSearch) {
    if (typeof locationSearch !== 'string' || locationSearch === '') {
        return ''
    }

    try {
        return (new URLSearchParams(locationSearch).get('search') || '').trim()
    } catch (error) {
        return ''
    }
}

/**
 * هل تُحجَز المحادثة الواصلة بدل إدراجها في القائمة؟
 *
 * @param {string} locationSearch
 * @returns {boolean}
 */
export function shouldHoldArrival(locationSearch) {
    return activeChatSearch(locationSearch) !== ''
}

/**
 * إضافة جهة اتصال إلى المحجوزات بلا تكرار.
 *
 * العدد عدد المحادثات لا الرسائل: عميل أرسل خمس رسائل محادثةٌ واحدة، وعدّها
 * خمساً يُفزع الموظّفة بلا سبب.
 *
 * @param {Array<number|string>} held
 * @param {number|string|null|undefined} contactId
 * @returns {Array<number|string>}
 */
export function holdArrival(held, contactId) {
    const current = Array.isArray(held) ? held : []

    if (contactId === null || contactId === undefined || contactId === '') {
        return current
    }

    return current.some((id) => String(id) === String(contactId))
        ? current
        : [...current, contactId]
}

/**
 * عنوان فتح محادثة مع الإبقاء على المرشِّحات النشطة.
 *
 * العطل: فتح المحادثة كان ينتقل إلى `/chats/{uuid}` مجرّداً، فيسقط `search`
 * من العنوان بينما يبقى نصّه في صندوق البحث. فأيّ جلبٍ لاحق للقائمة يعود
 * بها كاملة غير مصفّاة، والموظّفة ترى عشرات المحادثات وبحثها ما زال مكتوباً
 * أمامها — فتظنّ أن البحث أتى بها.
 *
 * والاتجاه المعاكس كان مضبوطاً أصلاً: البحث يُبقي المحادثة المفتوحة. فهذا
 * يُكمل الزوج.
 *
 * @param {string} uuid           معرّف جهة الاتصال
 * @param {string} locationSearch العنوان الحالي، مثل '?search=055&status=open'
 * @returns {string}
 */
export function chatUrlWithFilters(uuid, locationSearch) {
    const base = '/chats/' + uuid

    if (typeof locationSearch !== 'string' || locationSearch === '') {
        return base
    }

    let params

    try {
        params = new URLSearchParams(locationSearch)
    } catch (error) {
        return base
    }

    // صفحة القائمة لا معنى لها في عنوان محادثة بعينها.
    params.delete('contact_page')

    const query = params.toString()

    return query ? base + '?' + query : base
}
