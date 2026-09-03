/**
 * ترجمة أخطاء واتساب إلى سبب يفهمه صاحب الحساب.
 *
 * العطل الذي أنتج هذا: حملة فشلت لرقم ونجحت لآخر، ولم يجد الموظّف في
 * الواجهة إلا كلمة «failed» — والسبب الحقيقي كان مكتوباً في سجلّ الحالة:
 * الكود 131049، أي أن واتساب أوقفت التسليم لهذا المستلِم وحده حفاظاً على
 * «صحّة التفاعل». بلا هذه الترجمة يبدو الخلل في نظامنا، ويُفتح بلاغ دعم
 * لا حيلة له فيه.
 *
 * الأكواد مرتّبة بترتيب تكرارها الفعلي في الإنتاج (آخر ثلاثة أشهر):
 *   131042 → 107 آلاف، 131049 → 52 ألفاً، 131026 → 17 ألفاً، 131048 → 10 آلاف.
 *
 * القيم مفاتيح ترجمة لا نصوصاً معروضة: الواجهة تمرّرها على $t.
 */
export const WHATSAPP_ERROR_EXPLANATIONS = {
    131042: 'WhatsApp stopped sending for this account because of a billing problem. Check the payment method on the Meta Business account.',
    131049: 'WhatsApp did not deliver this marketing message to keep the recipient from receiving too many. Try again later, or wait for the customer to message you first.',
    131026: 'The message could not be delivered: the number may not be on WhatsApp, or it cannot receive messages right now.',
    131048: 'WhatsApp limited sending because the account quality rating dropped. Reduce marketing sends and wait for the rating to recover.',
    130472: 'WhatsApp is running an experiment on this recipient and did not deliver marketing messages to them.',
    131053: 'WhatsApp rejected the attached file. Check the file format and size.',
    131050: 'The customer chose to stop receiving marketing messages from this account.',
    131031: 'The WhatsApp business account is restricted or locked. Check the account status on Meta Business.',
    131047: 'More than 24 hours passed since the customer last messaged, so only an approved template can be sent.',
    131000: 'WhatsApp could not send the message for an unspecified reason. Try again.',
    130403: 'WhatsApp temporarily blocked sending because of too many requests. Try again later.',
}

/** هل الكود معروف لدينا بشرحٍ عربي؟ */
export function hasWhatsappExplanation(code) {
    return Object.prototype.hasOwnProperty.call(WHATSAPP_ERROR_EXPLANATIONS, Number(code))
}

/**
 * مفتاح الشرح لكود واحد، أو '' إن كان مجهولاً — فتعرض الواجهة نصّ واتساب
 * كما هو بدل أن تكتم الخبر.
 *
 * @param {number|string|null|undefined} code
 * @returns {string}
 */
export function explainWhatsappErrorCode(code) {
    const numeric = Number(code)

    if (!Number.isFinite(numeric)) {
        return ''
    }

    return WHATSAPP_ERROR_EXPLANATIONS[numeric] ?? ''
}

/**
 * أوّل خطأ في حمولة سجلّ الحالة، أياً كان شكلها.
 *
 * الأشكال الواصلة: سجلّ حالة (errors[])، وردّ Graph API عند الرفض
 * (data.error)، وكلاهما قد يصل نصّاً أو كائناً.
 *
 * @param {string|object|null|undefined} payload
 * @returns {{code: number|null, title: string, message: string, details: string}|null}
 */
export function firstWhatsappError(payload) {
    let data = payload

    if (typeof data === 'string') {
        try {
            data = JSON.parse(data)
        } catch {
            return null
        }
    }

    if (!data || typeof data !== 'object') {
        return null
    }

    const candidate = Array.isArray(data.errors) && data.errors.length
        ? data.errors[0]
        : (data.data?.error ?? data.error ?? null)

    if (!candidate || typeof candidate !== 'object') {
        return null
    }

    const code = Number(candidate.code)

    return {
        code: Number.isFinite(code) ? code : null,
        title: candidate.title ?? candidate.error_user_title ?? '',
        message: candidate.message ?? candidate.error_user_msg ?? '',
        details: candidate.error_data?.details ?? '',
    }
}

/**
 * شرح جاهز لحمولة كاملة: مفتاح الترجمة إن عرفنا الكود، وإلا نصّ واتساب.
 *
 * @param {string|object|null|undefined} payload
 * @returns {{code: number|null, explanation: string, translatable: boolean, raw: string}|null}
 */
export function explainWhatsappError(payload) {
    const error = firstWhatsappError(payload)

    if (!error) {
        return null
    }

    const explanation = explainWhatsappErrorCode(error.code)
    const raw = [error.title, error.message, error.details]
        .filter((part) => typeof part === 'string' && part.trim() !== '')
        .filter((part, index, all) => all.indexOf(part) === index)
        .join(' — ')

    return {
        code: error.code,
        explanation: explanation !== '' ? explanation : raw,
        translatable: explanation !== '',
        raw,
    }
}
