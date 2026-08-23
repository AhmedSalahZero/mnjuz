/**
 * حرّاس على أسلاك الرفع غير الحاجب.
 *
 * منطق الطابور مُختبَر في upload-queue.mjs. ما يُختبَر هنا هو الوصل: أن
 * ChatForm لم يعد ينتظر الرفع، وأن النافذة تُغلق فوراً، وأن المؤشّر معروض
 * في صيغتَي صندوق الرسائل معاً — نسيان إحداهما يجعل نصف المستخدمين بلا
 * مؤشّر، ولا يظهر ذلك في أي اختبار منطق.
 */
import fs from 'fs';

let checks = 0;
const fail = [];
const ok = (cond, msg) => { checks++; if (!cond) fail.push(msg); };

const strip = (t) => t.replace(/\/\*[\s\S]*?\*\//g, '')
    .split('\n').filter((l) => !l.trim().startsWith('//')).join('\n');

const form = strip(fs.readFileSync('resources/js/Components/ChatComponents/ChatForm.vue', 'utf8'));
const indicator = strip(fs.readFileSync('resources/js/Components/ChatComponents/UploadProgressIndicator.vue', 'utf8'));
const queue = strip(fs.readFileSync('resources/js/Composables/uploadQueue.js', 'utf8'));

// ------------------------------------------------- لا انتظار

const sendAttachments = form.slice(
    form.indexOf('const sendAttachments'),
    form.indexOf('const retryUpload')
);

ok(sendAttachments.length > 0, 'sendAttachments غائبة');

ok(!/await\s+axios/.test(sendAttachments),
   'sendAttachments ما زالت تنتظر الشبكة — النافذة ستبقى تحجب الشاشة');

ok(!/const sendAttachments = async/.test(form),
   'sendAttachments ما زالت async — الانتظار عاد');

ok(/uploads\.enqueue\(/.test(sendAttachments),
   'المرفقات لا تُسلَّم للطابور');

ok(/closeAttachmentPreview\(\)/.test(sendAttachments),
   'النافذة لا تُغلق بعد التسليم');

// حالة الانتظار القديمة يجب أن تختفي تماماً، لا أن تبقى معطّلة.
ok(!/sendingAttachments/.test(form),
   'حالة الانتظار القديمة ما زالت في المكوّن');

// -------------------------------------------- الفقاعات التفاؤلية

ok(/onFailure:/.test(sendAttachments),
   'الإخفاق لا يُبلَّغ — الفقاعات ستبقى موهمةً أن الملف وصل');

ok(/removeMessage/.test(sendAttachments),
   'لا إزالة للفقاعات عند الإخفاق');

// ------------------------------------------------ حياة الطابور

ok(/initUploadQueue/.test(form),
   'المكوّن لا يستعمل الطابور المشترك');

ok(/^let shared = null/m.test(queue),
   'الطابور ليس على مستوى الوحدة — سيموت مع المكوّن كما كان');

ok(/const requests = new Map\(\)/.test(queue),
   'الطلبات داخل التفاعلية — الملفات ستُلَفّ بوكيل وتفسد FormData');

// -------------------------------------------------- المؤشّر

const indicatorUses = (form.match(/<UploadProgressIndicator/g) || []).length;
ok(indicatorUses === 2,
   `المؤشّر معروض في ${indicatorUses} موضع لا 2 — إحدى صيغتَي صندوق الرسائل بلا مؤشّر`);

// المؤشّر خاصّ بمحادثته: قراءة الطابور كلّه تعرض رفع عميل آخر في شاشة هذا.
ok(/:percent="currentUploadPercent"/.test(form), 'المؤشّر لا يقرأ نسبة محادثته');
ok(/:jobs="currentUploadJobs"/.test(form), 'المؤشّر لا يقرأ مهامّ محادثته');
ok(!/:jobs="uploads\.jobs\.value"/.test(form), 'المؤشّر يعرض مهامّ كل المحادثات');
ok(/uploads\.jobsFor\(props\.contact\?\.uuid\)/.test(form), 'التقييد ليس بالمحادثة المعروضة');
ok(/@retry="retryUpload"/.test(form), 'لا إعادة محاولة موصولة');
ok(/@cancel=/.test(form) && /@dismiss=/.test(form), 'لا إلغاء أو إخفاء موصول');

ok(/@click="emit\('toggle'\)"/.test(indicator), 'المؤشّر لا يُفتح بالضغط');
ok(/v-if="expanded"/.test(indicator), 'لا لوحة تفاصيل');
ok(/role="progressbar"/.test(indicator), 'شريط التقدّم بلا دور وصول');

// إعادة المحاولة تقرأ الطلب من المخزن لا من المهمّة التفاعلية.
const retry = form.slice(form.indexOf('const retryUpload'), form.indexOf('const retryUpload') + 900);
ok(/uploads\.requestFor\(job\.id\)/.test(retry),
   'إعادة المحاولة تقرأ ملفات ملفوفة بوكيل');

if (fail.length) {
    console.error('❌ ' + fail.length + ' إخفاق:');
    fail.forEach((f) => console.error('   - ' + f));
    process.exit(1);
}
console.log('✅ ' + checks + ' فحصاً — الأسلاك سليمة والنافذة لا تحجب');
