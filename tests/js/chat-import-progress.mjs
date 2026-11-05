/**
 * شريط تقدّم استيراد المحادثات.
 *
 * الخادم يُعطي نسبة المرحلة الحاليّة وحدها (فحص، كتابة، تراجع)، والرفع
 * يُقاس في المتصفّح. خطأ في الربط بينها يُظهر شريطاً يقفز إلى الوراء أو
 * يقول «اكتمل» لملفٍ مرفوض — فنثبّت كل حالة هنا.
 *
 * التشغيل: node tests/js/chat-import-progress.mjs
 */
import {
    clampPercent,
    formatBytes,
    isActive,
    looksLikeCsv,
    newUploadId,
    progressView,
    statusLabel,
    statusTone,
    stepState,
    transitionToast,
} from '../../resources/js/Composables/chatImportProgress.js';
import { uploadInChunks } from '../../resources/js/Composables/chunkedUpload.js';

let checks = 0;
const fail = [];
const is = (actual, expected, msg) => {
    checks++;
    if (JSON.stringify(actual) !== JSON.stringify(expected)) {
        fail.push(`${msg} — توقّعنا ${JSON.stringify(expected)} ووجدنا ${JSON.stringify(actual)}`);
    }
};

// ------------------------------------------------- المرحلة الحاليّة

is(progressView(), { step: null, percent: 0, status: null }, 'لا رفع ولا استيراد');
is(progressView({ upload: { loaded: 25, total: 100 } }), { step: 'upload', percent: 25, status: 'uploading' }, 'أثناء الرفع');
is(progressView({ upload: { loaded: 0, total: 0 } }).step, null, 'رفع بلا حجم لا يقسم على صفر');

const at = (status, percent = 40) => progressView({ importState: { status, percent } });

is(at('pending_validation', 0), { step: 'validate', percent: 0, status: 'pending_validation' }, 'بانتظار الفحص');
is(at('validating'), { step: 'validate', percent: 40, status: 'validating' }, 'أثناء الفحص');
is(at('queued', 0).step, 'import', 'بانتظار الكتابة');
is(at('importing', 73).percent, 73, 'نسبة الكتابة من الخادم');
is(at('cancelling').step, 'import', 'الإلغاء يبقى على مرحلة الكتابة حتى تتوقّف');
is(at('rolling_back', 12), { step: 'rollback', percent: 12, status: 'rolling_back' }, 'التراجع شريط مستقلّ');
is(at('completed', 100), { step: 'done', percent: 100, status: 'completed' }, 'اكتمل');
is(at('invalid', 100).step, 'validate', 'المرفوض يقف عند الفحص — لم يُكتب شيء');

// الاستيراد يغلب الرفع: حين يردّ الخادم بالاستيراد يكون الرفع قد انتهى.
is(progressView({ upload: { loaded: 10, total: 100 }, importState: { status: 'validating', percent: 5 } }).step, 'validate', 'الاستيراد يغلب الرفع');

// ------------------------------------------------- الخطوات

const steps = (status, started = true) => {
    const view = at(status);
    return ['upload', 'validate', 'import'].map((step) => stepState(step, view, started));
};

is(steps('validating'), ['done', 'current', 'pending'], 'خطوات أثناء الفحص');
is(steps('importing'), ['done', 'done', 'current'], 'خطوات أثناء الكتابة');
is(steps('completed'), ['done', 'done', 'done'], 'خطوات الاكتمال');
is(steps('invalid'), ['done', 'failed', 'pending'], 'المرفوض فشل عند الفحص');
is(steps('failed', true), ['done', 'done', 'failed'], 'فشل أثناء الكتابة');
is(steps('failed', false), ['done', 'failed', 'pending'], 'فشل قبل الكتابة = فشل فحص');
is(steps('cancelled', true), ['done', 'done', 'failed'], 'أُلغي أثناء الكتابة');
is(stepState('upload', progressView({ upload: { loaded: 1, total: 2 } })), 'current', 'الرفع هو الخطوة الحاليّة');

// ------------------------------------------------- الحالات

is(isActive('importing'), true, 'الكتابة نشطة');
is(isActive('rolling_back'), true, 'التراجع نشط');
is(isActive('completed'), false, 'الاكتمال ليس نشطاً');
is(isActive('invalid'), false, 'المرفوض ليس نشطاً');

is(statusTone('completed'), 'success', 'لون الاكتمال');
is(statusTone('rolled_back'), 'success', 'لون التراجع المكتمل');
is(statusTone('invalid'), 'error', 'لون الرفض');
is(statusTone('failed'), 'error', 'لون الفشل');
is(statusTone('cancelled'), 'warning', 'لون الإلغاء');
is(statusTone('importing'), 'info', 'لون الجاري');
is(statusLabel('invalid'), 'Rejected', 'تسمية الرفض');
is(statusLabel('unknown_state'), 'unknown_state', 'حالة غير معروفة تُعرض كما هي');

// ------------------------------------------------- التنبيهات

is(transitionToast(null, 'completed'), null, 'أوّل قراءة لا تُنبّه — الصفحة فُتحت على حالة قائمة');
is(transitionToast('importing', 'importing'), null, 'لا تغيير لا تنبيه');
is(transitionToast('validating', 'invalid').type, 'error', 'الرفض تنبيه خطأ');
is(transitionToast('validating', 'queued').type, 'success', 'اجتياز الفحص');
is(transitionToast('failed', 'queued'), null, 'الاستئناف لا يُعلن اجتياز الفحص من جديد');
is(transitionToast('importing', 'completed').type, 'success', 'الاكتمال');
is(transitionToast('importing', 'failed').type, 'error', 'الفشل');
is(transitionToast('rolling_back', 'rolled_back').type, 'success', 'التراجع');

// ------------------------------------------------- أدوات

is(clampPercent(140), 100, 'سقف النسبة');
is(clampPercent(-3), 0, 'أرضية النسبة');
is(clampPercent('x'), 0, 'نسبة غير رقمية');
is(clampPercent(99.9), 99, 'لا يُقرَّب إلى 100 قبل الاكتمال');
is(formatBytes(47564319), '45.4 MB', 'حجم ملف HBR');
is(formatBytes(512), '512 B', 'بايتات');
is(looksLikeCsv('history.CSV'), true, 'الامتداد بلا حساسية للحالة');
is(looksLikeCsv('history.xlsx'), false, 'Excel مرفوض');
is(newUploadId() !== newUploadId(), true, 'معرّف الرفع فريد');
is(/^[A-Za-z0-9-]+$/.test(newUploadId()), true, 'معرّف الرفع حروف وأرقام وشرطات — ما يقبله الخادم');

// ------------------------------------------------- الرفع على مسار الاستيراد

const urls = [];
const fakePost = async (url) => {
    urls.push(url);
    return { data: { completed: urls.length === 2 } };
};
const file = { size: 10, slice: () => new Blob(['x']) };

await uploadInChunks({ post: fakePost, file, fields: { upload_id: 'a' }, chunkBytes: 5, url: '/settings/chat-imports/upload/chunk' });
is(urls, ['/settings/chat-imports/upload/chunk', '/settings/chat-imports/upload/chunk'], 'القطع تذهب إلى مسار الاستيراد');

urls.length = 0;
await uploadInChunks({ post: fakePost, file, fields: {}, chunkBytes: 10 });
is(urls, ['/chats/upload/chunk'], 'المسار الافتراضي لمرفقات المحادثة لم يتغيّر');

if (fail.length) {
    console.error(`✗ ${fail.length} من ${checks} فحصاً فشلت:`);
    fail.forEach((line) => console.error('  - ' + line));
    process.exit(1);
}

console.log(`✓ ${checks} فحصاً — شريط تقدّم استيراد المحادثات`);
