/**
 * الرفع المجزّأ.
 *
 * العطل: Cloudflare يقطع أي طلب تجاوز ١٢٥ ثانية — لا أي حجم بعينه. أُعيد
 * إنتاجه بالضبط: نفس الملف ونفس السرعة، عبر Cloudflare يُقطع عند 125.005
 * ثانية برمز 524، ومباشرةً إلى الخادم يكتمل في 184 ثانية بلا اعتراض.
 *
 * وبسرعة رفع ٠٫٣ ميغابايت/ثانية يعني ذلك أن أي ملف فوق ~٤٠ ميغابايت يفشل
 * مهما أُرسل وحده — فالتقسيم على الحجم لا يكفي، والقياس يجب أن يكون بالزمن.
 *
 * القطعة خمسة ميغابايت: على أبطأ شبكة معقولة تبقى دون عشرين ثانية.
 */
import { CHUNK_BYTES, chunkCount, planChunks, uploadInChunks } from '../../resources/js/Composables/chunkedUpload.js';

const MB = 1024 * 1024;

let checks = 0;
const fail = [];
const is = (actual, expected, msg) => {
    checks++;
    if (JSON.stringify(actual) !== JSON.stringify(expected)) {
        fail.push(`${msg} — توقّعنا ${JSON.stringify(expected)} ووجدنا ${JSON.stringify(actual)}`);
    }
};

// ------------------------------------------------- تقسيم الملف

is(CHUNK_BYTES, 5 * MB, 'حجم القطعة تغيّر');

/** ملف الـWSJ الحقيقي: ٧٦٫٥ ميغابايت. */
is(chunkCount(76.5 * MB), 16, 'عدد قطع ملف ٧٦٫٥ م.ب');

/** لا قطعة تتجاوز الحدّ — وهو ما يبقي كل طلب قصيراً. */
{
    for (const size of [1, 5, 5.1, 40, 76.5, 100]) {
        for (const c of planChunks(size * MB)) {
            checks++;
            if (c.end - c.start > CHUNK_BYTES) fail.push(`قطعة أكبر من الحدّ في ملف ${size} م.ب`);
        }
    }
}

/** والقطع تُغطّي الملف كلّه بلا فجوة ولا تداخل. */
{
    const chunks = planChunks(76.5 * MB);
    is(chunks[0].start, 0, 'أول قطعة لا تبدأ من الصفر');
    is(chunks[chunks.length - 1].end, 76.5 * MB, 'آخر قطعة لا تنتهي بنهاية الملف');

    for (let i = 1; i < chunks.length; i++) {
        checks++;
        if (chunks[i].start !== chunks[i - 1].end) fail.push(`فجوة أو تداخل عند القطعة ${i}`);
    }
}

/** الفهارس متتابعة من الصفر — الخادم يدمج بها. */
is(planChunks(12 * MB).map((c) => c.index), [0, 1, 2], 'الفهارس ليست متتابعة');

// ------------------------------------------------------- الحدود

is(planChunks(0), [], 'ملف فارغ');
is(planChunks(-5), [], 'حجم سالب');
is(planChunks(NaN), [], 'حجم غير رقمي');
is(planChunks(undefined), [], 'حجم غير معرَّف');
is(chunkCount(1), 1, 'بايت واحد ⇒ قطعة واحدة');
is(chunkCount(5 * MB), 1, 'ملف بحجم القطعة تماماً ⇒ قطعة واحدة');
is(chunkCount(5 * MB + 1), 2, 'بايت فوق القطعة ⇒ قطعتان');

// ------------------------------------------------------ الرفع

/** ملفٌ وهمي: slice يجب أن تُرجع Blob حقيقياً — FormData لا تقبل غيره. */
function fakeFile(size, name = 'big.pdf') {
    return { size, name, slice: (a, b) => new Blob([new Uint8Array(b - a)]) };
}

function transport() {
    const calls = [];
    const post = (url, body, config) => {
        calls.push({ url, body, config });
        // نُحاكي تقدّماً كاملاً للقطعة.
        const sent = body.get('chunk');
        config?.onUploadProgress?.({ loaded: sent?.size ?? 0, total: sent?.size ?? 0 });

        return Promise.resolve({ data: { completed: calls.length === Number(body.get('total')) } });
    };

    return { calls, post };
}

{
    const { calls, post } = transport();
    const file = fakeFile(12 * MB);
    const seen = [];

    await uploadInChunks({
        post,
        file,
        fields: { upload_id: 'abc', contact_uuid: 'c1', file_name: 'big.pdf', file_type: 'document' },
        onProgress: (loaded, total) => seen.push([loaded, total]),
    });

    is(calls.length, 3, 'عدد الطلبات ليس بعدد القطع');
    is(calls.every((c) => c.url === '/chats/upload/chunk'), true, 'مسار خاطئ');
    is(calls.map((c) => c.body.get('index')), ['0', '1', '2'], 'الفهارس تُرسَل بالترتيب');
    is(calls.every((c) => c.body.get('total') === '3'), true, 'العدد الكلّي غير مُرسَل');
    is(calls[0].body.get('upload_id'), 'abc', 'معرّف الرفع غير مُرسَل');
    is(calls[0].body.get('contact_uuid'), 'c1', 'المحادثة غير مُرسَلة');

    // التقدّم يُقاس على الملف كلّه لا على القطعة: نسبةٌ ترتدّ مع كل قطعة
    // تبدو للمستخدم اضطراباً بلا معنى.
    is(seen[seen.length - 1], [12 * MB, 12 * MB], 'التقدّم لا يبلغ نهاية الملف');
    checks++;
    if (seen.some(([loaded]) => loaded > 12 * MB)) fail.push('التقدّم تجاوز حجم الملف');
    checks++;
    for (let i = 1; i < seen.length; i++) {
        if (seen[i][0] < seen[i - 1][0]) { fail.push('التقدّم ارتدّ إلى الوراء'); break; }
    }
}

/** الحقول الفارغة لا تُرسَل — تعليقٌ فارغ ليس تعليقاً. */
{
    const { calls, post } = transport();
    await uploadInChunks({
        post,
        file: fakeFile(1 * MB),
        fields: { upload_id: 'a', contact_uuid: 'c', file_name: 'x.pdf', file_type: 'document', caption: '', temp_message_id: null },
    });

    is(calls[0].body.has('caption'), false, 'تعليق فارغ أُرسل');
    is(calls[0].body.has('temp_message_id'), false, 'معرّف معدوم أُرسل');
}

// ------------------------------------------------------ الإلغاء

/** الإلغاء يوقف الرفع فوراً ولا يُكمل القطع الباقية. */
{
    const { calls, post } = transport();
    const controller = new AbortController();
    controller.abort();

    let thrown = null;
    try {
        await uploadInChunks({
            post,
            file: fakeFile(20 * MB),
            fields: { upload_id: 'a', contact_uuid: 'c', file_name: 'x.pdf', file_type: 'document' },
            signal: controller.signal,
        });
    } catch (e) {
        thrown = e;
    }

    is(thrown?.name, 'AbortError', 'الإلغاء لا يرمي AbortError');
    is(calls.length, 0, 'أُرسلت قطع بعد الإلغاء');
}

/** وملف بلا محتوى يُرفض بوضوح بدل أن يُرسل صفر قطع ويبدو ناجحاً. */
{
    let thrown = null;
    try {
        await uploadInChunks({ post: transport().post, file: fakeFile(0), fields: {} });
    } catch (e) {
        thrown = e;
    }
    checks++;
    if (!thrown) fail.push('ملف فارغ مرّ بلا خطأ');
}

// ------------------------------------------------------ الأسلاك

import fs from 'fs';
const strip = (t) => t.replace(/\/\*[\s\S]*?\*\//g, '').split('\n').filter((l) => !l.trim().startsWith('//')).join('\n');
const queue = strip(fs.readFileSync('resources/js/Composables/uploadQueue.js', 'utf8'));
const ok = (cond, msg) => { checks++; if (!cond) fail.push(msg); };

ok(/uploadInChunks\(/.test(queue), 'الطابور لا يستعمل الرفع المجزّأ');
ok(/> CHUNK_BYTES/.test(queue), 'لا حدّ يقرّر متى يُجزَّأ');
ok(/uploadId: randomId\(\)/.test(queue), 'لا معرّف رفع ثابت — الخادم لن يجمع القطع');
ok(/request\.files\.length === 1/.test(queue), 'التجزئة تُطبَّق على دفعة لا على ملف مفرد');

if (fail.length) {
    console.error('❌ ' + fail.length + ' إخفاق:');
    fail.forEach((x) => console.error('   - ' + x));
    process.exit(1);
}
console.log('✅ ' + checks + ' حالة — لا طلب يقترب من المهلة');
