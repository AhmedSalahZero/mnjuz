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

const MB = 1024 * 1024;

/** ملف كبير: slice تُرجع Blob حقيقياً — FormData لا تقبل غيره. */
const bigFile = (name, mb) => ({
    file: { name, size: mb * MB, type: 'application/pdf', slice: (a, b) => new Blob([new Uint8Array(b - a)]) },
    type: 'document',
});

/**
 * ناقل يحلّ فوراً — لازم للقطع المتتابعة.
 *
 * الناقل الأصلي في هذا الملف مُتحكَّم به عمداً: يحتفظ بـresolve ليُستدعى يدوياً.
 * وسلسلة القطع تنتظر كل قطعة، فتقف عند الأولى إلى الأبد.
 */
function autoTransport() {
    const calls = [];
    const post = (url, formData) => {
        calls.push({ url, formData });

        return Promise.resolve({ data: {} });
    };

    return { calls, post };
}

/**
 * انتظار سلسلة القطع حتى تهدأ فعلاً.
 *
 * مهلة ثابتة تُنتج اختباراً يمرّ أو يسقط بحسب سرعة الجهاز — نستطلع الطابور
 * حتى يفرغ بدل التخمين.
 */
const settle = async (q, timeoutMs = 5000) => {
    const until = Date.now() + timeoutMs;

    while (q.isBusy.value && Date.now() < until) {
        await new Promise((resolve) => setTimeout(resolve, 10));
    }
};

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

/**
 * الطابور متتابع لا متوازٍ.
 *
 * التوازي لا يُسرِّع شيئاً — الملفات تتقاسم نفس عرض النطاق — ويُضيّع الترتيب.
 * والأهمّ أنه يجعل «الإلغاء قبل الرفع» مستحيلاً: ما بدأ لا يُلغى إلّا بقطع
 * اتصال قائم، بينما المنتظِر يُحذف فوراً بلا لمس الشبكة.
 */
{
    const { t, q } = queueWith();
    q.enqueue({ contactUuid: 'uuid-A', contactName: 'أحمد', files: [file('a.pdf', 10)], tempIds: ['a'] });
    q.enqueue({ contactUuid: 'uuid-B', contactName: 'سارة', files: [file('b.pdf', 10)], tempIds: ['b'] });

    is(t.calls.length, 1, 'انطلق أكثر من رفع معاً');
    is(q.jobs.value.map((j) => j.state).join(','), 'uploading,pending', 'الثاني لم ينتظر دوره');
    is(q.uploading.value.map((j) => j.contactUuid).join(','), 'uuid-A,uuid-B',
       'المنتظِر لا يظهر في المؤشّر — سيظنّ الموظّف أنه ضاع');

    // انتهاء الأوّل يُشغّل الثاني.
    t.calls[0].resolve({});
    await tick();

    is(t.calls.length, 2, 'الثاني لم ينطلق بعد انتهاء الأوّل');
    is(t.last().formData.get('uuid'), 'uuid-B', 'انطلق الخطأ');
}

/** والمنتظِر يُلغى بلا أن يُرفع منه بايت — وهذا كل الغرض. */
{
    const { t, q } = queueWith();
    let removed = null;
    q.enqueue({ contactUuid: 'c', files: [file('a.pdf', 10)], tempIds: ['a'] });
    const second = q.enqueue({
        contactUuid: 'c', files: [file('b.pdf', 10)], tempIds: ['b'],
        onFailure: (ids) => { removed = ids; },
    });

    is(t.calls.length, 1, 'الثاني بدأ قبل دوره');

    q.cancel(second);

    is(t.calls.length, 1, 'الإلغاء لمس الشبكة');
    is(q.jobs.value.length, 1, 'المنتظِر لم يُحذف');
    is(removed?.join(','), 'b', 'فقاعة المنتظِر بقيت');
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

// ------------------------------------------- المؤشّر خاصّ بمحادثته

/**
 * الرفع يكمل في الخلفية أيّاً كانت المحادثة المعروضة، لكن **عرضه** خاصّ
 * بمحادثته: من انتقل إلى عميل آخر لا يعنيه رفعٌ يخصّ عميلاً سابقاً، وعرضه
 * هناك ضجيج. ويعود المؤشّر كما هو حين يرجع صاحبه.
 */
{
    const { t, q } = queueWith();
    q.enqueue({ contactUuid: 'A', contactName: 'أحمد', files: [file('a.pdf', 100)], tempIds: ['a'] });
    q.enqueue({ contactUuid: 'B', contactName: 'سارة', files: [file('b.pdf', 200), file('c.pdf', 200)], tempIds: ['b1', 'b2'] });

    is(q.jobsFor('A').length, 1, 'محادثة A ترى مهمّتها وحدها');
    is(q.jobsFor('B').length, 1, 'ومحادثة B كذلك');
    is(q.jobsFor('C').length, 0, 'ومحادثة بلا رفع لا ترى شيئاً — المؤشّر يختفي');
    is(q.jobsFor(undefined).length, 0, 'وبلا محادثة معروضة: لا شيء');

    is(q.fileCountFor('A'), 1, 'عدد ملفات A');
    is(q.fileCountFor('B'), 2, 'عدد ملفات B');
    is(q.fileCountFor('C'), 0, 'لا ملفات في C');

    // التقدّم لكلٍّ على حدة، لا مجموع الكل.
    t.calls[0].config.onUploadProgress({ loaded: 50, total: 100 });
    is(q.percentFor('A'), 50, 'نصف A');
    is(q.percentFor('B'), 0, 'وB لم تبدأ');
    is(q.percentFor('C'), 0, 'ومحادثة بلا رفع: صفر لا انهيار');

    // والرفع نفسه لم يتوقّف: المهمّتان ما زالتا جاريتين.
    is(q.isBusy.value, true, 'الرفع يكمل في الخلفية مهما كانت المحادثة المعروضة');
    is(q.jobs.value.length, 2, 'الطابور يحتفظ بالمهمّتين');
}

/** والعودة إلى المحادثة تُرجع المؤشّر كما تركه صاحبه. */
{
    const { t, q } = queueWith();
    q.enqueue({ contactUuid: 'A', files: [file('a.pdf', 100)], tempIds: ['a'] });
    t.last().config.onUploadProgress({ loaded: 70, total: 100 });

    is(q.jobsFor('B').length, 0, 'غاب عند الانتقال');
    is(q.percentFor('A'), 70, 'وعاد بنسبته عند الرجوع');
}

// ------------------------------- الدفعة الكبيرة تُجزَّأ لا تُحزَم

/**
 * العطل الذي وقع على الإنتاج بعد تفعيل التجزئة: ملفٌ واحد مرّ على أربع قطع
 * ونجح، ثم ملفان معاً عادا إلى طلبٍ واحد بـ٧٦٫٥ ميغابايت فمات عند ١٢٥ ثانية —
 * نفس الرقم الذي كان يفشل قبل التجزئة.
 *
 * السبب أن التجزئة كانت مشروطة بأن يكون الملف واحداً. ومهلة الوكيل على
 * **الطلب** لا على الملف، فالقياس يجب أن يكون على الحمولة كلّها.
 */
{
    const t = autoTransport();
    const q = createUploadQueue({ post: t.post });
    q.enqueue({
        contactUuid: 'c',
        files: [bigFile('a.pdf', 30), bigFile('b.pdf', 46)],
        tempIds: ['t1', 't2'],
    });

    await settle(q);

    is(t.calls.filter((c) => c.url === '/chats').length, 0,
       'دفعة من ملفين عادت إلى طلبٍ واحد كامل — سيقتلها الوكيل كما كان');

    const chunked = t.calls.filter((c) => c.url === '/chats/upload/chunk');
    is(chunked.length > 0, true, 'لم تُجزَّأ');

    // مجلّد مستقلّ لكل ملف: المشترك يخلط القطع ويُتلف الملفين معاً.
    const folders = [...new Set(chunked.map((c) => c.formData.get('upload_id')))];
    is(folders.length, 2, 'الملفان يتقاسمان مجلّد قطع واحداً');
}

/**
 * إخفاقٌ في منتصف الدفعة لا يمحو ما وصل.
 *
 * الملفات تُرفع واحداً بعد آخر، وكلٌّ يصير رسالة عند اكتماله. فلو أخفق
 * الثاني، إزالةُ فقاعة الأوّل تُخفي رسالةً وصلت العميل فعلاً — يظنّ الموظّف
 * أنه لم يُرسل شيئاً فيُعيد، فتصل مكرَّرة.
 */
{
    const calls = [];
    let failFrom = null;
    const post = (url, formData) => {
        calls.push({ url, formData });
        const id = formData.get?.('upload_id') ?? '';

        return failFrom && id.endsWith(failFrom)
            ? Promise.reject(new Error('انقطع الاتصال'))
            : Promise.resolve({ data: {} });
    };

    failFrom = '-1';   // الملف الثاني
    const q = createUploadQueue({ post });
    let removed = null;

    q.enqueue({
        contactUuid: 'c',
        files: [bigFile('a.pdf', 6), bigFile('b.pdf', 6)],
        tempIds: ['t1', 't2'],
        onFailure: (ids) => { removed = ids; },
    });

    await settle(q);

    // is في هذا الملف مقارنةٌ صارمة، والمصفوفات لا تتساوى بالهوية.
    is(removed?.join(','), 't2', 'أُزيلت فقاعة ملفٍ وصل العميل — سيُعيد الموظّف إرساله');
}

/** والدفعة الصغيرة تبقى في طلب واحد — التجزئة بلا فائدة حين يكفي طلب قصير. */
{
    const t = autoTransport();
    const q = createUploadQueue({ post: t.post });
    q.enqueue({ contactUuid: 'c', files: [file('a.pdf', 10), file('b.pdf', 20)], tempIds: ['t1', 't2'] });

    is(t.calls.length, 1, 'دفعة صغيرة جُزّئت بلا داعٍ');
    is(t.calls[0].url, '/chats', 'مسار خاطئ للدفعة الصغيرة');
}

// ------------------------------- النسبة الإجمالية لا ترتدّ

/**
 * كانت المهمّة المكتملة تُحذف فوراً، فتخرج بايتاتها من الحساب: يصغر المقام
 * ويعود البسط للصفر — فترتدّ النسبة إلى الصفر مع نهاية **كل** ملف. يرى
 * الموظّف تقدّماً يُمحى ثلاث مرّات فيظنّ الرفع يُعاد من أوّله.
 *
 * المكتملة تبقى محسوبة حتى تفرغ الجولة: هي ما أُنجز فعلاً.
 */
{
    const { t, q } = queueWith();
    const sizes = [46, 27, 19];

    sizes.forEach((size, i) =>
        q.enqueue({ contactUuid: 'c', files: [file(`f${i}.pdf`, size)], tempIds: ['t' + i] }));

    const seen = [q.percent.value];

    for (const [i, size] of sizes.entries()) {
        const call = t.calls[i];
        call.config.onUploadProgress({ loaded: size / 2, total: size });
        seen.push(q.percent.value);
        call.config.onUploadProgress({ loaded: size, total: size });
        call.resolve({});
        await tick();
        // آخر ملف يُفرغ الطابور فتختفي النسبة مع المؤشّر — لا تُحسب ارتداداً.
        if (i < sizes.length - 1) seen.push(q.percent.value);
    }

    checks++;
    for (let i = 1; i < seen.length; i++) {
        if (seen[i] < seen[i - 1]) {
            fail.push(`النسبة ارتدّت: ${seen.join(' → ')}`);
            break;
        }
    }

    is(seen[0], 0, 'البداية ليست صفراً');
    is(seen[2], 50, 'انتهاء أوّل ملف من ثلاثة (٤٦ من ٩٢) ليس ٥٠٪');
    is(q.jobs.value.length, 0, 'الطابور لم يُطوَ بعد انتهاء الجولة');
}

/**
 * والمخفق يخرج من الحساب: بقاؤه فيه يُجمّد النسبة عند سقفٍ لا تتجاوزه أبداً،
 * فيظنّ الموظّف أن الرفع متوقّف وهو ماضٍ.
 */
{
    const { t, q } = queueWith();
    q.enqueue({ contactUuid: 'c', files: [file('a.pdf', 50)], tempIds: ['a'] });
    q.enqueue({ contactUuid: 'c', files: [file('b.pdf', 50)], tempIds: ['b'] });

    t.calls[0].reject(new Error('فشل'));
    await tick();

    // الثاني وحده هو الجولة الآن: نصفه = ٥٠٪ لا ٢٥٪.
    t.calls[1].config.onUploadProgress({ loaded: 25, total: 50 });

    is(q.percent.value, 50, 'المخفق ما زال محسوباً في المقام');
}

// -------------------------------------------------------- النتيجة

if (fail.length) {
    console.error('❌ ' + fail.length + ' إخفاق:');
    fail.forEach((f) => console.error('   - ' + f));
    process.exit(1);
}
console.log('✅ ' + checks + ' حالة — الرفع في الخلفية والموظّف حرّ');
