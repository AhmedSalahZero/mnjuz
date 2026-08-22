/**
 * تسمية المستند في معاينة قائمة المحادثات.
 *
 * العطل: أُرسل PDF على الإنتاج فظهر تحت اسم العميل «Unknown ملف». ثلاثة
 * أسباب متراكبة — قراءة .type من نصّ JSON غير محلَّل، ثم أن type هو نوع
 * الرسالة لا نوع الملف، ثم أن مورد القائمة يُسقط تفاصيل المستند أصلاً.
 * فكل مستند كان يظهر «Unknown»، لا الـPDF وحده.
 */
import {
    documentExtension,
    documentPreviewLabel,
    extensionFromFilename,
    extensionFromMime,
} from '../../resources/js/Composables/documentPreview.js';

let checks = 0;
const fail = [];
const is = (actual, expected, msg) => {
    checks++;
    if (actual !== expected) fail.push(`${msg} — توقّعنا "${expected}" ووجدنا "${actual}"`);
};

// ------------------------------------------------ الصادر: mime_type

is(documentExtension({ type: 'document', document: { mime_type: 'application/pdf' } }), 'PDF',
   'PDF صادر');
is(documentExtension({ document: { mime_type: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' } }), 'DOCX',
   'DOCX صادر');
is(documentExtension({ document: { mime_type: 'APPLICATION/PDF' } }), 'PDF',
   'MIME بحروف كبيرة');
is(documentExtension({ document: { mime_type: ' application/pdf ' } }), 'PDF',
   'MIME بمسافات');

// ------------------------------------------------ الوارد: filename

is(documentExtension({ type: 'document', document: { filename: 'عرض السعر.pdf' } }), 'PDF',
   'PDF وارد باسم عربي');
is(documentExtension({ document: { filename: 'report.XLSX' } }), 'XLSX',
   'امتداد بحروف كبيرة');
is(documentExtension({ document: { filename: 'archive.v2.docx' } }), 'DOCX',
   'اسم فيه أكثر من نقطة');

// ------------------------------------------------------- الأولوية

is(documentExtension({ document: { mime_type: 'application/pdf', filename: 'x.docx' } }), 'PDF',
   'mime_type يسبق الاسم — أدقّ من الامتداد');

// ------------------------------------------------- «Unknown» لا تظهر

is(documentPreviewLabel({ type: 'document' }), '',
   'بلا تفاصيل: كلمة «ملف» وحدها لا «Unknown ملف»');
is(documentPreviewLabel({ type: 'document', document: {} }), '', 'كتلة مستند فارغة');
is(documentPreviewLabel({ type: 'document', document: { mime_type: null, filename: null } }), '',
   'حقول معدومة');
is(documentPreviewLabel({}), '', 'حمولة فارغة');
is(documentPreviewLabel(null), '', 'حمولة معدومة');
is(documentPreviewLabel(undefined), '', 'حمولة غير معرَّفة');

// العطل الأصلي بعينه: نوع الرسالة مُرِّر مكان نوع الملف.
is(extensionFromMime('document'), '', 'نوع الرسالة ليس MIME');
is(extensionFromMime(undefined), '', 'MIME غير معرَّف');

// ------------------------------------------------------ أسماء حدّية

is(extensionFromFilename('بلا امتداد'), '', 'اسم بلا نقطة');
is(extensionFromFilename('.gitignore'), '', 'نقطة بادئة ليست امتداداً');
is(extensionFromFilename('ينتهي بنقطة.'), '', 'نقطة أخيرة بلا امتداد');
is(extensionFromFilename('setup.exe'), '', 'امتداد غير معروف لا يُعرض خاماً');
is(extensionFromFilename(''), '', 'اسم فارغ');
is(extensionFromFilename(null), '', 'اسم معدوم');

if (fail.length) {
    console.error('❌ ' + fail.length + ' إخفاق:');
    fail.forEach(f => console.error('   - ' + f));
    process.exit(1);
}
console.log('✅ ' + checks + ' حالة — لا «Unknown» بعد اليوم');
