/**
 * طابور رفع المرفقات في الخلفية.
 *
 * قبله: sendAttachments تنتظر بـawait والنافذة مفتوحة فوق الشاشة، فالموظّف
 * محبوس حتى يرتفع آخر بايت — لا يفتح محادثة أخرى ولا يردّ على عميل ينتظر.
 * والحالة داخل المكوّن تموت بموته، فأي انتقال يُلغي الرفع الجاري.
 *
 * ما يحرسه هذا الملف: أن الرفع يبدأ ولا يُنتظر، وأن التقدّم يُقاس بالبايتات
 * لا بعدد المهامّ، وأن الإخفاق يُزيل الفقاعات التفاؤلية دائماً — إبقاؤها
 * يوهم الموظّف أن الملف وصل العميل وهو لم يصل.
 */
import { isReactive } from 'vue';
import { createUploadQueue, jobPercent } from '../../resources/js/Composables/uploadQueue.js';

let checks = 0;
const fail = [];
const is = (actual, expected, msg) => {
    checks++;
    if (actual !== expected) fail.push(`${msg} — توقّعنا ${JSON.stringify(expected)} ووجدنا ${JSON.stringify(actual)}`);
};
const ok = (cond, msg) => is(!!cond, true, msg);

const tick = () => new Promise((resolve) => setImmediate(resolve));

/** ناقل مُتحكَّم به: يكشف الوعد ودالّة التقدّم لكل طلب. */
function transport() {
    const calls = [];
    const post = (url, formData, config) => new Promise((resolve, reject) => {
        calls.push({ url, formData, config, resolve, reject });
    });

    return { calls, post, last: () => calls[calls.length - 1] };
}

const file = (name, size) => ({ file: { name, size, type: 'application/pdf' }, type: 'document' });

function queueWith(t = transport()) {
    return { t, q: createUploadQueue({ post: t.post }) };
}

// ------------------------------------------------- لا انتظار

{
    const { t, q } = queueWith();
    const id = q.enqueue({
        contactUuid: 'uuid-1', contactName: 'أحمد',
        files: [file('a.pdf', 100), file('b.pdf', 300)],
        tempIds: ['t1', 't2'],
    });

    ok(typeof id === 'number', 'enqueue يعود بمعرّف فوراً');
    is(t.calls.length, 1, 'الطلب انطلق بلا انتظار');
    is(q.isBusy.value, true, 'المؤشّر يعرف أن هناك رفعاً جارياً');
    is(q.fileCount.value, 2, 'عدد الملفات لا عدد المهامّ');
    is(q.uploading.value[0].contactName, 'أحمد', 'المهمّة تحمل اسم المحادثة');
}

// ------------------------------------------ الحمولة كما ينتظرها الخادم

{
    const { t, q } = queueWith();
    q.enqueue({
        contactUuid: 'uuid-1', files: [file('a.pdf', 10), file('b.pdf', 20)],
        caption: 'تفضّل', tempIds: ['t1', 't2'],
    });

    const sent = t.last().formData;
    is(sent.get('uuid'), 'uuid-1', 'معرّف المحادثة');
    is(sent.get('message'), 'تفضّل', 'التعليق يُرسَل مرّة واحدة');
    is(sent.getAll('files[]').length, 2, 'الملفّان في طلب واحد');
    is(sent.getAll('tempMessageIds[]').join(','), 't1,t2', 'المعرّفات المؤقّتة بالترتيب');
    is(sent.getAll('types[]').join(','), 'document,document', 'الأنواع');
}

{
    const { t, q } = queueWith();
    q.enqueue({ contactUuid: 'u', files: [file('a.pdf', 10)], tempIds: ['t1'] });
    is(t.last().formData.has('message'), false, 'بلا تعليق: لا مفتاح فارغ');
}

// ------------------------------------------------------- التقدّم

{
    const { t, q } = queueWith();
    q.enqueue({ contactUuid: 'u', files: [file('a.pdf', 1000)], tempIds: ['t1'] });

    is(q.percent.value, 0, 'قبل أي تقرير: صفر');
    t.last().config.onUploadProgress({ loaded: 250, total: 1000 });
    is(q.percent.value, 25, 'ربع الطريق');
    t.last().config.onUploadProgress({ loaded: 1000, total: 1000 });
    is(q.percent.value, 100, 'اكتمل الرفع');
}

/** النسبة موزونة بالبايتات: مهمّة صغيرة لا تقفز بالمؤشّر. */
{
    const { t, q } = queueWith();
    q.enqueue({ contactUuid: 'u', files: [file('small.pdf', 100)], tempIds: ['a'] });
    q.enqueue({ contactUuid: 'u', files: [file('big.pdf', 900)], tempIds: ['b'] });

    t.calls[0].config.onUploadProgress({ loaded: 100, total: 100 });
    is(q.percent.value, 10, 'الصغيرة اكتملت والكبيرة في أوّلها ⇒ ١٠٪ لا ٥٠٪');
}

is(jobPercent({ loaded: 50, total: 200 }), 25, 'نسبة مهمّة واحدة');
is(jobPercent({ loaded: 5, total: 0 }), 0, 'بلا حجم معروف: صفر لا قسمة على صفر');
is(jobPercent(null), 0, 'مهمّة معدومة');

// ---------------------------------------------- محادثات متوازية

{
    const { t, q } = queueWith();
    q.enqueue({ contactUuid: 'uuid-A', contactName: 'أحمد', files: [file('a.pdf', 10)], tempIds: ['a'] });
    q.enqueue({ contactUuid: 'uuid-B', contactName: 'سارة', files: [file('b.pdf', 10)], tempIds: ['b'] });

    is(t.calls.length, 2, 'رفعان متوازيان لمحادثتين');
    is(q.uploading.value.map((j) => j.contactUuid).join(','), 'uuid-A,uuid-B', 'كلٌّ يحمل محادثته');
}

// -------------------------------------------------------- النجاح

{
    const { t, q } = queueWith();
    let removed = null;
    q.enqueue({ contactUuid: 'u', files: [file('a.pdf', 10)], tempIds: ['t1'], onFailure: (ids) => { removed = ids; } });

    t.last().resolve({ data: {} });
    await tick();

    is(q.jobs.value.length, 0, 'النجاح يُزيل المهمّة — لا أرشيف يحتاج تنظيفاً');
    is(q.isBusy.value, false, 'المؤشّر يختفي');
    is(removed, null, 'النجاح لا يمسّ الفقاعات التفاؤلية');
}

// -------------------------------------------------------- الفشل

{
    const { t, q } = queueWith();
    let removed = null;
    q.enqueue({ contactUuid: 'u', files: [file('a.pdf', 10)], tempIds: ['t1', 't2'], onFailure: (ids) => { removed = ids; } });

    t.last().reject({ response: { data: { message: 'الملف كبير جداً' } } });
    await tick();

    is(q.jobs.value.length, 1, 'المهمّة تبقى ظاهرة ليعرف الموظّف أنها أخفقت');
    is(q.failed.value[0].state, 'failed', 'الحالة: أخفقت');
    is(q.failed.value[0].error, 'الملف كبير جداً', 'الرسالة من الخادم لا رسالة عامّة');
    is(q.isBusy.value, false, 'لم يعد هناك رفع جارٍ');
    is(removed?.join(','), 't1,t2', 'الفقاعات التفاؤلية تُزال — لم يصل شيء');
}

{
    const { t, q } = queueWith();
    q.enqueue({ contactUuid: 'u', files: [file('a.pdf', 10)], tempIds: ['t1'] });
    t.last().reject(new Error('انقطع الاتصال'));
    await tick();
    is(q.failed.value[0].error, 'انقطع الاتصال', 'خطأ شبكة بلا ردّ خادم');
}

// ------------------------------------------------ إعادة المحاولة

{
    const { t, q } = queueWith();
    const removedBatches = [];
    const id = q.enqueue({
        contactUuid: 'u', files: [file('a.pdf', 10)], tempIds: ['t1'],
        onFailure: (ids) => removedBatches.push(ids.join(',')),
    });

    t.last().reject(new Error('فشل'));
    await tick();

    is(q.retry(id, ['t9']), true, 'إعادة المحاولة تنطلق');
    is(t.calls.length, 2, 'طلب جديد');
    is(q.uploading.value[0].state, 'uploading', 'عادت إلى الرفع');
    is(t.last().formData.getAll('tempMessageIds[]').join(','), 't9', 'بمعرّفات جديدة — القديمة أُزيلت');

    t.last().resolve({});
    await tick();
    is(q.jobs.value.length, 0, 'ونجحت');
}

{
    const { t, q } = queueWith();
    const id = q.enqueue({ contactUuid: 'u', files: [file('a.pdf', 10)], tempIds: ['t1'] });
    is(q.retry(id), false, 'لا إعادة محاولة لمهمّة ما زالت ترفع');
    is(t.calls.length, 1, 'ولا طلب مكرّر');
}

// ------------------------------------------------------- الإلغاء

{
    const { t, q } = queueWith();
    let removed = null;
    const id = q.enqueue({
        contactUuid: 'u', files: [file('a.pdf', 10)], tempIds: ['t1'],
        onFailure: (ids) => { removed = ids; },
    });

    q.cancel(id);
    t.last().reject({ name: 'CanceledError' });
    await tick();

    is(q.jobs.value.length, 0, 'الإلغاء يُزيل المهمّة');
    is(removed?.join(','), 't1', 'ويُزيل الفقاعات التفاؤلية معها');
}

// ------------------------------------------------------- التنظيف

{
    const { t, q } = queueWith();
    const id = q.enqueue({ contactUuid: 'u', files: [file('a.pdf', 10)], tempIds: ['t1'] });

    is(q.dismiss(id), false, 'لا يُخفى ما زال يرفع');

    t.last().reject(new Error('فشل'));
    await tick();

    is(q.dismiss(id), true, 'والمخفق يُخفى بإرادة الموظّف');
    is(q.jobs.value.length, 0, 'اختفى');
}

// ------------------------------------- الملفات لا تُلَفّ بوكيل تفاعلي

/**
 * FormData لا تقبل وكيل Proxy: ترسل "[object Object]" مكان الملف. وحفظ الطلب
 * على مهمّة تفاعلية يفعل ذلك بالضبط — ولا يظهر إلا عند إعادة المحاولة، وهي
 * أسوأ لحظة لعطلٍ جديد.
 */
{
    const { t, q } = queueWith();
    const original = file('a.pdf', 10);
    const id = q.enqueue({ contactUuid: 'u', files: [original], tempIds: ['t1'] });

    // الهويّة هي الفحص: وكيل Proxy لا يساوي أصله بـ===.
    is(q.requestFor(id).files[0].file === original.file, true, 'الملف المحفوظ هو الأصل لا نسخة ملفوفة');
    is(isReactive(q.requestFor(id)), false, 'الطلب خارج التفاعلية');
    is(isReactive(q.requestFor(id).files[0].file), false, 'والملف كذلك');

    t.last().reject(new Error('فشل'));
    await tick();

    q.retry(id, ['t9']);
    is(q.requestFor(id).files[0].file === original.file, true, 'وبعد إعادة المحاولة يبقى الأصل');
}

/** والمهمّة نفسها تفاعلية — المؤشّر يقرأ تقدّمها. */
{
    const { q } = queueWith();
    q.enqueue({ contactUuid: 'u', files: [file('a.pdf', 10)], tempIds: ['t1'] });
    is(isReactive(q.uploading.value[0]), true, 'المهمّة تفاعلية ليتحدّث المؤشّر');
}

{
    const { q } = queueWith();
    is(q.requestFor(99999), null, 'طلب مهمّة غير موجودة');
}

// -------------------------------------------------------- النتيجة

if (fail.length) {
    console.error('❌ ' + fail.length + ' إخفاق:');
    fail.forEach((f) => console.error('   - ' + f));
    process.exit(1);
}
console.log('✅ ' + checks + ' حالة — الرفع في الخلفية والموظّف حرّ');
