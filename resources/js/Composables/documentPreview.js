/**
 * تسمية المستند في معاينة قائمة المحادثات.
 *
 * كانت تُقرأ هكذا: getExtension(last_chat.metadata?.type) — وفيها ثلاثة أخطاء
 * متراكبة، حصيلتها «Unknown ملف» لكل مستند لا للـPDF وحده:
 *
 *   ١. metadata نصّ JSON لا كائن، فـ.type عليه undefined. بقيّة الملف تُحلّله
 *      بـcontent() أولاً — هذا السطر وحده نسي.
 *   ٢. ولو حُلّل، فـtype هو نوع الرسالة ("document") لا نوع الملف، وخريطة
 *      الصيغ مفاتيحها MIME.
 *   ٣. ومورد القائمة كان يُسقط تفاصيل المستند كلّها ويُبقي type فقط.
 *
 * والوارد والصادر يحفظان حقلين مختلفين: الوارد يحتفظ بـfilename ويُسقط
 * mime_type (ChatMetadataHelper)، والصادر يحفظ mime_type بلا filename
 * (formatMediaResponse). فالقراءة تجرّب الاثنين.
 */

const MIME_EXTENSIONS = {
    'text/plain': 'TXT',
    'application/pdf': 'PDF',
    'application/vnd.ms-powerpoint': 'PPT',
    'application/msword': 'DOC',
    'application/vnd.ms-excel': 'XLS',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document': 'DOCX',
    'application/vnd.openxmlformats-officedocument.presentationml.presentation': 'PPTX',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet': 'XLSX',
};

/** امتدادات نعرضها كما هي حين يغيب mime_type. */
const KNOWN_EXTENSIONS = ['pdf', 'txt', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'csv', 'zip', 'rtf'];

export function extensionFromMime(mimeType) {
    return MIME_EXTENSIONS[String(mimeType ?? '').trim().toLowerCase()] ?? '';
}

export function extensionFromFilename(filename) {
    const name = String(filename ?? '').trim();
    const dot = name.lastIndexOf('.');

    if (dot <= 0 || dot === name.length - 1) {
        return '';
    }

    const extension = name.slice(dot + 1).toLowerCase();

    return KNOWN_EXTENSIONS.includes(extension) ? extension.toUpperCase() : '';
}

/**
 * صيغة المستند من حمولة المحادثة، أو '' حين تتعذّر معرفتها.
 *
 * @param {object} parsedMetadata حمولة مُحلّلة (لا نصّ JSON)
 */
export function documentExtension(parsedMetadata) {
    const document = parsedMetadata?.document ?? {};

    return extensionFromMime(document.mime_type) || extensionFromFilename(document.filename);
}

/**
 * السطر المعروض: «PDF» حين تُعرف الصيغة، وفارغ حين لا تُعرف — لتبقى الكلمة
 * «ملف» وحدها. «Unknown» ليست معلومة، وعرضها يوهم المستخدم بخطأ في الملف.
 *
 * @returns {string}
 */
export function documentPreviewLabel(parsedMetadata) {
    return documentExtension(parsedMetadata);
}
