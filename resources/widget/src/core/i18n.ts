/** نصوص الـ widget بالعربية والإنجليزية. اللغة من الإعداد، أو من الموقع نفسه. */
const ar = {
    open: 'افتح المحادثة',
    close: 'إغلاق',
    title: 'تحدّث معنا',
    subtitle: 'نردّ عادةً خلال دقائق',
    prechat_intro: 'عرّفنا بنفسك لنبدأ المحادثة.',
    name: 'الاسم',
    phone: 'رقم الجوال',
    email: 'البريد الإلكتروني',
    optional: 'اختياري',
    start: 'ابدأ المحادثة',
    starting: 'جارٍ البدء…',
    placeholder: 'اكتب رسالتك…',
    send: 'إرسال',
    attach: 'إرفاق ملف',
    emoji: 'الرموز التعبيرية',
    recent: 'المستخدمة مؤخراً',
    emoji_smileys: 'الوجوه',
    emoji_gestures: 'الإيماءات',
    emoji_hearts: 'القلوب والرموز',
    emoji_objects: 'الأشياء',
    emoji_food: 'الطعام والطبيعة',
    uploading: 'جارٍ الرفع…',
    upload_failed: 'تعذّر رفع الملف',
    failed: 'لم تُرسل — اضغط لإعادة المحاولة',
    unavailable: 'المحادثة غير متاحة الآن.',
    offline: 'نحن خارج أوقات العمل الآن، اترك رسالتك وسنردّ عليك.',
    choose: 'اختر',
    earlier: 'رسائل أقدم',
    file_too_large: 'الملف أكبر من المسموح.',
    file_too_large_named: 'الملف «{name}» أكبر من المسموح.',
    file_type_named: 'نوع الملف «{name}» غير مسموح.',
    too_many_files: 'يمكنك إرسال {n} ملفات كحدّ أقصى في المرة الواحدة.',
    powered_by: 'بواسطة',
    brand: 'منجز شات',
    file_type: 'هذا النوع من الملفات غير مسموح.',
    error: 'حدث خطأ، حاول مرة أخرى.',
    too_fast: 'تمهّل قليلاً ثم حاول مرة أخرى.',
    agent: 'فريق الدعم',
    bot: 'الردّ الآلي',
    you: 'أنت',
    new_message: 'رسالة جديدة',
}

type Dictionary = typeof ar

const en: Dictionary = {
    open: 'Open chat',
    close: 'Close',
    title: 'Chat with us',
    subtitle: 'We usually reply in a few minutes',
    prechat_intro: 'Tell us who you are to start the chat.',
    name: 'Name',
    phone: 'Mobile number',
    email: 'Email',
    optional: 'optional',
    start: 'Start chat',
    starting: 'Starting…',
    placeholder: 'Type your message…',
    send: 'Send',
    attach: 'Attach a file',
    emoji: 'Emoji',
    recent: 'Recently used',
    emoji_smileys: 'Smileys',
    emoji_gestures: 'Gestures',
    emoji_hearts: 'Hearts and symbols',
    emoji_objects: 'Objects',
    emoji_food: 'Food and nature',
    uploading: 'Uploading…',
    upload_failed: 'The file could not be uploaded',
    failed: 'Not sent — tap to retry',
    unavailable: 'Chat is not available right now.',
    offline: 'We are outside working hours. Leave a message and we will get back to you.',
    choose: 'Choose',
    earlier: 'Earlier messages',
    file_too_large: 'The file is too large.',
    file_too_large_named: '"{name}" is too large.',
    file_type_named: 'The type of "{name}" is not allowed.',
    too_many_files: 'You can send up to {n} files at a time.',
    powered_by: 'Powered by',
    brand: 'Mnjz Chat',
    file_type: 'This file type is not allowed.',
    error: 'Something went wrong, please try again.',
    too_fast: 'Please slow down and try again.',
    agent: 'Support',
    bot: 'Automated reply',
    you: 'You',
    new_message: 'New message',
}

export type TextKey = keyof Dictionary

export function resolveLanguage(setting: string, documentLang: string, browserLang: string): 'ar' | 'en' {
    if (setting === 'ar' || setting === 'en') {
        return setting
    }

    const lang = (documentLang || browserLang || '').toLowerCase()

    return lang.startsWith('ar') ? 'ar' : 'en'
}

export function translator(language: 'ar' | 'en') {
    const dictionary = language === 'ar' ? ar : en

    return (key: TextKey): string => dictionary[key] ?? en[key] ?? key
}

export type Translate = ReturnType<typeof translator>
