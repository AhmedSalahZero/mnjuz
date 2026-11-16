/**
 * لغة الـ widget: العربية والإنجليزية، والزائر يبدّل بينهما من رأس النافذة.
 *
 * صغير عمداً: يستورده الـ loader (الذي يُحمَّل في كل صفحة من موقع التاجر)
 * ليُعنون زرّه بلغة الزائر قبل تحميل الواجهة.
 */
export type Lang = 'ar' | 'en'

/** مفتاح اختيار الزائر في التخزين؛ يبقى بعد مسح الجلسة (storage.clear). */
export const LANG_KEY = 'lang'

/**
 * اختيار الزائر أوّلاً، ثم إعداد التاجر (ar/en)، ثم — لـ auto — لغة الصفحة
 * أو المتصفّح.
 */
export function resolveLanguage(choice: string | null | undefined, setting: string, documentLang: string, browserLang: string): Lang {
    if (choice === 'ar' || choice === 'en') {
        return choice
    }

    if (setting === 'ar' || setting === 'en') {
        return setting
    }

    const lang = (documentLang || browserLang || '').toLowerCase()

    return lang.startsWith('ar') ? 'ar' : 'en'
}

/** نصّ كتبه التاجر بلغتين: الإنجليزي لزائرٍ بالإنجليزية إن وُجد، وإلّا العربي. */
export function pick(lang: Lang, ar: string | null | undefined, en: string | null | undefined): string {
    const arabic = (ar ?? '').trim()
    const english = (en ?? '').trim()

    if (lang === 'en' && english !== '') {
        return english
    }

    return arabic !== '' ? arabic : english
}

/** ?lang= على عنوان الطلب: الخادم يردّ برسائل التحقّق بلغة الزائر. */
export function withLang(url: string, lang: Lang): string {
    return `${url}${url.includes('?') ? '&' : '?'}lang=${lang}`
}
